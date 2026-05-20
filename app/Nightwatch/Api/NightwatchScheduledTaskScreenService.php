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

final class NightwatchScheduledTaskScreenService
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
                $row->environment,
                $row->group_hash ?? '',
            ]))
            ->map(fn (Collection $group): array => $this->mapListGroup($group))
            ->sortByDesc(fn (array $group): string => $group['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($groups, $page, $perPage);
        $durations = $rows->pluck('duration_us')->all();
        $failedCount = $rows->where('status', 'failed')->count();
        $skippedCount = $rows->where('status', 'skipped')->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Scheduler',
            'title' => 'Scheduled Tasks',
            'subtitle' => 'Schedule telemetry from nw_schedule_1m and per-run metadata from nw_scheduled_task_details.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'RUNS',
                    'caption' => sprintf('%d total', $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('failed '.$failedCount, $failedCount > 0 ? 'red' : 'green'),
                        $this->presenter->meta('skipped '.$skippedCount, $skippedCount > 0 ? 'yellow' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $rows->where('status', 'processed')->count(), 'tone' => 'green'],
                        ['value' => (float) $skippedCount, 'tone' => 'yellow'],
                        ['value' => (float) $failedCount, 'tone' => 'red'],
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
                'title' => sprintf('%d Tasks', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep cron, command…',
                'columns' => [
                    ['key' => 'task', 'label' => 'Task', 'kind' => 'primary'],
                    ['key' => 'schedule', 'label' => 'Schedule', 'kind' => 'code'],
                    ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_executions')
            ->where('source', 'schedule')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch scheduled task group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Scheduled task group hash [{$groupHash}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $runs = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('executions.group_hash', $groupHash)
            ->orderByDesc('executions.occurred_at')
            ->get();

        $latest = $runs->first();
        $durations = $runs->pluck('duration_us')->all();

        return [
            'eyebrow' => 'Scheduled task detail',
            'title' => (string) $latest->name,
            'subtitle' => 'A detail screen shaped around nw_scheduled_task_details with related command activity.',
            'backTo' => $this->presenter->backTo('scheduled-tasks'),
            'tags' => [
                [
                    'text' => (string) $latest->status,
                    'tone' => $this->scheduledStatusTone((string) $latest->status),
                ],
                [
                    'text' => $this->scheduleLabel($latest),
                    'tone' => 'mauve',
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'environment' => $latest->environment,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'RUNS',
                    'caption' => (string) $runs->count(),
                    'value' => (string) $runs->count(),
                    'meta' => [
                        $this->presenter->meta('processed '.$runs->where('status', 'processed')->count(), 'green'),
                        $this->presenter->meta('failed '.$runs->where('status', 'failed')->count(), 'red'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $runs->where('status', 'processed')->count(), 'tone' => 'green'],
                        ['value' => (float) $runs->where('status', 'skipped')->count(), 'tone' => 'yellow'],
                        ['value' => (float) $runs->where('status', 'failed')->count(), 'tone' => 'red'],
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
                    'title' => 'Info',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Cron', (string) $latest->cron),
                        $this->presenter->info('Timezone', (string) ($latest->timezone ?: 'n/a')),
                        $this->presenter->info('Without overlapping', $latest->without_overlapping ? 'Yes' : 'No'),
                        $this->presenter->info('Run in background', $latest->run_in_background ? 'Yes' : 'No'),
                        $this->presenter->info('On one server', $latest->on_one_server ? 'Yes' : 'No'),
                    ],
                ],
                [
                    'title' => 'Events',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Queries', (string) $latest->queries),
                        $this->presenter->info('Outgoing requests', (string) $latest->outgoing_requests),
                        $this->presenter->info('Mail', (string) $latest->mail),
                        $this->presenter->info('Queue jobs', (string) $latest->jobs_queued),
                    ],
                ],
            ],
            'timeline' => null,
            'codePanels' => array_values(array_filter([
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
                    'searchPlaceholder' => 'grep run timestamp…',
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
                            (string) $run->status,
                            ['tone' => $this->scheduledStatusTone((string) $run->status)],
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
            ->join('nw_scheduled_task_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->join('nw_projects as projects', 'projects.id', '=', 'executions.project_id')
            ->where('executions.source', 'schedule')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('executions.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('executions.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('details.name', 'like', $like)
                        ->orWhere('details.cron', 'like', $like)
                        ->orWhere('details.timezone', 'like', $like)
                        ->orWhere('executions.execution_id', 'like', $like)
                        ->orWhere('executions.group_hash', 'like', $like);
                });
            })
            ->select([
                'projects.name as project_name',
                'executions.project_id',
                'executions.environment',
                'executions.execution_id',
                'executions.group_hash',
                'executions.occurred_at',
                'executions.duration_us',
                'executions.queries',
                'executions.jobs_queued',
                'executions.mail',
                'executions.outgoing_requests',
                'executions.exception_preview',
                'executions.context_json',
                'details.name',
                'details.cron',
                'details.timezone',
                'details.repeat_seconds',
                'details.without_overlapping',
                'details.on_one_server',
                'details.run_in_background',
                'details.even_in_maintenance_mode',
                'details.status',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();

        return [
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->environment.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'scheduled-tasks', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                        'environment' => $latest->environment,
                    ],
                ],
                'task' => $this->presenter->cell(
                    (string) $latest->name,
                    ['meta' => $this->scheduleLabel($latest)],
                ),
                'schedule' => $this->presenter->cell((string) $latest->cron),
                'status' => $this->presenter->cell(
                    (string) $latest->status,
                    ['tone' => $this->scheduledStatusTone((string) $latest->status)],
                ),
                'avg' => $this->presenter->cell($this->presenter->duration((int) round($group->avg('duration_us') ?? 0))),
            ],
        ];
    }

    private function scheduleLabel(object $task): string
    {
        if ((int) $task->repeat_seconds > 0) {
            return 'every '.$task->repeat_seconds.' seconds';
        }

        return (string) ($task->timezone ?: $task->cron);
    }

    private function scheduledStatusTone(string $status): string
    {
        return match ($status) {
            'failed' => 'red',
            'skipped' => 'yellow',
            default => 'green',
        };
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
            'environment' => $filters['environment'] ?? null,
            'range' => $filters['range'] ?? '24h',
            'search' => $filters['search'] ?? null,
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 15),
        ];
    }
}
