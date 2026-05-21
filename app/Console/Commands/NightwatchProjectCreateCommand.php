<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithNightwatchSecrets;
use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class NightwatchProjectCreateCommand extends Command
{
    use InteractsWithNightwatchSecrets;

    protected $signature = 'nightwatch:project:create {slug} {--name=} {--tags=}';

    protected $description = 'Create a Nightwatch project tenant.';

    public function handle(NightwatchProjectKeyManager $manager): int
    {
        $slug = (string) $this->argument('slug');
        $name = $this->option('name') ? (string) $this->option('name') : null;
        $tags = $this->option('tags')
            ? explode(',', (string) $this->option('tags'))
            : [];

        try {
            $project = DB::transaction(fn () => $manager->createProject($slug, $name, $tags));
        } catch (QueryException $e) {
            $this->error('Unable to create project. The slug may already exist.');

            return self::FAILURE;
        }

        $this->info('Nightwatch project created.');
        $this->table(
            ['ID', 'Slug', 'Name', 'Active', 'Tags'],
            [[
                $project['id'],
                $project['slug'],
                $project['name'],
                $project['is_active'] ? 'yes' : 'no',
                implode(', ', $project['tags']),
            ]],
        );
        $this->renderProjectSecret($project);

        return self::SUCCESS;
    }
}
