<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithNightwatchSecrets;
use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NightwatchProjectRotateKeyCommand extends Command
{
    use InteractsWithNightwatchSecrets;

    protected $signature = 'nightwatch:project:rotate-key {project : Project slug or ID}';

    protected $description = 'Rotate the Nightwatch ingest key for a project.';

    public function handle(NightwatchProjectKeyManager $manager): int
    {
        $projectIdentifier = (string) $this->argument('project');
        $project = $manager->findProject($projectIdentifier);

        if ($project === null) {
            $this->error("Nightwatch project [{$projectIdentifier}] was not found.");

            return self::FAILURE;
        }

        if (! $project['is_active']) {
            $this->error("Nightwatch project [{$project['slug']}] is inactive.");

            return self::FAILURE;
        }

        $rotated = DB::transaction(fn () => $manager->rotateKey($project));

        $this->info('Nightwatch project key rotated.');
        $this->renderProjectSecret($rotated);

        return self::SUCCESS;
    }
}
