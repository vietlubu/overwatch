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

final class NightwatchQueryScreenService
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

        return [
            'kind' => 'collection',
            'eyebrow' => 'Database activity',
            'title' => 'Queries',
            'subtitle' => 'Grouped SQL performance aligned with nw_query_group_1m and individual samples from nw_queries.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'CALLS',
                    'caption' => sprintf('%d total calls', $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('avg '.$this->presenter->duration((int) round($rows->avg('duration_us') ?? 0)), 'blue'),
                        $this->presenter->meta('p95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars(
                        $groups->map(fn (array $group): int => $group['count'])->all() ?: [0],
                        'blue',
                    ),
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
                'title' => sprintf('%d Queries', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep sql, table, file…',
                'columns' => [
                    ['key' => 'query', 'label' => 'Query', 'kind' => 'primary'],
                    ['key' => 'connection', 'label' => 'Connection'],
                    ['key' => 'calls', 'label' => 'Calls'],
                    ['key' => 'avg', 'label' => 'Avg', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_queries')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch query group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Query group hash [{$groupHash}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $samples = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
            'environment' => $scopes->first()->environment,
        ], applyRange: false)
            ->where('queries.group_hash', $groupHash)
            ->orderByDesc('queries.occurred_at')
            ->get();

        $latest = $samples->first();
        $durations = $samples->pluck('duration_us')->all();
        $totalDuration = (int) $samples->sum('duration_us');

        return [
            'eyebrow' => 'Query detail',
            'title' => (string) $latest->sql,
            'subtitle' => 'Future detail endpoint can blend one grouped query with example samples and related request contexts.',
            'backTo' => $this->presenter->backTo('queries'),
            'tags' => [
                [
                    'text' => (string) $latest->connection_type,
                    'tone' => $latest->connection_type === 'write' ? 'yellow' : 'blue',
                ],
                [
                    'text' => (string) ($latest->connection ?: 'unknown'),
                    'tone' => 'blue',
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'environment' => $latest->environment,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'CALLS',
                    'caption' => sprintf('%d calls', $samples->count()),
                    'value' => (string) $samples->count(),
                    'meta' => [
                        $this->presenter->meta('total '.$this->presenter->duration($totalDuration), 'blue'),
                    ],
                    'bars' => $this->presenter->sparkBars($durations === [] ? [0] : $durations, 'blue'),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $this->presenter->duration((int) round($samples->avg('duration_us') ?? 0)),
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
                        $this->presenter->info('File', (string) ($latest->file ?: 'n/a')),
                        $this->presenter->info('Connection', (string) ($latest->connection ?: 'unknown')),
                        $this->presenter->info('Type', (string) $latest->connection_type),
                        $this->presenter->info('Total time', $this->presenter->duration($totalDuration)),
                        $this->presenter->info('P95', $this->presenter->duration($this->presenter->percentile($durations, 0.95))),
                    ],
                ],
            ],
            'timeline' => null,
            'codePanels' => [
                [
                    'title' => 'Normalized SQL',
                    'code' => (string) $latest->sql,
                ],
            ],
            'tables' => [
                [
                    'title' => 'Sample Calls',
                    'caption' => '',
                    'searchPlaceholder' => 'grep request id…',
                    'columns' => [
                        ['key' => 'call', 'label' => 'Call', 'kind' => 'primary'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                        ['key' => 'connection', 'label' => 'Connection'],
                    ],
                    'rows' => $samples->map(fn (object $sample): array => [
                        'id' => (string) $sample->id,
                        'call' => $this->presenter->cell(
                            $this->presenter->shortTimestamp($sample->occurred_at),
                            ['meta' => trim($this->executionLabel($sample).' · '.($sample->execution_id ?: 'n/a'))],
                        ),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $sample->duration_us)),
                        'connection' => $this->presenter->cell(
                            trim(($sample->connection ?: 'unknown').' / '.$sample->connection_type),
                        ),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_queries as queries')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'queries.project_id')
                    ->on('executions.environment', '=', 'queries.environment')
                    ->on('executions.execution_id', '=', 'queries.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('queries.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('queries.environment', $environment))
            ->when($applyRange, fn (Builder $query) => $query->where('queries.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('queries.sql', 'like', $like)
                        ->orWhere('queries.file', 'like', $like)
                        ->orWhere('queries.connection', 'like', $like)
                        ->orWhere('queries.connection_type', 'like', $like)
                        ->orWhere('queries.execution_id', 'like', $like);
                });
            })
            ->select([
                'queries.id',
                'queries.project_id',
                'queries.environment',
                'queries.group_hash',
                'queries.occurred_at',
                'queries.execution_id',
                'queries.sql',
                'queries.file',
                'queries.line',
                'queries.duration_us',
                'queries.connection',
                'queries.connection_type',
                'executions.preview as execution_label',
                'request_details.method as request_method',
                'request_details.route_path as request_route_path',
                'request_details.url as request_url',
            ]);
    }

    private function mapListGroup(Collection $group): array
    {
        $latest = $group->sortByDesc('occurred_at')->first();
        $durations = $group->pluck('duration_us')->all();

        return [
            'count' => $group->count(),
            'sort_at' => (string) $latest->occurred_at,
            'row' => [
                'id' => sha1($latest->project_id.'|'.$latest->environment.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'queries', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                        'environment' => $latest->environment,
                    ],
                ],
                'query' => $this->presenter->cell(
                    $this->truncateSql((string) $latest->sql),
                    ['meta' => trim(($latest->file ?: 'n/a').($latest->line ? ':'.$latest->line : ''))],
                ),
                'connection' => $this->presenter->cell(
                    trim(($latest->connection ?: 'unknown').' / '.$latest->connection_type),
                ),
                'calls' => $this->presenter->cell((string) $group->count()),
                'avg' => $this->presenter->cell(
                    $this->presenter->duration((int) round($group->avg('duration_us') ?? 0)),
                ),
            ],
        ];
    }

    private function truncateSql(string $sql): string
    {
        return mb_strlen($sql) > 120 ? mb_substr($sql, 0, 117).'...' : $sql;
    }

    private function executionLabel(object $sample): string
    {
        if ($sample->execution_label) {
            return (string) $sample->execution_label;
        }

        if ($sample->request_method || $sample->request_route_path || $sample->request_url) {
            return $this->presenter->routeLabel(
                $sample->request_method,
                $sample->request_route_path,
                $sample->request_url,
            );
        }

        return 'n/a';
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
