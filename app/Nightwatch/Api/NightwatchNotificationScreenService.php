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

final class NightwatchNotificationScreenService
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
        $failedCount = $rows->where('failed', true)->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Notification events',
            'title' => 'Notifications',
            'subtitle' => 'Notification delivery groups from nw_notification_events with request-aware samples.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'EVENTS',
                    'caption' => sprintf('%d groups · %d total', $groups->count(), $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('failed '.$failedCount, $failedCount > 0 ? 'red' : 'green'),
                        $this->presenter->meta('channels '.$rows->pluck('channel')->filter()->unique()->count(), 'blue'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($rows->count() - $failedCount), 'tone' => 'green'],
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
                'title' => sprintf('%d Notification Groups', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep class, channel…',
                'columns' => [
                    ['key' => 'notification', 'label' => 'Notification', 'kind' => 'primary'],
                    ['key' => 'channel', 'label' => 'Channel', 'kind' => 'tone'],
                    ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_notification_events')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch notification group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Notification group hash [{$groupHash}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $events = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('notifications.group_hash', $groupHash)
            ->orderByDesc('notifications.occurred_at')
            ->get();

        $latest = $events->first();
        $durations = $events->pluck('duration_us')->all();
        $failedCount = $events->where('failed', true)->count();

        return [
            'eyebrow' => 'Notification detail',
            'title' => (string) $latest->class,
            'subtitle' => 'Grouped notification deliveries with source execution context.',
            'backTo' => $this->presenter->backTo('notifications'),
            'tags' => [
                [
                    'text' => (string) $latest->channel,
                    'tone' => $this->channelTone((string) $latest->channel),
                ],
                [
                    'text' => $failedCount > 0 ? 'failed' : 'successful',
                    'tone' => $failedCount > 0 ? 'red' : 'green',
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
                    'caption' => sprintf('%d deliveries', $events->count()),
                    'value' => (string) $events->count(),
                    'meta' => [
                        $this->presenter->meta('failed '.$failedCount, $failedCount > 0 ? 'red' : 'green'),
                        $this->presenter->meta('last '.$this->presenter->longTimestamp($latest->occurred_at), 'blue'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($events->count() - $failedCount), 'tone' => 'green'],
                        ['value' => (float) $failedCount, 'tone' => 'red'],
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
                        $this->presenter->info('Channel', (string) $latest->channel),
                        $this->presenter->info('Class', (string) $latest->class),
                        $this->presenter->info('Project', (string) $latest->project_name),
                        $this->presenter->info('Total events', (string) $events->count()),
                        $this->presenter->info('Failed', $failedCount > 0 ? 'Yes' : 'No'),
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
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'event', 'label' => 'Event', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $events->map(fn (object $event): array => [
                        'id' => (string) $event->id,
                        'event' => $this->presenter->cell(
                            $this->presenter->shortTimestamp($event->occurred_at),
                            ['meta' => trim($this->executionLabel($event).' · '.($event->execution_id ?: 'n/a'))],
                        ),
                        'status' => $this->presenter->cell(
                            $event->failed ? 'failed' : 'successful',
                            ['tone' => $event->failed ? 'red' : 'green'],
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

        return DB::table('nw_notification_events as notifications')
            ->join('nw_projects as projects', 'projects.id', '=', 'notifications.project_id')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'notifications.project_id')
                    ->on('executions.environment', '=', 'notifications.environment')
                    ->on('executions.execution_id', '=', 'notifications.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('notifications.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('notifications.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('notifications.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('notifications.class', 'like', $like)
                        ->orWhere('notifications.channel', 'like', $like)
                        ->orWhere('notifications.execution_id', 'like', $like)
                        ->orWhere('request_details.route_path', 'like', $like)
                        ->orWhere('request_details.url', 'like', $like);
                });
            })
            ->select([
                'notifications.id',
                'notifications.project_id',
                'notifications.environment',
                'notifications.group_hash',
                'notifications.occurred_at',
                'notifications.execution_id',
                'notifications.execution_source',
                'notifications.class',
                'notifications.channel',
                'notifications.duration_us',
                'notifications.failed',
                'projects.name as project_name',
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
                    'params' => ['screenKey' => 'notifications', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                        'environment' => $latest->environment,
                    ],
                ],
                'notification' => $this->presenter->cell(
                    (string) $latest->class,
                    ['meta' => $this->executionLabel($latest)],
                ),
                'channel' => $this->presenter->cell(
                    (string) $latest->channel,
                    ['tone' => $this->channelTone((string) $latest->channel)],
                ),
                'duration' => $this->presenter->cell(
                    $this->presenter->duration((int) round($group->avg('duration_us') ?? 0)),
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

    private function channelTone(string $channel): string
    {
        return match ($channel) {
            'database' => 'sky',
            'mail' => 'yellow',
            'slack', 'broadcast' => 'mauve',
            default => 'blue',
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
