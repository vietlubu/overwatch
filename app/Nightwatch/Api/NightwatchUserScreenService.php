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
use function str_pad;

final class NightwatchUserScreenService
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
        $users = $this->userQuery($filters)->get();
        $requests = $this->requestQuery($filters, applyRange: true)->get();
        $exceptions = $this->exceptionQuery($filters, applyRange: true)->get();

        $requestsByUser = $requests->groupBy(fn (object $row): string => $this->userKey($row->project_id, $row->environment, $row->external_user_id));
        $exceptionCounts = $exceptions
            ->groupBy(fn (object $row): string => $this->userKey($row->project_id, $row->environment, $row->external_user_id))
            ->map(fn (Collection $rows): int => $rows->count());

        $items = $users
            ->map(function (object $user) use ($requestsByUser, $exceptionCounts): array {
                $key = $this->userKey($user->project_id, $user->environment, $user->external_user_id);
                $userRequests = $requestsByUser->get($key, collect());
                $exceptionCount = (int) ($exceptionCounts->get($key) ?? 0);

                return $this->mapListUser($user, $userRequests, $exceptionCount);
            })
            ->sortByDesc('sort_key')
            ->values();

        $pagination = $this->presenter->paginate($items, $page, $perPage);

        return [
            'kind' => 'collection',
            'eyebrow' => 'Users',
            'title' => 'Users',
            'subtitle' => 'User dimension list from nw_users enriched with request and exception activity.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'AUTHENTICATED USERS',
                    'caption' => sprintf('%d distinct', $users->count()),
                    'value' => (string) $users->count(),
                    'meta' => [
                        $this->presenter->meta('requests '.$requests->count(), 'blue'),
                        $this->presenter->meta('exceptions '.$exceptions->count(), $exceptions->count() > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $users->filter(fn (object $user): bool => $requestsByUser->has($this->userKey($user->project_id, $user->environment, $user->external_user_id)))->count(), 'tone' => 'green'],
                        ['value' => (float) $users->filter(fn (object $user): bool => ! $requestsByUser->has($this->userKey($user->project_id, $user->environment, $user->external_user_id)))->count(), 'tone' => 'yellow'],
                    ]),
                ],
                [
                    'label' => 'REQUESTS',
                    'caption' => sprintf('%d active / %d idle', $requestsByUser->count(), max(0, $users->count() - $requestsByUser->count())),
                    'value' => (string) $requests->count(),
                    'meta' => [
                        $this->presenter->meta('active', 'green'),
                        $this->presenter->meta('idle', 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars(
                        $items->map(fn (array $item): int => $item['request_count'])->all() ?: [0],
                        'green',
                    ),
                ],
            ],
            'table' => [
                'title' => sprintf('%d Users', $users->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep id, name, username…',
                'columns' => [
                    ['key' => 'user', 'label' => 'User', 'kind' => 'primary'],
                    ['key' => 'requests', 'label' => 'Requests'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                    ['key' => 'exceptions', 'label' => 'Exceptions', 'kind' => 'tone'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $externalUserId, array $filters): array
    {
        $scopes = DB::table('nw_users')
            ->where('external_user_id', $externalUserId)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch user [{$externalUserId}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "User [{$externalUserId}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $user = DB::table('nw_users')
            ->where('project_id', $scopes->first()->project_id)
            ->where('environment', $scopes->first()->environment)
            ->where('external_user_id', $externalUserId)
            ->first();

        $requests = $this->requestQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('executions.external_user_id', $externalUserId)
            ->orderByDesc('executions.occurred_at')
            ->get();

        $exceptions = $this->exceptionQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('exceptions.external_user_id', $externalUserId)
            ->get();

        $durations = $requests->pluck('duration_us')->all();
        $topRoutes = $requests
            ->groupBy(fn (object $row): string => implode('|', [
                $row->method,
                $row->route_name ?? '',
                $row->route_path ?: ($row->url ?? ''),
            ]))
            ->map(function (Collection $group): array {
                $latest = $group->sortByDesc('occurred_at')->first();

                return [
                    'count' => $group->count(),
                    'row' => [
                        'id' => sha1((string) $latest->execution_id.'route'),
                        'route' => $this->presenter->cell(
                            $this->presenter->routeLabel($latest->method, $latest->route_path, $latest->url),
                            ['meta' => (string) ($latest->route_name ?: 'n/a')],
                        ),
                        'requests' => $this->presenter->cell((string) $group->count()),
                        'avg' => $this->presenter->cell(
                            $this->presenter->duration((int) round($group->avg('duration_us') ?? 0)),
                        ),
                    ],
                ];
            })
            ->sortByDesc('count')
            ->values();

        return [
            'eyebrow' => 'User detail',
            'title' => (string) ($user->name ?: $user->username ?: $user->external_user_id),
            'subtitle' => 'User-centric slice over nw_users, request activity, and exception activity.',
            'backTo' => $this->presenter->backTo('users'),
            'tags' => [
                [
                    'text' => $requests->count().' requests',
                    'tone' => 'green',
                ],
                [
                    'text' => $exceptions->count().' exceptions',
                    'tone' => $exceptions->count() > 0 ? 'red' : 'sky',
                ],
            ],
            'scope' => [
                'project_id' => $user->project_id,
                'environment' => $user->environment,
                'external_user_id' => $externalUserId,
            ],
            'metrics' => [
                [
                    'label' => 'REQUESTS',
                    'caption' => 'scoped activity',
                    'value' => (string) $requests->count(),
                    'meta' => [
                        $this->presenter->meta('5xx '.$requests->filter(fn (object $row): bool => (int) $row->status_code >= 500)->count(), $requests->filter(fn (object $row): bool => (int) $row->status_code >= 500)->count() > 0 ? 'red' : 'green'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $requests->filter(fn (object $row): bool => (int) $row->status_code < 400)->count(), 'tone' => 'green'],
                        ['value' => (float) $requests->filter(fn (object $row): bool => (int) $row->status_code >= 400 && (int) $row->status_code < 500)->count(), 'tone' => 'yellow'],
                        ['value' => (float) $requests->filter(fn (object $row): bool => (int) $row->status_code >= 500)->count(), 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => 'request avg',
                    'value' => $requests->isEmpty() ? 'n/a' : $this->presenter->duration((int) round($requests->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('p95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars($durations === [] ? [0] : $durations, 'yellow'),
                ],
            ],
            'summaryPanels' => [
                [
                    'title' => 'Info',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Name', (string) ($user->name ?: 'n/a')),
                        $this->presenter->info('External id', (string) $user->external_user_id),
                        $this->presenter->info('Username', (string) ($user->username ?: 'n/a')),
                        $this->presenter->info('Last seen', $this->presenter->longTimestamp($user->last_seen_at)),
                        $this->presenter->info('First seen', $this->presenter->longTimestamp($user->first_seen_at)),
                    ],
                ],
            ],
            'timeline' => null,
            'tables' => [
                [
                    'title' => 'Top Routes',
                    'caption' => '',
                    'searchPlaceholder' => 'grep route…',
                    'columns' => [
                        ['key' => 'route', 'label' => 'Route', 'kind' => 'primary'],
                        ['key' => 'requests', 'label' => 'Requests'],
                        ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                    ],
                    'rows' => $topRoutes->map(fn (array $item): array => $item['row'])->all(),
                ],
                [
                    'title' => 'Recent Requests',
                    'caption' => '',
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'request', 'label' => 'Request', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $requests->take(10)->map(fn (object $request): array => [
                        'id' => (string) $request->execution_id,
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'requests', 'detailId' => $request->execution_id],
                            'query' => [
                                'project_id' => (string) $request->project_id,
                                'environment' => $request->environment,
                            ],
                        ],
                        'request' => $this->presenter->cell(
                            $this->presenter->routeLabel($request->method, $request->route_path, $request->url),
                            ['meta' => $this->presenter->shortTimestamp($request->occurred_at)],
                        ),
                        'status' => $this->presenter->cell(
                            (string) $request->status_code,
                            ['tone' => $this->presenter->statusCodeTone((int) $request->status_code)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $request->duration_us)),
                    ])->all(),
                ],
            ],
        ];
    }

    private function userQuery(array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        return DB::table('nw_users')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('external_user_id', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('username', 'like', $like);
                });
            })
            ->select([
                'id',
                'project_id',
                'environment',
                'external_user_id',
                'name',
                'username',
                'first_seen_at',
                'last_seen_at',
            ]);
    }

    private function requestQuery(array $filters, bool $applyRange): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_executions as executions')
            ->join('nw_request_details as details', 'details.execution_row_id', '=', 'executions.id')
            ->where('executions.source', 'request')
            ->whereNotNull('executions.external_user_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('executions.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('executions.occurred_at', '>=', $from))
            ->select([
                'executions.project_id',
                'executions.environment',
                'executions.external_user_id',
                'executions.execution_id',
                'executions.occurred_at',
                'executions.duration_us',
                'details.method',
                'details.url',
                'details.route_name',
                'details.route_path',
                'details.status_code',
            ]);
    }

    private function exceptionQuery(array $filters, bool $applyRange): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_exceptions as exceptions')
            ->whereNotNull('exceptions.external_user_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('exceptions.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('exceptions.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('exceptions.occurred_at', '>=', $from))
            ->select([
                'exceptions.project_id',
                'exceptions.environment',
                'exceptions.external_user_id',
            ]);
    }

    private function mapListUser(object $user, Collection $requests, int $exceptionCount): array
    {
        $requestCount = $requests->count();

        return [
            'request_count' => $requestCount,
            'sort_key' => str_pad((string) $requestCount, 10, '0', STR_PAD_LEFT).'|'.$user->last_seen_at,
            'row' => [
                'id' => (string) $user->external_user_id,
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'users', 'detailId' => $user->external_user_id],
                    'query' => [
                        'project_id' => (string) $user->project_id,
                        'environment' => $user->environment,
                    ],
                ],
                'user' => $this->presenter->cell(
                    (string) ($user->name ?: $user->username ?: $user->external_user_id),
                    ['meta' => (string) ($user->username ?: $user->external_user_id)],
                ),
                'requests' => $this->presenter->cell((string) $requestCount),
                'avg' => $this->presenter->cell(
                    $requests->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) round($requests->avg('duration_us') ?? 0)),
                ),
                'exceptions' => $this->presenter->cell(
                    (string) $exceptionCount,
                    ['tone' => $exceptionCount > 0 ? 'red' : 'green'],
                ),
            ],
        ];
    }

    private function userKey(int $projectId, string $environment, string $externalUserId): string
    {
        return $projectId.'|'.$environment.'|'.$externalUserId;
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
