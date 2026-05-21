<?php

namespace App\Nightwatch\Api;

use Illuminate\Support\Facades\DB;

final class NightwatchProjectPickerService
{
    public function index(): array
    {
        $projects = DB::table('nw_projects')
            ->select(['id', 'slug', 'name', 'is_active', 'tags'])
            ->orderBy('name')
            ->get();

        return [
            'projects' => $projects
                ->map(fn (object $project): array => [
                    'id' => (int) $project->id,
                    'slug' => (string) $project->slug,
                    'name' => (string) $project->name,
                    'is_active' => (bool) $project->is_active,
                    'tags' => $this->decodeTags($project->tags),
                ])
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function decodeTags(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $tag): string => trim((string) $tag), $decoded)));
    }
}
