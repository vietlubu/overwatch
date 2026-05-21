<?php

namespace App\Nightwatch\Api;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_filter;
use function count;
use function max;

final class NightwatchExceptionScreenService
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
                $row->group_hash,
            ]))
            ->map(fn (Collection $group): array => $this->mapListGroup($group))
            ->sortByDesc(fn (array $group): string => $group['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($groups, $page, $perPage);
        $latestSeen = $rows->max('occurred_at');
        $handledGroups = $groups->filter(fn (array $group): bool => $group['handled'])->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Exceptions',
            'title' => 'Exceptions',
            'subtitle' => 'Exception groups and latest occurrences stored in nw_exceptions.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'EXCEPTIONS',
                    'caption' => sprintf('%d occurrences · %d groups', $rows->count(), $groups->count()),
                    'value' => (string) $groups->count(),
                    'meta' => [
                        $this->presenter->meta('handled '.$handledGroups, 'green'),
                        $this->presenter->meta('unhandled '.($groups->count() - $handledGroups), 'red'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $handledGroups, 'tone' => 'green'],
                        ['value' => (float) max(0, $groups->count() - $handledGroups), 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'LAST SEEN',
                    'caption' => $latestSeen ? $this->presenter->longTimestamp($latestSeen) : 'n/a',
                    'value' => (string) $rows->pluck('execution_id')->filter()->unique()->count(),
                    'meta' => [
                        $this->presenter->meta('scoped executions', 'sky'),
                    ],
                    'bars' => $this->presenter->sparkBars(
                        $groups->map(fn (array $group): int => $group['count'])->all(),
                        'mauve',
                    ),
                ],
            ],
            'table' => [
                'title' => sprintf('%d Exception Groups', $groups->count()),
                'caption' => 'Grouped by project and group hash.',
                'searchPlaceholder' => 'grep class, file, message…',
                'columns' => [
                    ['key' => 'exception', 'label' => 'Exception', 'kind' => 'primary'],
                    ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                    ['key' => 'lastSeen', 'label' => 'Last seen', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_exceptions')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->select(['project_id'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch exception group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Exception group hash [{$groupHash}] exists in multiple projects. Pass project_id.",
            );
        }

        $occurrences = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
        ], applyRange: false)
            ->where('exceptions.group_hash', $groupHash)
            ->orderByDesc('exceptions.occurred_at')
            ->get();

        $latest = $occurrences->first();
        $firstSeen = $occurrences->last();
        $unhandledCount = $occurrences->filter(fn (object $row): bool => ! $row->handled)->count();
        $timeline = $latest->execution_source === 'request' ? $this->timelineRows($latest) : [];
        $related = $occurrences
            ->filter(fn (object $row): bool => $row->execution_id !== null)
            ->take(10);

        $routeTag = $latest->route_name ?: $this->presenter->routeLabel($latest->method, $latest->route_path, $latest->request_url);

        return [
            'eyebrow' => 'Exception detail',
            'title' => (string) $latest->message,
            'subtitle' => 'Detailed view stitched from nw_exceptions, nw_executions, request metadata, and source context.',
            'backTo' => $this->presenter->backTo('exceptions'),
            'tags' => array_values(array_filter([
                ['text' => $latest->handled ? 'Handled' : 'Unhandled', 'tone' => $this->presenter->handledTone((bool) $latest->handled)],
                $latest->execution_source ? ['text' => $latest->execution_source === 'request' ? 'HTTP request' : (string) $latest->execution_source, 'tone' => 'blue'] : null,
                $routeTag ? ['text' => $routeTag, 'tone' => 'mauve'] : null,
            ])),
            'scope' => [
                'project_id' => $latest->project_id,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'LAST SEEN',
                    'caption' => $this->presenter->longTimestamp($latest->occurred_at),
                    'value' => sprintf('%d occurrences', $occurrences->count()),
                    'meta' => [
                        $this->presenter->meta('handled '.($occurrences->count() - $unhandledCount), 'green'),
                        $this->presenter->meta('unhandled '.$unhandledCount, 'red'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($occurrences->count() - $unhandledCount), 'tone' => 'green'],
                        ['value' => (float) $unhandledCount, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'RUNTIME',
                    'caption' => 'Execution duration',
                    'value' => $this->presenter->duration((int) ($latest->duration_us ?? 0)),
                    'meta' => [
                        $this->presenter->meta('peak '.$this->presenter->bytes((int) ($latest->peak_memory_bytes ?? 0)), 'sky'),
                        $this->presenter->meta('queries '.((string) ($latest->queries ?? 0)), 'yellow'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($latest->bootstrap_us ?? 0), 'tone' => 'blue'],
                        ['value' => (float) ($latest->before_middleware_us ?? 0), 'tone' => 'sky'],
                        ['value' => (float) ($latest->action_us ?? 0), 'tone' => 'red'],
                        ['value' => (float) (($latest->render_us ?? 0) + ($latest->sending_us ?? 0) + ($latest->after_middleware_us ?? 0) + ($latest->terminating_us ?? 0)), 'tone' => 'yellow'],
                    ]),
                ],
            ],
            'summaryPanels' => [
                [
                    'title' => 'Occurrence',
                    'caption' => 'Derived from nw_exceptions + request context.',
                    'entries' => [
                        $this->presenter->info('First seen', $this->presenter->longTimestamp($firstSeen->occurred_at)),
                        $this->presenter->info('Project', (string) $latest->project_name),
                        $this->presenter->info('Trace id', (string) ($latest->trace_id ?: 'n/a')),
                        $this->presenter->info('Request', $routeTag ?: 'n/a'),
                        $this->presenter->info('User', (string) ($latest->user_name ?: $latest->username ?: $latest->external_user_id ?: 'n/a')),
                    ],
                ],
                [
                    'title' => 'Runtime',
                    'caption' => 'Execution counters mirrored from nw_executions.',
                    'entries' => [
                        $this->presenter->info('Queries', (string) ($latest->queries ?? 0)),
                        $this->presenter->info('Logs', (string) ($latest->logs ?? 0)),
                        $this->presenter->info('Outgoing requests', (string) ($latest->outgoing_requests ?? 0)),
                        $this->presenter->info('Notifications', (string) ($latest->notifications ?? 0)),
                        $this->presenter->info('Hydrated models', (string) ($latest->hydrated_models ?? 0)),
                    ],
                ],
            ],
            'timeline' => $timeline === [] ? null : [
                'title' => 'Execution Timeline',
                'caption' => 'Potential response from `/api/executions/{id}/timeline`.',
                'rows' => $timeline,
            ],
            'codePanels' => [
                [
                    'title' => 'Error Preview',
                    'code' => $this->presenter->exceptionPreview(
                        (string) $latest->class,
                        (string) $latest->message,
                        $latest->file,
                        $latest->line,
                        $latest->trace_frames_json,
                    ),
                ],
                [
                    'title' => 'Stack Frames',
                    'code' => $this->presenter->traceFramesToText($latest->trace_frames_json),
                ],
            ],
            'tables' => [
                [
                    'title' => 'Related Executions',
                    'caption' => 'Nearby executions sharing the same exception group.',
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'execution', 'label' => 'Execution', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $related->map(fn (object $row): array => [
                        'id' => $row->id,
                        'href' => $row->execution_id ? [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'requests', 'detailId' => $row->execution_id],
                            'query' => [
                                'project_id' => (string) $row->project_id,
                            ],
                        ] : null,
                        'execution' => $this->presenter->cell(
                            (string) ($row->execution_id ?: $row->trace_id ?: 'n/a'),
                            ['meta' => $routeTag],
                        ),
                        'status' => $this->presenter->cell(
                            $row->handled ? 'handled' : 'unhandled',
                            ['tone' => $this->presenter->handledTone((bool) $row->handled)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) ($row->duration_us ?? 0))),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_exceptions as exceptions')
            ->join('nw_projects as projects', 'projects.id', '=', 'exceptions.project_id')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'exceptions.project_id')
                    ->on('executions.execution_id', '=', 'exceptions.execution_id');
            })
            ->leftJoin('nw_request_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->leftJoin('nw_users as users', function ($join): void {
                $join->on('users.project_id', '=', 'exceptions.project_id')
                    ->on('users.external_user_id', '=', 'exceptions.external_user_id');
            })
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('exceptions.project_id', $projectId))
            ->when($applyRange, fn (Builder $query) => $query->where('exceptions.occurred_at', '>=', $from))
            ->when(array_key_exists('handled', $filters), fn (Builder $query) => $query->where('exceptions.handled', (bool) $filters['handled']))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('exceptions.class', 'like', $like)
                        ->orWhere('exceptions.message', 'like', $like)
                        ->orWhere('exceptions.file', 'like', $like)
                        ->orWhere('details.route_name', 'like', $like)
                        ->orWhere('details.route_path', 'like', $like);
                });
            })
            ->select([
                'exceptions.id',
                'exceptions.project_id',
                'exceptions.group_hash',
                'exceptions.trace_id',
                'exceptions.execution_id',
                'exceptions.execution_source',
                'exceptions.occurred_at',
                'exceptions.external_user_id',
                'exceptions.class',
                'exceptions.file',
                'exceptions.line',
                'exceptions.message',
                'exceptions.code',
                'exceptions.trace_frames_json',
                'exceptions.handled',
                'projects.name as project_name',
                'executions.status',
                'executions.duration_us',
                'executions.queries',
                'executions.logs',
                'executions.notifications',
                'executions.outgoing_requests',
                'executions.hydrated_models',
                'executions.peak_memory_bytes',
                'details.method',
                'details.url as request_url',
                'details.route_name',
                'details.route_path',
                'details.bootstrap_us',
                'details.before_middleware_us',
                'details.action_us',
                'details.render_us',
                'details.after_middleware_us',
                'details.sending_us',
                'details.terminating_us',
                'users.name as user_name',
                'users.username',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();
        $handled = $group->every(fn (object $row): bool => (bool) $row->handled);
        $meta = trim(($latest->file ?: 'n/a').($latest->line ? ':'.$latest->line : ''));

        return [
            'handled' => $handled,
            'count' => $group->count(),
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'exceptions', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                    ],
                ],
                'exception' => $this->presenter->cell(
                    (string) $latest->message,
                    ['meta' => $meta.' · '.$group->count().' occurrence(s)'],
                ),
                'status' => $this->presenter->cell(
                    $handled ? 'handled' : 'unhandled',
                    ['tone' => $this->presenter->handledTone($handled)],
                ),
                'lastSeen' => $this->presenter->cell($this->presenter->shortTimestamp($latest->occurred_at)),
            ],
        ];
    }

    private function timelineRows(object $exception): array
    {
        $stages = [
            ['label' => 'bootstrap', 'value' => (int) ($exception->bootstrap_us ?? 0), 'tone' => 'blue'],
            ['label' => 'before middleware', 'value' => (int) ($exception->before_middleware_us ?? 0), 'tone' => 'sky'],
            ['label' => 'action', 'value' => (int) ($exception->action_us ?? 0), 'tone' => 'red'],
            ['label' => 'render', 'value' => (int) ($exception->render_us ?? 0), 'tone' => 'yellow'],
            ['label' => 'send + terminate', 'value' => (int) (($exception->after_middleware_us ?? 0) + ($exception->sending_us ?? 0) + ($exception->terminating_us ?? 0)), 'tone' => 'green'],
        ];

        $maxValue = max(array_map(static fn (array $stage): int => $stage['value'], $stages)) ?: 1;

        return array_map(
            fn (array $stage): array => $this->presenter->timelineRow(
                $stage['label'],
                $this->presenter->duration($stage['value']),
                ($stage['value'] / $maxValue) * 100,
                $stage['tone'],
            ),
            $stages,
        );
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
            'handled' => $filters['handled'] ?? null,
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 15),
        ];
    }
}
