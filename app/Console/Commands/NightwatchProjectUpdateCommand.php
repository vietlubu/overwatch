<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NightwatchProjectUpdateCommand extends Command
{
    protected $signature = 'nightwatch:project:update
        {project : Project slug or ID}
        {--name= : Updated display name}
        {--tags= : Comma-separated project tags}';

    protected $description = 'Update a Nightwatch project name and tags.';

    public function handle(NightwatchProjectKeyManager $manager): int
    {
        $projectIdentifier = (string) $this->argument('project');
        $project = $manager->findProject($projectIdentifier);

        if ($project === null) {
            $this->error("Nightwatch project [{$projectIdentifier}] was not found.");

            return self::FAILURE;
        }

        $name = $this->option('name');
        $tags = $this->option('tags') !== null
            ? explode(',', (string) $this->option('tags'))
            : null;

        $project = DB::transaction(fn () => $manager->updateProject(
            $project,
            $name !== null ? (string) $name : null,
            $tags,
        ));

        $this->info('Nightwatch project updated.');
        $this->table(
            ['ID', 'Slug', 'Name', 'Tags'],
            [[
                $project['id'],
                $project['slug'],
                $project['name'],
                implode(', ', $project['tags']),
            ]],
        );

        return self::SUCCESS;
    }
}
