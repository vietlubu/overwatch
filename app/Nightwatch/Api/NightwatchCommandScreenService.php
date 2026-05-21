<?php

namespace App\Nightwatch\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_filter;
use function max;
use function min;
use function round;
use function sprintf;

final class NightwatchCommandScreenService
{
    public function __construct(
        private readonly NightwatchScreenPresenter $presenter,
    ) {
        //
    }

    public function index(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $rows = $this->baseQuery($filters, applyRange: true)->get();

        $groups = $rows
            ->groupBy(fn (object $row): string => implode('|', [
                $row->project_id,
                $row->group_hash ?? '',
            ]))
            ->map(fn (Collection $group): array => $this->mapListGroup($group))
            ->sortByDesc(fn (array $group): string => $group['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($groups, $page, $perPage);
        $durations = $rows->pluck('duration_us')->all();
        $successfulCount = $rows->filter(fn (object $row): bool => (int) $row->exit_code === 0)->count();
        $unsuccessfulCount = $rows->count() - $successfulCount;

        return [
            'kind' => 'collection',
            'eyebrow' => 'Console activity',
            'title' => 'Commands',
            'subtitle' => 'Command execution summaries backed by nw_command_1m and command detail rows from nw_command_details.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'COMMANDS',
                    'caption' => sprintf('%d executions', $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('unsuccessful '.$unsuccessfulCount, 'red'),
                        $this->presenter->meta('successful '.$successfulCount, 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $successfulCount, 'tone' => 'green'],
                        ['value' => (float) $unsuccessfulCount, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $rows->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $rows->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) round($rows->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('AVG', 'blue'),
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars($durations === [] ? [0] : $durations, 'yellow'),
                ],
            ],
            'table' => [
                'title' => sprintf('%d Commands', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep artisan name…',
                'columns' => [
                    ['key' => 'command', 'label' => 'Command', 'kind' => 'primary'],
                    ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                    ['key' => 'p95', 'label' => 'P95', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_executions')
            ->where('source', 'command')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->select(['project_id'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch command group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Command group hash [{$groupHash}] exists in multiple projects. Pass project_id.",
            );
        }

        $runs = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
        ], applyRange: false)
            ->where('executions.group_hash', $groupHash)
            ->orderByDesc('executions.occurred_at')
            ->get();

        $latest = $runs->first();
        $durations = $runs->pluck('duration_us')->all();
        $successfulCount = $runs->filter(fn (object $row): bool => (int) $row->exit_code === 0)->count();
        $unsuccessfulCount = $runs->count() - $successfulCount;

        return [
            'eyebrow' => 'Command detail',
            'title' => (string) $latest->name,
            'subtitle' => 'Terminal-mode detail page for a single command execution group.',
            'backTo' => $this->presenter->backTo('commands'),
            'tags' => [
                [
                    'text' => $this->commandStatusLabel((int) $latest->exit_code),
                    'tone' => $this->commandStatusTone((int) $latest->exit_code),
                ],
                [
                    'text' => 'artisan',
                    'tone' => 'blue',
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'CALLS',
                    'caption' => $runs->count().' total',
                    'value' => (string) $runs->count(),
                    'meta' => [
                        $this->presenter->meta('exit code '.$latest->exit_code, $this->commandStatusTone((int) $latest->exit_code)),
                        $this->presenter->meta('unsuccessful '.$unsuccessfulCount, $unsuccessfulCount > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $successfulCount, 'tone' => 'green'],
                        ['value' => (float) $unsuccessfulCount, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $this->presenter->duration((int) round($runs->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('AVG', 'blue'),
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars($durations, 'yellow'),
                ],
            ],
            'summaryPanels' => [
                [
                    'title' => 'Command',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Name', (string) $latest->name),
                        $this->presenter->info('Class', (string) $latest->class),
                        $this->presenter->info('Last exit code', (string) $latest->exit_code),
                        $this->presenter->info('Source', (string) ($latest->execution_source ?: 'command')),
                    ],
                ],
                [
                    'title' => 'Events',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Queries', (string) $latest->queries),
                        $this->presenter->info('Logs', (string) $latest->logs),
                        $this->presenter->info('Queue jobs', (string) $latest->jobs_queued),
                        $this->presenter->info('Peak memory', $this->presenter->bytes((int) $latest->peak_memory_bytes)),
                    ],
                ],
            ],
            'timeline' => null,
            'codePanels' => array_values(array_filter([
                [
                    'title' => 'Command',
                    'code' => (string) $latest->command,
                ],
                $latest->exception_preview ? [
                    'title' => 'Exception Preview',
                    'code' => (string) $latest->exception_preview,
                ] : null,
                [
                    'title' => 'Context',
                    'code' => $this->presenter->prettyJson($latest->context_json),
                ],
            ])),
            'tables' => [
                [
                    'title' => 'Recent Runs',
                    'caption' => '',
                    'searchPlaceholder' => 'grep run id…',
                    'columns' => [
                        ['key' => 'run', 'label' => 'Run', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $runs->map(fn (object $run): array => [
                        'id' => $run->execution_id,
                        'run' => $this->presenter->cell(
                            $this->presenter->longTimestamp($run->occurred_at),
                            ['meta' => 'exec '.$run->execution_id],
                        ),
                        'status' => $this->presenter->cell(
                            $this->commandStatusLabel((int) $run->exit_code),
                            ['tone' => $this->commandStatusTone((int) $run->exit_code)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $run->duration_us)),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_executions as executions')
            ->join('nw_command_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->join('nw_projects as projects', 'projects.id', '=', 'executions.project_id')
            ->where('executions.source', 'command')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->when($applyRange, fn (Builder $query) => $query->where('executions.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('details.name', 'like', $like)
                        ->orWhere('details.class', 'like', $like)
                        ->orWhere('details.command', 'like', $like)
                        ->orWhere('executions.execution_id', 'like', $like)
                        ->orWhere('executions.group_hash', 'like', $like);
                });
            })
            ->select([
                'projects.name as project_name',
                'executions.project_id',
                'executions.execution_id',
                'executions.group_hash',
                'executions.source as execution_source',
                'executions.occurred_at',
                'executions.duration_us',
                'executions.queries',
                'executions.logs',
                'executions.jobs_queued',
                'executions.peak_memory_bytes',
                'executions.exception_preview',
                'executions.context_json',
                'details.class',
                'details.name',
                'details.command',
                'details.exit_code',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();
        $durations = $group->pluck('duration_us')->all();
        $successfulCount = $group->filter(fn (object $row): bool => (int) $row->exit_code === 0)->count();
        $unsuccessfulCount = $group->count() - $successfulCount;

        return [
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'commands', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                    ],
                ],
                'command' => $this->presenter->cell(
                    (string) $latest->name,
                    ['meta' => $latest->class.' · '.$latest->project_name],
                ),
                'status' => $this->presenter->cell(
                    $unsuccessfulCount > 0 ? 'unsuccessful' : 'successful',
                    ['tone' => $unsuccessfulCount > 0 ? 'red' : 'green'],
                ),
                'avg' => $this->presenter->cell($this->presenter->duration((int) round($group->avg('duration_us') ?? 0))),
                'p95' => $this->presenter->cell($this->presenter->duration($this->presenter->percentile($durations, 0.95))),
            ],
        ];
    }

    private function commandStatusLabel(int $exitCode): string
    {
        return $exitCode === 0 ? 'successful' : 'unsuccessful';
    }

    private function commandStatusTone(int $exitCode): string
    {
        return $exitCode === 0 ? 'green' : 'red';
    }

    private function resolveRangeStart(string $range): string
    {
        return match ($range) {
            '1h' => now()->subHour()->toDateTimeString(),
            '7d' => now()->subDays(7)->toDateTimeString(),
            '14d' => now()->subDays(14)->toDateTimeString(),
            '30d' => now()->subDays(30)->toDateTimeString(),
            default => now()->subDay()->toDateTimeString(),
        };
    }

    private function publicFilters(array $filters): array
    {
        return [
            'project_id' => $filters['project_id'] ?? null,
            'range' => $filters['range'] ?? '24h',
            'search' => $filters['search'] ?? null,
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 15),
        ];
    }
}
