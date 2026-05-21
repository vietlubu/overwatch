<?php

namespace App\Nightwatch\Api;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function array_filter;
use function count;
use function implode;
use function max;
use function min;
use function round;
use function sha1;
use function sprintf;
use function str_pad;

final class NightwatchDashboardScreenService
{
    public function __construct(
        private readonly NightwatchScreenPresenter $presenter,
    ) {
        //
    }

    public function index(array $filters): array
    {
        $requests = $this->requestQuery($filters)->get();
        $exceptions = $this->exceptionQuery($filters)->get();
        $jobAttempts = $this->jobAttemptQuery($filters)->get();
        $commands = $this->commandQuery($filters)->get();
        $cacheEvents = $this->cacheQuery($filters)->get();
        $users = $this->userQuery($filters)->get()
            ->keyBy(fn (object $user): string => $this->userKey((int) $user->project_id, (string) $user->external_user_id));
        $infrastructure = $this->infrastructureSummary($filters);

        return [
            'eyebrow' => 'Overwatch',
            'title' => 'Dashboard',
            'subtitle' => 'Live Nightwatch overview built from request, exception, job, command, cache, and user activity.',
            'sections' => [
                $this->activitySection($requests, $exceptions, $jobAttempts),
                $this->applicationSection($jobAttempts, $commands, $cacheEvents, $infrastructure),
                $this->usersSection($requests, $exceptions, $users),
            ],
        ];
    }

