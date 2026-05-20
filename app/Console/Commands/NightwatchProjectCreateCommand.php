<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class NightwatchProjectCreateCommand extends Command
{
    protected $signature = 'nightwatch:project:create {slug} {--name=}';

    protected $description = 'Create a Nightwatch project tenant.';

    public function handle(NightwatchProjectKeyManager $manager): int
    {
        $slug = (string) $this->argument('slug');
        $name = $this->option('name') ? (string) $this->option('name') : null;

        try {
            $project = DB::transaction(fn () => $manager->createProject($slug, $name));
        } catch (QueryException $e) {
            $this->error('Unable to create project. The slug may already exist.');

            return self::FAILURE;
        }

        $this->info('Nightwatch project created.');
        $this->table(
            ['ID', 'Slug', 'Name', 'Active'],
            [[
                $project['id'],
                $project['slug'],
                $project['name'],
                $project['is_active'] ? 'yes' : 'no',
            ]],
        );

        return self::SUCCESS;
    }
}
