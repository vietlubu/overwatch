<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class NightwatchKeyCreateCommand extends Command
{
    protected $signature = 'nightwatch:key:create
        {project : Project slug or ID}
        {--environment= : Target environment for this key}
        {--name=primary : Logical key name within the project/environment}';

    protected $description = 'Generate a Nightwatch ingest key for a project environment.';

    public function handle(NightwatchProjectKeyManager $manager): int
    {
        $projectIdentifier = (string) $this->argument('project');
        $environment = trim((string) ($this->option('environment') ?? ''));
        $keyName = trim((string) ($this->option('name') ?? 'primary'));

        if ($environment === '') {
            $this->error('The --environment option is required.');

            return self::FAILURE;
        }

        if ($keyName === '') {
            $this->error('The --name option cannot be empty.');

            return self::FAILURE;
        }

        $project = $manager->findProject($projectIdentifier);

        if ($project === null) {
            $this->error("Nightwatch project [{$projectIdentifier}] was not found.");

            return self::FAILURE;
        }

        if (! $project['is_active']) {
            $this->error("Nightwatch project [{$project['slug']}] is inactive.");

            return self::FAILURE;
        }

        try {
            $key = DB::transaction(fn () => $manager->createKey($project, $environment, $keyName));
        } catch (QueryException $e) {
            $this->error('Unable to create key. The key name may already exist for this project/environment.');

            return self::FAILURE;
        }

        $advertiseUri = sprintf(
            '%s:%d',
            config('overwatch.tcp.host', '127.0.0.1'),
            (int) config('overwatch.tcp.port', 2407),
        );

        $this->warn('Store this secret now. It will not be shown again.');
        $this->newLine();
        $this->table(
            ['Project', 'Environment', 'Key', 'Token Hash', 'Fingerprint'],
            [[
                $project['slug'],
                $key['environment'],
                $key['key_name'],
                $key['token_hash'],
                $key['secret_fingerprint'],
            ]],
        );
        $this->line('Secret: '.$key['secret']);
        $this->newLine();
        $this->line('Environment snippet:');
        $this->line('NIGHTWATCH_TOKEN='.$key['secret']);
        $this->line('NIGHTWATCH_INGEST_URI='.$advertiseUri);
        $this->line('NIGHTWATCH_DEPLOY=your-deploy-name');
        $this->line('NIGHTWATCH_SERVER=your-server-name');

        return self::SUCCESS;
    }
}
