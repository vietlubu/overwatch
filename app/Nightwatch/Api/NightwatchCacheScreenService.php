<?php

namespace App\Nightwatch\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function max;
use function min;
use function round;
use function sprintf;

final class NightwatchCacheScreenService
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
                $row->group_hash,
            ]))
            ->map(fn (Collection $group): array => $this->mapListGroup($group))
            ->sortByDesc(fn (array $group): string => $group['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($groups, $page, $perPage);
        $durations = $rows->pluck('duration_us')->all();
        $failureCount = $rows->filter(fn (object $row): bool => $this->isFailureType((string) $row->cache_event_type))->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Cache events',
            'title' => 'Cache',
            'subtitle' => 'Cache key groups from nw_cache_events with request-aware samples.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'EVENTS',
                    'caption' => sprintf('%d keys · %d total', $groups->count(), $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('writes '.$rows->where('cache_event_type', 'write')->count(), 'blue'),
                        $this->presenter->meta('misses '.$rows->where('cache_event_type', 'miss')->count(), 'yellow'),
                        $this->presenter->meta('failures '.$failureCount, $failureCount > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $rows->where('cache_event_type', 'miss')->count(), 'tone' => 'yellow'],
                        ['value' => (float) $rows->where('cache_event_type', 'write')->count(), 'tone' => 'blue'],
                        ['value' => (float) $failureCount, 'tone' => 'red'],
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
                'title' => sprintf('%d Cache Keys', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep cache key, store…',
                'columns' => [
                    ['key' => 'key', 'label' => 'Key', 'kind' => 'primary'],
                    ['key' => 'store', 'label' => 'Store'],
                    ['key' => 'events', 'label' => 'Events'],
                    ['key' => 'type', 'label' => 'Last type', 'kind' => 'tone'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_cache_events')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch cache group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Cache group hash [{$groupHash}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $events = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('cache.group_hash', $groupHash)
            ->orderByDesc('cache.occurred_at')
            ->get();

        $latest = $events->first();
        $durations = $events->pluck('duration_us')->all();
        $failureCount = $events->filter(fn (object $row): bool => $this->isFailureType((string) $row->cache_event_type))->count();

        return [
            'eyebrow' => 'Cache detail',
            'title' => (string) $latest->cache_key,
            'subtitle' => 'Grouped cache activity with recent event samples and source execution context.',
            'backTo' => $this->presenter->backTo('cache'),
            'tags' => [
                [
                    'text' => (string) ($latest->store ?: 'default'),
                    'tone' => 'sky',
                ],
                [
                    'text' => (string) $latest->cache_event_type,
                    'tone' => $this->cacheTypeTone((string) $latest->cache_event_type),
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'environment' => $latest->environment,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'EVENTS',
                    'caption' => sprintf('%d events', $events->count()),
                    'value' => (string) $events->count(),
                    'meta' => [
                        $this->presenter->meta('ttl '.$this->ttlLabel((int) $latest->ttl_seconds), 'blue'),
                        $this->presenter->meta('failures '.$failureCount, $failureCount > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $events->where('cache_event_type', 'hit')->count(), 'tone' => 'green'],
                        ['value' => (float) $events->where('cache_event_type', 'miss')->count(), 'tone' => 'yellow'],
                        ['value' => (float) $events->where('cache_event_type', 'write')->count(), 'tone' => 'blue'],
                        ['value' => (float) $failureCount, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $this->presenter->duration((int) round($events->avg('duration_us') ?? 0)),
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
                        $this->presenter->info('Store', (string) ($latest->store ?: 'default')),
                        $this->presenter->info('TTL', $this->ttlLabel((int) $latest->ttl_seconds)),
                        $this->presenter->info('Events', (string) $events->count()),
                        $this->presenter->info('Failures', (string) $failureCount),
                    ],
                ],
                [
                    'title' => 'Source',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Last execution', (string) ($latest->execution_id ?: 'n/a')),
                        $this->presenter->info('Execution type', (string) ($latest->execution_source ?: 'n/a')),
                        $this->presenter->info('Last call', $this->executionLabel($latest)),
                    ],
                ],
            ],
            'timeline' => null,
            'tables' => [
                [
                    'title' => 'Recent Events',
                    'caption' => '',
                    'searchPlaceholder' => 'grep event type…',
                    'columns' => [
                        ['key' => 'event', 'label' => 'Event', 'kind' => 'primary'],
                        ['key' => 'type', 'label' => 'Type', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $events->map(fn (object $event): array => [
                        'id' => (string) $event->id,
                        'event' => $this->presenter->cell(
                            $this->presenter->shortTimestamp($event->occurred_at),
                            ['meta' => trim($this->executionLabel($event).' · '.($event->execution_id ?: 'n/a'))],
                        ),
                        'type' => $this->presenter->cell(
                            (string) $event->cache_event_type,
                            ['tone' => $this->cacheTypeTone((string) $event->cache_event_type)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $event->duration_us)),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_cache_events as cache')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'cache.project_id')
                    ->on('executions.environment', '=', 'cache.environment')
                    ->on('executions.execution_id', '=', 'cache.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('cache.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('cache.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('cache.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('cache.cache_key', 'like', $like)
                        ->orWhere('cache.store', 'like', $like)
                        ->orWhere('cache.cache_event_type', 'like', $like)
                        ->orWhere('cache.execution_id', 'like', $like)
                        ->orWhere('request_details.route_path', 'like', $like)
                        ->orWhere('request_details.url', 'like', $like);
                });
            })
            ->select([
                'cache.id',
                'cache.project_id',
                'cache.environment',
                'cache.group_hash',
                'cache.occurred_at',
                'cache.execution_id',
                'cache.execution_source',
                'cache.store',
                'cache.cache_key',
                'cache.cache_event_type',
                'cache.duration_us',
                'cache.ttl_seconds',
                'executions.preview as execution_label',
                'request_details.method as request_method',
                'request_details.route_path as request_route_path',
                'request_details.url as request_url',
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
                    'params' => ['screenKey' => 'cache', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                        'environment' => $latest->environment,
                    ],
                ],
                'key' => $this->presenter->cell(
                    (string) $latest->cache_key,
                    ['meta' => 'ttl '.$this->ttlLabel((int) $latest->ttl_seconds)],
                ),
                'store' => $this->presenter->cell((string) ($latest->store ?: 'default')),
                'events' => $this->presenter->cell((string) $group->count()),
                'type' => $this->presenter->cell(
                    (string) $latest->cache_event_type,
                    ['tone' => $this->cacheTypeTone((string) $latest->cache_event_type)],
                ),
            ],
        ];
    }

    private function executionLabel(object $event): string
    {
        if ($event->execution_label) {
            return (string) $event->execution_label;
        }

        if ($event->request_method || $event->request_route_path || $event->request_url) {
            return $this->presenter->routeLabel(
                $event->request_method,
                $event->request_route_path,
                $event->request_url,
            );
        }

        return (string) ($event->execution_source ?: 'n/a');
    }

    private function cacheTypeTone(string $type): string
    {
        return match ($type) {
            'hit' => 'green',
            'miss' => 'yellow',
            'write' => 'blue',
            'delete' => 'mauve',
            default => 'red',
        };
    }

    private function isFailureType(string $type): bool
    {
        return $type === 'write-failure' || $type === 'delete-failure';
    }

    private function ttlLabel(int $seconds): string
    {
        return $seconds > 0 ? $seconds.'s' : 'none';
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