    private function activitySection(Collection $requests, Collection $exceptions, Collection $jobAttempts): array
    {
        $routeGroups = $this->topRouteItems($requests);
        $durations = $requests->pluck('duration_us')->all();
        $statusCodes = $requests->pluck('status_code');
        $requestBuckets = $this->requestMinuteBuckets($requests);
        $jobBuckets = $this->jobThroughputBuckets($jobAttempts);
        $handledCount = $exceptions->where('handled', true)->count();
        $unhandledCount = $exceptions->where('handled', false)->count();
        $unhandledGroups = $exceptions
            ->where('handled', false)
            ->groupBy(fn (object $row): string => $this->exceptionGroupKey($row))
            ->count();
        $impactedUsers = $exceptions
            ->where('handled', false)
            ->filter(fn (object $row): bool => $row->external_user_id !== null && $row->external_user_id !== '')
            ->map(fn (object $row): string => $this->userKey((int) $row->project_id, (string) $row->external_user_id))
            ->unique()
            ->count();

        return [
            'title' => 'Activity',
            'icon' => '[]',
            'caption' => 'Live request, exception, and queue throughput from Nightwatch fact tables.',
            'metrics' => [
                [
                    'label' => 'REQUESTS',
                    'caption' => sprintf(
                        '%d routes · avg %s',
                        $routeGroups->count(),
                        $this->presenter->duration((int) round($requests->avg('duration_us') ?? 0)),
                    ),
                    'value' => (string) $requests->count(),
                    'meta' => [
                        $this->presenter->meta('2xx/3xx '.$statusCodes->filter(fn ($status): bool => $status >= 200 && $status < 400)->count(), 'green'),
                        $this->presenter->meta('4xx '.$statusCodes->filter(fn ($status): bool => $status >= 400 && $status < 500)->count(), 'yellow'),
                        $this->presenter->meta('5xx '.$statusCodes->filter(fn ($status): bool => $status >= 500)->count(), 'red'),
                    ],
                    'bars' => $this->presenter->sparkBars($requestBuckets['counts'], 'blue'),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $requests->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $requests->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) round($requests->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                        $this->presenter->meta('Slowest '.$this->presenter->duration((int) max($durations ?: [0])), 'red'),
                    ],
                    'bars' => $this->presenter->sparkBars($requestBuckets['p95'], 'yellow'),
                ],
                [
                    'label' => 'ISSUES',
                    'caption' => 'distinct unhandled exception groups',
                    'value' => sprintf('%d open', $unhandledGroups),
                    'meta' => [
                        $this->presenter->meta('handled '.$handledCount, 'green'),
                        $this->presenter->meta('unhandled '.$unhandledCount, 'red'),
                        $this->presenter->meta('impacted users '.$impactedUsers, 'sky'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $handledCount, 'tone' => 'green'],
                        ['value' => (float) $unhandledCount, 'tone' => 'red'],
                        ['value' => (float) $impactedUsers, 'tone' => 'sky'],
                    ]),
                ],
            ],
            'plots' => [
                [
                    'title' => 'Slowest Routes',
                    'caption' => 'Request latency by minute from nw_request_details joined to nw_executions.',
                    'fromLabel' => $requestBuckets['from'],
                    'toLabel' => $requestBuckets['to'],
                    'series' => [
                        [
                            'label' => 'AVG',
                            'tone' => 'blue',
                            'values' => $this->presenter->normalizeSeries($requestBuckets['avg']),
                        ],
                        [
                            'label' => 'P95',
                            'tone' => 'yellow',
                            'values' => $this->presenter->normalizeSeries($requestBuckets['p95']),
                        ],
                    ],
                ],
                [
                    'title' => 'Job Throughput',
                    'caption' => 'Processed versus failed job attempts per minute.',
                    'fromLabel' => $jobBuckets['from'],
                    'toLabel' => $jobBuckets['to'],
                    'series' => [
                        [
                            'label' => 'processed',
                            'tone' => 'green',
                            'values' => $this->presenter->normalizeSeries($jobBuckets['processed']),
                        ],
                        [
                            'label' => 'failed',
                            'tone' => 'red',
                            'values' => $this->presenter->normalizeSeries($jobBuckets['failed']),
                        ],
                    ],
                ],
            ],
            'tables' => [
                [
                    'title' => 'Top Routes',
                    'caption' => 'Highest-volume routes in the selected time range.',
                    'searchPlaceholder' => 'grep route…',
                    'columns' => [
                        ['key' => 'route', 'label' => 'Route', 'kind' => 'primary'],
                        ['key' => 'requests', 'label' => 'Requests'],
                        ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                        ['key' => 'p95', 'label' => 'P95', 'kind' => 'code'],
                    ],
                    'rows' => $routeGroups->map(fn (array $item): array => $item['row'])->all(),
                ],
            ],
        ];
    }

    private function applicationSection(
        Collection $jobAttempts,
        Collection $commands,
        Collection $cacheEvents,
        array $infrastructure,
    ): array {
        $jobDurations = $jobAttempts->pluck('duration_us')->all();
        $commandDurations = $commands->pluck('duration_us')->all();
        $processedJobs = $jobAttempts->where('status', 'processed')->count();
        $releasedJobs = $jobAttempts->where('status', 'released')->count();
        $failedJobs = $jobAttempts->where('status', 'failed')->count();
        $writeCount = $cacheEvents->where('cache_event_type', 'write')->count();
        $failureCount = $cacheEvents->filter(
            fn (object $row): bool => $row->cache_event_type === 'write-failure' || $row->cache_event_type === 'delete-failure',
        )->count();

        return [
            'title' => 'Application',
            'icon' => '[*]',
            'caption' => 'Current execution, cache, and infrastructure health without waiting for rollups.',
            'metrics' => [
                [
                    'label' => 'JOBS',
                    'caption' => sprintf('processed %d · released %d', $processedJobs, $releasedJobs),
                    'value' => sprintf('%d failed', $failedJobs),
                    'meta' => [
                        $this->presenter->meta('attempts '.$jobAttempts->count(), 'blue'),
                        $this->presenter->meta(
                            'P95 '.$this->presenter->duration($this->presenter->percentile($jobDurations, 0.95)),
                            'yellow',
                        ),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $processedJobs, 'tone' => 'green'],
                        ['value' => (float) $releasedJobs, 'tone' => 'yellow'],
                        ['value' => (float) $failedJobs, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'COMMANDS',
                    'caption' => sprintf('%d command executions', $commands->count()),
                    'value' => $commands->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) round($commands->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta(
                            'P95 '.$this->presenter->duration($this->presenter->percentile($commandDurations, 0.95)),
                            'yellow',
                        ),
                        $this->presenter->meta('slowest '.$this->presenter->duration((int) max($commandDurations ?: [0])), 'red'),
                    ],
                    'bars' => $this->presenter->sparkBars($commandDurations === [] ? [0] : $commandDurations, 'blue'),
                ],
                [
                    'label' => 'CACHE',
                    'caption' => sprintf('%d events across %d keys', $cacheEvents->count(), $cacheEvents->pluck('cache_key')->unique()->count()),
                    'value' => sprintf('%d writes', $writeCount),
                    'meta' => [
                        $this->presenter->meta('hits '.$cacheEvents->where('cache_event_type', 'hit')->count(), 'blue'),
                        $this->presenter->meta('misses '.$cacheEvents->where('cache_event_type', 'miss')->count(), 'yellow'),
                        $this->presenter->meta('failures '.$failureCount, $failureCount > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $cacheEvents->where('cache_event_type', 'hit')->count(), 'tone' => 'blue'],
                        ['value' => (float) $cacheEvents->where('cache_event_type', 'miss')->count(), 'tone' => 'yellow'],
                        ['value' => (float) $writeCount, 'tone' => 'green'],
                        ['value' => (float) $failureCount, 'tone' => 'red'],
                    ]),
                ],
            ],
            'panels' => [
                [
                    'title' => 'Retention',
                    'caption' => 'Derived from config(overwatch.storage.*).',
                    'entries' => [
                        $this->presenter->info('Fact tables', config('overwatch.storage.retention_days').' days'),
                        $this->presenter->info('Rollup tables', config('overwatch.storage.rollup_retention_days').' days'),
                        $this->presenter->info('Partition precreate', config('overwatch.storage.partition_precreate_months').' months'),
                    ],
                ],
                [
                    'title' => 'Infrastructure',
                    'caption' => 'Scoped Nightwatch project, server, and deployment summary.',
                    'entries' => [
                        $this->presenter->info('Scope', $infrastructure['scope']),
                        $this->presenter->info('Projects observed', (string) $infrastructure['projects']),
                        $this->presenter->info('Servers observed', (string) $infrastructure['servers']),
                        $this->presenter->info('Deployments observed', (string) $infrastructure['deployments']),
                        $this->presenter->info('Last event', $infrastructure['last_seen']),
                    ],
                ],
            ],
        ];
    }

    private function usersSection(Collection $requests, Collection $exceptions, Collection $users): array
    {
        $scopedRequests = $requests->filter(
            fn (object $row): bool => $row->external_user_id !== null && $row->external_user_id !== '',
        );
        $requestsByUser = $scopedRequests->groupBy(
            fn (object $row): string => $this->userKey((int) $row->project_id, (string) $row->external_user_id),
        );
        $exceptionCounts = $exceptions
            ->filter(fn (object $row): bool => $row->external_user_id !== null && $row->external_user_id !== '')
            ->groupBy(fn (object $row): string => $this->userKey((int) $row->project_id, (string) $row->external_user_id))
            ->map(fn (Collection $rows): int => $rows->count());
        $unhandledExceptions = $exceptions->where('handled', false);
        $impactedKeys = $unhandledExceptions
            ->filter(fn (object $row): bool => $row->external_user_id !== null && $row->external_user_id !== '')
            ->map(fn (object $row): string => $this->userKey((int) $row->project_id, (string) $row->external_user_id))
            ->unique();
        $items = $requestsByUser
            ->map(function (Collection $userRequests, string $key) use ($users, $exceptionCounts): array {
                $user = $users->get($key);
                $first = $userRequests->first();
                $requestCount = $userRequests->count();
                $name = (string) ($user->name ?? $user->username ?? $first->external_user_id);
                $meta = array_filter([
                    $user->username ?? null,
                    $user && $user->username !== $user->external_user_id ? $user->external_user_id : null,
                ]);
                $exceptionCount = (int) ($exceptionCounts->get($key) ?? 0);

                return [
                    'request_count' => $requestCount,
                    'sort_key' => str_pad((string) $requestCount, 6, '0', STR_PAD_LEFT).'|'.(string) ($user->last_seen_at ?? $first->occurred_at),
                    'row' => [
                        'id' => sha1('dashboard-user|'.$key),
                        'user' => $this->presenter->cell($name, ['meta' => implode(' · ', $meta)]),
                        'requests' => $this->presenter->cell((string) $requestCount),
                        'exceptions' => $this->presenter->cell(
                            (string) $exceptionCount,
                            ['tone' => $exceptionCount > 0 ? 'red' : 'green'],
                        ),
                    ],
                ];
            })
            ->sortByDesc('sort_key')
            ->values();

        return [
            'title' => 'Users',
            'icon' => '[U]',
            'caption' => 'Authenticated request activity and exception impact by Nightwatch user identity.',
            'metrics' => [
                [
                    'label' => 'AUTHENTICATED USERS',
                    'caption' => sprintf('%d distinct users in the selected range', $requestsByUser->count()),
                    'value' => sprintf('%d requests', $scopedRequests->count()),
                    'meta' => [
                        $this->presenter->meta('active '.$requestsByUser->count(), 'green'),
                        $this->presenter->meta('known '.$users->count(), 'blue'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $requestsByUser->count(), 'tone' => 'green'],
                        ['value' => (float) max(0, $users->count() - $requestsByUser->count()), 'tone' => 'yellow'],
                    ]),
                ],
                [
                    'label' => 'IMPACTED USERS',
                    'caption' => 'users tied to unhandled exceptions',
                    'value' => (string) $impactedKeys->count(),
                    'meta' => [
                        $this->presenter->meta('exceptions '.$unhandledExceptions->count(), 'red'),
                        $this->presenter->meta('requests '.$unhandledExceptions->pluck('execution_id')->unique()->count(), 'blue'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $impactedKeys->count(), 'tone' => 'red'],
                        ['value' => (float) max(0, $requestsByUser->count() - $impactedKeys->count()), 'tone' => 'green'],
                    ]),
                ],
            ],
            'tables' => [
                [
                    'title' => 'Most Active Users',
                    'caption' => 'Top authenticated users by request volume.',
                    'searchPlaceholder' => 'grep name, email…',
                    'columns' => [
                        ['key' => 'user', 'label' => 'User', 'kind' => 'primary'],
                        ['key' => 'requests', 'label' => 'Requests'],
                        ['key' => 'exceptions', 'label' => 'Exceptions', 'kind' => 'tone'],
                    ],
                    'rows' => $items->map(fn (array $item): array => $item['row'])->all(),
                ],
            ],
        ];
    }

    private function requestQuery(array $filters): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_executions as executions')
            ->join('nw_request_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->where('executions.occurred_at', '>=', $from)
            ->select([
                'executions.project_id',
                'executions.execution_id',
                'executions.occurred_at',
                'executions.duration_us',
                'executions.external_user_id',
                'details.method',
                'details.url',
                'details.route_name',
                'details.route_path',
                'details.status_code',
            ]);
    }

    private function exceptionQuery(array $filters): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_exceptions as exceptions')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('exceptions.project_id', $projectId))
            ->where('exceptions.occurred_at', '>=', $from)
            ->select([
                'exceptions.project_id',
                'exceptions.execution_id',
                'exceptions.external_user_id',
                'exceptions.group_hash',
                'exceptions.class',
                'exceptions.file',
                'exceptions.line',
                'exceptions.handled',
            ]);
    }

    private function jobAttemptQuery(array $filters): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_job_attempt_details as attempts')
            ->join('nw_executions as executions', 'executions.id', '=', 'attempts.execution_row_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->where('executions.occurred_at', '>=', $from)
            ->select([
                'executions.project_id',
                'attempts.job_id',
                'attempts.status',
                'executions.occurred_at',
                'executions.duration_us',
            ]);
    }

    private function commandQuery(array $filters): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_command_details as commands')
            ->join('nw_executions as executions', 'executions.id', '=', 'commands.execution_row_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->where('executions.occurred_at', '>=', $from)
            ->select([
                'executions.project_id',
                'executions.occurred_at',
                'executions.duration_us',
            ]);
    }

    private function cacheQuery(array $filters): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_cache_events as cache_events')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('cache_events.project_id', $projectId))
            ->where('cache_events.occurred_at', '>=', $from)
            ->select([
                'cache_events.project_id',
                'cache_events.cache_key',
                'cache_events.cache_event_type',
                'cache_events.occurred_at',
            ]);
    }

    private function userQuery(array $filters): Builder
    {
        return DB::table('nw_users')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->select([
                'project_id',
                'external_user_id',
                'name',
                'username',
                'last_seen_at',
            ]);
    }

    /**
     * @return array<int, array{row: array<string, mixed>, count: int, latest: string}>
     */
    private function topRouteItems(Collection $requests): Collection
    {
        return $requests
            ->groupBy(function (object $row): string {
                return implode('|', array_filter([
                    $row->method ?? '',
                    $row->route_name ?? '',
                    $row->route_path ?: $this->presenter->routeLabel($row->method, null, $row->url),
                ]));
            })
            ->map(function (Collection $group): array {
                $latest = $group->sortByDesc('occurred_at')->first();
                $durations = $group->pluck('duration_us')->all();
                $route = $this->presenter->routeLabel($latest->method, $latest->route_path, $latest->url);
                $meta = (string) ($latest->route_name ?: 'unnamed route');

                return [
                    'count' => $group->count(),
                    'latest' => (string) $latest->occurred_at,
                    'row' => [
                        'id' => sha1('dashboard-route|'.$route),
                        'route' => $this->presenter->cell($route, ['meta' => $meta]),
                        'requests' => $this->presenter->cell((string) $group->count()),
                        'avg' => $this->presenter->cell(
                            $this->presenter->duration((int) round($group->avg('duration_us') ?? 0)),
                        ),
                        'p95' => $this->presenter->cell(
                            $this->presenter->duration($this->presenter->percentile($durations, 0.95)),
                        ),
                    ],
                ];
            })
            ->sortByDesc(fn (array $item): string => str_pad((string) $item['count'], 6, '0', STR_PAD_LEFT).'|'.$item['latest'])
            ->values();
    }

    /**
     * @return array{counts: array<int, int>, avg: array<int, int>, p95: array<int, int>, from: string, to: string}
     */
    private function requestMinuteBuckets(Collection $requests): array
    {
        $buckets = $this->minuteBuckets($requests);

        if ($buckets->isEmpty()) {
            return [
                'counts' => [0],
                'avg' => [0],
                'p95' => [0],
                'from' => 'n/a',
                'to' => 'n/a',
            ];
        }

        $counts = $buckets->map(fn (Collection $group): int => $group->count())->values()->all();
        $avg = $buckets->map(fn (Collection $group): int => (int) round($group->avg('duration_us') ?? 0))->values()->all();
        $p95 = $buckets->map(
            fn (Collection $group): int => $this->presenter->percentile($group->pluck('duration_us')->all(), 0.95),
        )->values()->all();

        return [
            'counts' => $counts,
            'avg' => $avg,
            'p95' => $p95,
            ...$this->bucketLabels($buckets),
        ];
    }

    /**
     * @return array{processed: array<int, int>, failed: array<int, int>, from: string, to: string}
     */
    private function jobThroughputBuckets(Collection $jobAttempts): array
    {
        $buckets = $this->minuteBuckets($jobAttempts);

        if ($buckets->isEmpty()) {
            return [
                'processed' => [0],
                'failed' => [0],
                'from' => 'n/a',
                'to' => 'n/a',
            ];
        }

        return [
            'processed' => $buckets->map(
                fn (Collection $group): int => $group->where('status', 'processed')->count(),
            )->values()->all(),
            'failed' => $buckets->map(
                fn (Collection $group): int => $group->where('status', 'failed')->count(),
            )->values()->all(),
            ...$this->bucketLabels($buckets),
        ];
    }

    /**
     * @return Collection<string, Collection<int, object>>
     */
    private function minuteBuckets(Collection $rows): Collection
    {
        return $rows
            ->groupBy(
                fn (object $row): string => CarbonImmutable::parse($row->occurred_at)
                    ->timezone(config('app.timezone'))
                    ->format('Y-m-d H:i'),
            )
            ->sortKeys();
    }

    /**
     * @param  Collection<string, Collection<int, object>>  $buckets
     * @return array{from: string, to: string}
     */
    private function bucketLabels(Collection $buckets): array
    {
        $labels = $buckets->keys()->values();

        return [
            'from' => CarbonImmutable::parse($labels->first(), config('app.timezone'))->format('M j, H:i'),
            'to' => CarbonImmutable::parse($labels->last(), config('app.timezone'))->format('M j, H:i'),
        ];
    }

    /**
     * @return array{scope: string, projects: int, servers: int, deployments: int, last_seen: string}
     */
    private function infrastructureSummary(array $filters): array
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');
        $summary = DB::table('nw_executions')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->where('occurred_at', '>=', $from)
            ->selectRaw('count(distinct project_id) as projects')
            ->selectRaw('count(distinct server_id) as servers')
            ->selectRaw('count(distinct deployment_id) as deployments')
            ->selectRaw('max(occurred_at) as last_seen')
            ->first();

        $scope = 'All projects';

        if ($filters['project_id'] ?? null) {
            $scope = (string) DB::table('nw_projects')
                ->where('id', $filters['project_id'])
                ->value('name');
        }

        return [
            'scope' => $scope,
            'projects' => (int) ($summary->projects ?? 0),
            'servers' => (int) ($summary->servers ?? 0),
            'deployments' => (int) ($summary->deployments ?? 0),
            'last_seen' => $this->presenter->longTimestamp($summary->last_seen ?? null),
        ];
    }

    private function exceptionGroupKey(object $row): string
    {
        return implode('|', [
            $row->group_hash ?? '',
            $row->class ?? '',
            $row->file ?? '',
            $row->line ?? '',
        ]);
    }

    private function userKey(int $projectId, string $externalUserId): string
    {
        return $projectId.'|'.$externalUserId;
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
}
