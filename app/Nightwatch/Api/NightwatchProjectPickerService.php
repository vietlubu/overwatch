<?php

namespace App\Nightwatch\Api;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NightwatchProjectPickerService
{
    public function index(): array
    {
        $projects = DB::table('nw_projects')
            ->select(['id', 'slug', 'name', 'is_active'])
            ->orderBy('name')
            ->get();

        $environments = DB::table('nw_ingest_tokens')
            ->select(['project_id', 'environment'])
            ->whereNotNull('environment')
            ->union(
                DB::table('nw_executions')
                    ->select(['project_id', 'environment'])
                    ->whereNotNull('environment'),
            )
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $rows): array => $rows
                ->pluck('environment')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all());

        return [
            'projects' => $projects
                ->map(fn (object $project): array => [
                    'id' => (int) $project->id,
                    'slug' => (string) $project->slug,
                    'name' => (string) $project->name,
                    'is_active' => (bool) $project->is_active,
                    'environments' => $environments->get($project->id, []),
                ])
                ->all(),
        ];
    }
}
