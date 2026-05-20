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
use function round;

final class NightwatchRequestScreenService
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
                $row->method,
                $row->route_name ?? '',
                $row->route_domain ?? '',
                $row->route_path ?? '',
            ]))
            ->map(fn (Collection $group): array => $this->mapListGroup($group))
            ->sortByDesc(fn (array $group): string => $group['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($groups, $page, $perPage);
        $durations = $rows->pluck('duration_us')->all();
        $statusCodes = $rows->pluck('status_code');
        $buckets = $this->bucketRequestSeries($rows);

        return [
            'kind' => 'collection',
            'eyebrow' => 'HTTP activity',
            'title' => 'Requests',
            'subtitle' => 'Grouped route performance built from nw_request_details with execution-level drill-down.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'REQUESTS',
                    'caption' => sprintf('%d routes · %d total', $groups->count(), $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('2xx '.$statusCodes->filter(fn ($status): bool => $status >= 200 && $status < 400)->count(), 'green'),
                        $this->presenter->meta('4xx '.$statusCodes->filter(fn ($status): bool => $status >= 400 && $status < 500)->count(), 'yellow'),
                        $this->presenter->meta('5xx '.$statusCodes->filter(fn ($status): bool => $status >= 500)->count(), 'red'),
                    ],
                    'bars' => $this->presenter->sparkBars($buckets['counts'], 'blue'),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $rows->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $this->presenter->duration((int) round($rows->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                        $this->presenter->meta('Slowest '.$this->presenter->duration((int) max($durations ?: [0])), 'red'),
                    ],
                    'bars' => $this->presenter->sparkBars($buckets['p95'], 'yellow'),
                ],
            ],
            'plots' => [
                [
                    'title' => 'Requests by minute',
                    'caption' => 'Live aggregation over nw_request_details and nw_executions.',
                    'fromLabel' => $buckets['from'],
                    'toLabel' => $buckets['to'],
                    'series' => [
                        [
                            'label' => 'requests',
                            'tone' => 'sky',
                            'values' => $this->presenter->normalizeSeries($buckets['counts']),
                        ],
                        [
                            'label' => 'p95',
                            'tone' => 'yellow',
                            'values' => $this->presenter->normalizeSeries($buckets['p95']),
                        ],
                    ],
                ],
            ],
            'table' => [
                'title' => sprintf('%d Routes', $groups->count()),
                'caption' => 'Potential response shape: `{ metrics, plots, table, pagination }`',
                'searchPlaceholder' => 'grep route, url, execution…',
                'columns' => [
                    ['key' => 'route', 'label' => 'Route', 'kind' => 'primary'],
                    ['key' => 'requests', 'label' => 'Requests'],
                    ['key' => 'users', 'label' => 'Users'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                    ['key' => 'p95', 'label' => 'P95', 'kind' => 'code'],
                    ['key' => 'failures', 'label' => '5XX', 'kind' => 'tone'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $executionId, array $filters): array
    {
        $scopes = DB::table('nw_executions')
            ->where('execution_id', $executionId)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch request execution [{$executionId}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Execution id [{$executionId}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $request = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('executions.execution_id', $executionId)
            ->orderByDesc('executions.occurred_at')
            ->firstOrFail();
        $related = $this->baseQuery([
            'project_id' => $request->project_id,
            'environment' => $request->environment,
        ], applyRange: false)
            ->where('details.method', $request->method)
            ->where(function (Builder $query) use ($request): void {
                $query
                    ->where('details.route_path', $request->route_path)
                    ->orWhere('details.url', $request->url);
            })
            ->where('executions.execution_id', '!=', $request->execution_id)
            ->orderByDesc('executions.occurred_at')
            ->limit(10)
            ->get();

        $timeline = $this->timelineRows($request);
        $userLabel = $request->user_name ?: $request->username ?: $request->external_user_id;

        return [
            'eyebrow' => 'Request detail',
            'title' => $this->presenter->routeLabel($request->method, $request->route_path, $request->url),
            'subtitle' => 'Execution detail from nw_request_details joined to nw_executions and child event counters.',
            'backTo' => $this->presenter->backTo('requests'),
            'tags' => array_values(array_filter([
                ['text' => $request->status_code.' '.($request->status_code >= 500 ? 'ERROR' : 'OK'), 'tone' => $this->presenter->statusCodeTone((int) $request->status_code)],
                $userLabel ? ['text' => 'user '.$userLabel, 'tone' => 'sky'] : null,
                $request->route_name ? ['text' => $request->route_name, 'tone' => 'mauve'] : null,
            ])),
            'scope' => [
                'project_id' => $request->project_id,
                'environment' => $request->environment,
                'execution_id' => $request->execution_id,
            ],
            'metrics' => [
                [
                    'label' => 'REQUEST',
                    'caption' => 'received '.$this->presenter->longTimestamp($request->occurred_at),
                    'value' => '1',
                    'meta' => [
                        $this->presenter->meta('status '.$request->status_code, $this->presenter->statusCodeTone((int) $request->status_code)),
                        $this->presenter->meta('payload '.$this->presenter->bytes((int) $request->request_bytes), 'blue'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $request->request_bytes, 'tone' => 'blue'],
                        ['value' => (float) $request->response_bytes, 'tone' => 'sky'],
                        ['value' => (float) $request->queries, 'tone' => 'yellow'],
                        ['value' => (float) $request->outgoing_requests, 'tone' => 'mauve'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => 'execution runtime',
                    'value' => $this->presenter->duration((int) $request->duration_us),
                    'meta' => [
                        $this->presenter->meta('peak '.$this->presenter->bytes((int) $request->peak_memory_bytes), 'sky'),
                        $this->presenter->meta('queries '.$request->queries, 'yellow'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $request->bootstrap_us, 'tone' => 'blue'],
                        ['value' => (float) $request->before_middleware_us, 'tone' => 'sky'],
                        ['value' => (float) $request->action_us, 'tone' => 'mauve'],
                        ['value' => (float) $request->render_us, 'tone' => 'yellow'],
                        ['value' => (float) $request->sending_us + (float) $request->after_middleware_us + (float) $request->terminating_us, 'tone' => 'green'],
                    ]),
                ],
            ],
            'summaryPanels' => [
                [
                    'title' => 'Info',
                    'caption' => 'Mirrors nw_request_details columns.',
                    'entries' => [
                        $this->presenter->info('Method', (string) $request->method),
                        $this->presenter->info('Route', (string) ($request->route_path ?: $request->url)),
                        $this->presenter->info('Status code', (string) $request->status_code),
                        $this->presenter->info('Server', (string) ($request->server_name ?: 'n/a')),
                        $this->presenter->info('Payload state', (string) $request->request_payload_state),
                    ],
                ],
                [
                    'title' => 'Events',
                    'caption' => 'Counts borrowed from nw_executions.',
                    'entries' => [
                        $this->presenter->info('Queries', (string) $request->queries),
                        $this->presenter->info('Logs', (string) $request->logs),
                        $this->presenter->info('Outgoing requests', (string) $request->outgoing_requests),
                        $this->presenter->info('Notifications', (string) $request->notifications),
                        $this->presenter->info('Cache', (string) $request->cache_events),
                    ],
                ],
            ],
            'timeline' => [
                'title' => 'Timeline',
                'caption' => 'Normalized duration breakdown for the sampled request.',
                'rows' => $timeline,
            ],
            'codePanels' => [
                [
                    'title' => 'Headers',
                    'code' => $this->presenter->headersToText($request->headers_json),
                ],
                [
                    'title' => 'Request Payload',
                    'code' => $request->request_payload_state === 'present'
                        ? $this->presenter->prettyJson($request->request_payload_json)
                        : 'Payload state: '.$request->request_payload_state,
                ],
            ],
            'tables' => [
                [
                    'title' => 'Related Executions',
                    'caption' => 'Nearby requests sharing the same route signature.',
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'execution', 'label' => 'Execution', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $related->map(fn (object $row): array => [
                        'id' => $row->execution_id,
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'requests', 'detailId' => $row->execution_id],
                            'query' => [
                                'project_id' => (string) $row->project_id,
                                'environment' => $row->environment,
                            ],
                        ],
                        'execution' => $this->presenter->cell(
                            $row->execution_id,
                            ['meta' => $this->presenter->shortTimestamp($row->occurred_at)],
                        ),
                        'status' => $this->presenter->cell(
                            (string) $row->status,
                            ['tone' => $this->presenter->executionTone($row->status)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $row->duration_us)),
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
            ->join('nw_request_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->join('nw_projects as projects', 'projects.id', '=', 'executions.project_id')
            ->leftJoin('nw_users as users', function ($join): void {
                $join->on('users.project_id', '=', 'executions.project_id')
                    ->on('users.environment', '=', 'executions.environment')
                    ->on('users.external_user_id', '=', 'executions.external_user_id');
            })
            ->leftJoin('nw_servers as servers', 'servers.id', '=', 'executions.server_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('executions.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('executions.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('details.method', 'like', $like)
                        ->orWhere('details.url', 'like', $like)
                        ->orWhere('details.route_name', 'like', $like)
                        ->orWhere('details.route_path', 'like', $like)
                        ->orWhere('details.route_action', 'like', $like)
                        ->orWhere('executions.execution_id', 'like', $like)
                        ->orWhere('users.name', 'like', $like)
                        ->orWhere('users.username', 'like', $like)
                        ->orWhere('executions.external_user_id', 'like', $like);
                });
            })
            ->select([
                'projects.name as project_name',
                'executions.project_id',
                'executions.environment',
                'executions.execution_id',
                'executions.occurred_at',
                'executions.duration_us',
                'executions.status',
                'executions.trace_id',
                'executions.preview',
                'executions.external_user_id',
                'executions.queries',
                'executions.logs',
                'executions.notifications',
                'executions.outgoing_requests',
                'executions.jobs_queued',
                'executions.cache_events',
                'executions.hydrated_models',
                'executions.peak_memory_bytes',
                'details.method',
                'details.url',
                'details.route_name',
                'details.route_domain',
                'details.route_path',
                'details.route_action',
                'details.status_code',
                'details.request_bytes',
                'details.response_bytes',
                'details.bootstrap_us',
                'details.before_middleware_us',
                'details.action_us',
                'details.render_us',
                'details.after_middleware_us',
                'details.sending_us',
                'details.terminating_us',
                'details.headers_json',
                'details.request_payload_json',
                'details.request_payload_state',
                'users.name as user_name',
                'users.username',
                'servers.name as server_name',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();
        $durations = $group->pluck('duration_us')->all();
        $latestExecutionId = (string) $latest->execution_id;
        $route = $this->presenter->routeLabel($latest->method, $latest->route_path, $latest->url);
        $meta = array_filter([
            $latest->route_name,
            $latest->project_name.' · '.$latest->environment,
        ]);

        return [
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->environment.'|'.$route),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'requests', 'detailId' => $latestExecutionId],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                        'environment' => $latest->environment,
                    ],
                ],
                'route' => $this->presenter->cell($route, ['meta' => implode(' · ', $meta)]),
                'requests' => $this->presenter->cell((string) $group->count()),
                'users' => $this->presenter->cell((string) $group->pluck('external_user_id')->filter()->unique()->count()),
                'avg' => $this->presenter->cell($this->presenter->duration((int) round($group->avg('duration_us') ?? 0))),
                'p95' => $this->presenter->cell($this->presenter->duration($this->presenter->percentile($durations, 0.95))),
                'failures' => $this->presenter->cell(
                    (string) $group->filter(fn (object $row): bool => (int) $row->status_code >= 500)->count(),
                    ['tone' => $group->contains(fn (object $row): bool => (int) $row->status_code >= 500) ? 'red' : 'green'],
                ),
            ],
        ];
    }

    private function bucketRequestSeries(Collection $rows): array
    {
        $buckets = $rows
            ->groupBy(fn (object $row): string => CarbonImmutable::parse($row->occurred_at)->timezone(config('app.timezone'))->format('Y-m-d H:i'))
            ->sortKeys();

        if ($buckets->isEmpty()) {
            return [
                'counts' => [0],
                'p95' => [0],
                'from' => 'n/a',
                'to' => 'n/a',
            ];
        }

        $counts = $buckets->map(fn (Collection $group): int => $group->count())->values()->all();
        $p95 = $buckets->map(fn (Collection $group): int => $this->presenter->percentile($group->pluck('duration_us')->all(), 0.95))->values()->all();
        $labels = $buckets->keys()->values();

        return [
            'counts' => $counts,
            'p95' => $p95,
            'from' => CarbonImmutable::parse($labels->first(), config('app.timezone'))->format('M j, H:i'),
            'to' => CarbonImmutable::parse($labels->last(), config('app.timezone'))->format('M j, H:i'),
        ];
    }

    private function timelineRows(object $request): array
    {
        $stages = [
            ['label' => 'bootstrap', 'value' => (int) $request->bootstrap_us, 'tone' => 'blue'],
            ['label' => 'before middleware', 'value' => (int) $request->before_middleware_us, 'tone' => 'sky'],
            ['label' => 'action', 'value' => (int) $request->action_us, 'tone' => 'mauve'],
            ['label' => 'render', 'value' => (int) $request->render_us, 'tone' => 'yellow'],
            ['label' => 'after middleware', 'value' => (int) $request->after_middleware_us, 'tone' => 'green'],
            ['label' => 'send', 'value' => (int) $request->sending_us, 'tone' => 'blue'],
            ['label' => 'terminate', 'value' => (int) $request->terminating_us, 'tone' => 'red'],
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
            'environment' => $filters['environment'] ?? null,
            'range' => $filters['range'] ?? '24h',
            'search' => $filters['search'] ?? null,
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 15),
        ];
    }
}
