<?php

namespace App\Nightwatch\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function max;
use function min;
use function parse_url;
use function round;
use function sprintf;

final class NightwatchOutgoingRequestScreenService
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
        $durations = $rows->pluck('duration_us')->all();
        $statusCodes = $rows->pluck('status_code');

        return [
            'kind' => 'collection',
            'eyebrow' => 'External traffic',
            'title' => 'Outgoing Requests',
            'subtitle' => 'Host-level outbound visibility from nw_outgoing_requests with execution-aware samples.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'REQUESTS',
                    'caption' => sprintf('%d hosts · %d total', $groups->count(), $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('4xx '.$statusCodes->filter(fn ($status): bool => $status >= 400 && $status < 500)->count(), 'yellow'),
                        $this->presenter->meta('5xx '.$statusCodes->filter(fn ($status): bool => $status >= 500)->count(), 'red'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $statusCodes->filter(fn ($status): bool => $status < 400)->count(), 'tone' => 'green'],
                        ['value' => (float) $statusCodes->filter(fn ($status): bool => $status >= 400 && $status < 500)->count(), 'tone' => 'yellow'],
                        ['value' => (float) $statusCodes->filter(fn ($status): bool => $status >= 500)->count(), 'tone' => 'red'],
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
                'title' => sprintf('%d Domains', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep host, method…',
                'columns' => [
                    ['key' => 'domain', 'label' => 'Domain', 'kind' => 'primary'],
                    ['key' => 'requests', 'label' => 'Requests'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                    ['key' => 'status', 'label' => 'Last status', 'kind' => 'tone'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_outgoing_requests')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->select(['project_id'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch outgoing request group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Outgoing request group hash [{$groupHash}] exists in multiple projects. Pass project_id.",
            );
        }

        $calls = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
        ], applyRange: false)
            ->where('outgoing.group_hash', $groupHash)
            ->orderByDesc('outgoing.occurred_at')
            ->get();

        $latest = $calls->first();
        $durations = $calls->pluck('duration_us')->all();

        return [
            'eyebrow' => 'Outgoing request detail',
            'title' => (string) ($latest->host ?: $latest->url),
            'subtitle' => 'Grouped outbound calls enriched with request context.',
            'backTo' => $this->presenter->backTo('outgoing-requests'),
            'tags' => [
                [
                    'text' => (string) $latest->method,
                    'tone' => 'sky',
                ],
                [
                    'text' => (string) $latest->status_code,
                    'tone' => $this->presenter->statusCodeTone((int) $latest->status_code),
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'REQUESTS',
                    'caption' => sprintf('%d total', $calls->count()),
                    'value' => (string) $calls->count(),
                    'meta' => [
                        $this->presenter->meta('request '.$this->presenter->bytes((int) $latest->request_bytes), 'blue'),
                        $this->presenter->meta('response '.$this->presenter->bytes((int) $latest->response_bytes), 'sky'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $latest->request_bytes, 'tone' => 'blue'],
                        ['value' => (float) $latest->response_bytes, 'tone' => 'sky'],
                        ['value' => (float) $latest->duration_us, 'tone' => 'yellow'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $this->presenter->duration((int) round($calls->avg('duration_us') ?? 0)),
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
                        $this->presenter->info('URL', (string) $latest->url),
                        $this->presenter->info('Method', (string) $latest->method),
                        $this->presenter->info('Status code', (string) $latest->status_code),
                        $this->presenter->info('Request bytes', $this->presenter->bytes((int) $latest->request_bytes)),
                        $this->presenter->info('Response bytes', $this->presenter->bytes((int) $latest->response_bytes)),
                    ],
                ],
            ],
            'timeline' => null,
            'tables' => [
                [
                    'title' => 'Related Calls',
                    'caption' => '',
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'call', 'label' => 'Call', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $calls->map(fn (object $call): array => [
                        'id' => (string) $call->id,
                        'call' => $this->presenter->cell(
                            $this->presenter->longTimestamp($call->occurred_at),
                            ['meta' => trim($this->executionLabel($call).' · '.($call->execution_id ?: 'n/a'))],
                        ),
                        'status' => $this->presenter->cell(
                            (string) $call->status_code,
                            ['tone' => $this->presenter->statusCodeTone((int) $call->status_code)],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $call->duration_us)),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_outgoing_requests as outgoing')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'outgoing.project_id')
                    ->on('executions.execution_id', '=', 'outgoing.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('outgoing.project_id', $projectId))
            ->when($applyRange, fn (Builder $query) => $query->where('outgoing.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('outgoing.host', 'like', $like)
                        ->orWhere('outgoing.method', 'like', $like)
                        ->orWhere('outgoing.url', 'like', $like)
                        ->orWhere('outgoing.execution_id', 'like', $like)
                        ->orWhere('request_details.route_path', 'like', $like)
                        ->orWhere('request_details.url', 'like', $like);
                });
            })
            ->select([
                'outgoing.id',
                'outgoing.project_id',
                'outgoing.group_hash',
                'outgoing.occurred_at',
                'outgoing.execution_id',
                'outgoing.execution_source',
                'outgoing.host',
                'outgoing.method',
                'outgoing.url',
                'outgoing.status_code',
                'outgoing.duration_us',
                'outgoing.request_bytes',
                'outgoing.response_bytes',
                'executions.preview as execution_label',
                'request_details.method as request_method',
                'request_details.route_path as request_route_path',
                'request_details.url as request_url',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();
        $path = (string) (parse_url((string) $latest->url, PHP_URL_PATH) ?: '/');

        return [
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'outgoing-requests', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                    ],
                ],
                'domain' => $this->presenter->cell(
                    (string) ($latest->host ?: 'unknown'),
                    ['meta' => trim($latest->method.' '.$path)],
                ),
                'requests' => $this->presenter->cell((string) $group->count()),
                'avg' => $this->presenter->cell($this->presenter->duration((int) round($group->avg('duration_us') ?? 0))),
                'status' => $this->presenter->cell(
                    (string) $latest->status_code,
                    ['tone' => $this->presenter->statusCodeTone((int) $latest->status_code)],
                ),
            ],
        ];
    }

    private function executionLabel(object $call): string
    {
        if ($call->execution_label) {
            return (string) $call->execution_label;
        }

        if ($call->request_method || $call->request_route_path || $call->request_url) {
            return $this->presenter->routeLabel(
                $call->request_method,
                $call->request_route_path,
                $call->request_url,
            );
        }

        return (string) ($call->execution_source ?: 'n/a');
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
