<?php

namespace App\Nightwatch\Api;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_filter;
use function array_map;
use function count;
use function hash;
use function in_array;
use function json_encode;
use function max;
use function parse_url;
use function sprintf;
use function str_starts_with;
use function substr;

final class NightwatchIssueScreenService
{
    public function __construct(
        private readonly NightwatchScreenPresenter $presenter,
        private readonly NightwatchExceptionScreenService $exceptionService,
    ) {
        //
    }

    public function index(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);

        $exceptionIssues = $this->mapExceptionIssues($this->exceptionIssueQuery($filters, applyRange: true)->get());
        $logIssues = $this->mapLogIssues($this->logIssueQuery($filters, applyRange: true)->get());
        $issues = $exceptionIssues->concat($logIssues)->values();

        $stateMap = $this->issueStateMap($issues);
        $openIssues = $issues
            ->filter(fn (array $issue): bool => ! $this->isResolvedIssue($issue, $stateMap))
            ->sortByDesc(fn (array $issue): string => $issue['sort_at'])
            ->values();

        $pagination = $this->presenter->paginate($openIssues, $page, $perPage);
        $latest = $openIssues->first();
        $impactedUsers = $openIssues
            ->flatMap(fn (array $issue): array => $issue['user_keys'])
            ->unique()
            ->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Open issues',
            'title' => 'Issues',
            'subtitle' => 'Operator-first queue of unresolved exception groups and error log groups.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'OPEN',
                    'caption' => 'active issue groups',
                    'value' => (string) $openIssues->count(),
                    'meta' => [
                        $this->presenter->meta('unresolved', 'red'),
                        $this->presenter->meta('impacted users '.$impactedUsers, 'sky'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $openIssues->count(), 'tone' => 'red'],
                        ['value' => (float) max(0, $impactedUsers), 'tone' => 'sky'],
                    ]),
                ],
                [
                    'label' => 'LAST SEEN',
                    'caption' => $latest ? $this->presenter->longTimestamp($latest['latest_occurred_at']) : 'n/a',
                    'value' => $latest ? $this->presenter->duration($latest['latest_duration_us']) : 'n/a',
                    'meta' => [
                        $this->presenter->meta(
                            $latest ? ($latest['latest_meta'] ?? 'latest runtime') : 'latest runtime',
                            $latest ? ($latest['severity_tone'] ?? 'yellow') : 'yellow',
                        ),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($latest ? ($latest['latest_duration_us'] ?? 0) : 0), 'tone' => $latest ? ($latest['severity_tone'] ?? 'yellow') : 'yellow'],
                        ['value' => (float) ($latest ? ($latest['count'] ?? 0) : 0), 'tone' => 'mauve'],
                    ]),
                ],
            ],
            'table' => [
                'title' => 'Open issues',
                'caption' => 'Resolved issues stay addressable by detail URL and reopen automatically when they recur.',
                'searchPlaceholder' => 'grep class, route, user…',
                'columns' => [
                    ['key' => 'issue', 'label' => 'Issue', 'kind' => 'primary'],
                    ['key' => 'severity', 'label' => 'Severity', 'kind' => 'tone'],
                    ['key' => 'lastSeen', 'label' => 'Last seen', 'kind' => 'code'],
                    ['key' => 'count', 'label' => 'Count'],
                ],
                'rows' => $pagination['items']->map(fn (array $issue): array => $issue['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $issueKey, array $filters): array
    {
        ['source_type' => $sourceType, 'source_key' => $sourceKey] = $this->parseIssueKey($issueKey);
        $projectId = $this->resolveScopedProjectId($sourceType, $sourceKey, $filters['project_id'] ?? null);

        return $sourceType === 'exception'
            ? $this->showExceptionIssue($issueKey, $sourceKey, $projectId)
            : $this->showLogIssue($issueKey, $sourceKey, $projectId);
    }

    public function resolve(string $issueKey, array $filters): array
    {
        ['source_type' => $sourceType, 'source_key' => $sourceKey] = $this->parseIssueKey($issueKey);
        $projectId = $this->resolveScopedProjectId($sourceType, $sourceKey, $filters['project_id'] ?? null);

        $latestOccurredAt = $sourceType === 'exception'
            ? $this->exceptionIssueQuery(['project_id' => $projectId], applyRange: false)
                ->where('exceptions.group_hash', $sourceKey)
                ->max('exceptions.occurred_at')
            : $this->logIssueRows($sourceKey, $projectId)->max('occurred_at');

        if ($latestOccurredAt === null) {
            throw new NotFoundHttpException("Nightwatch issue [{$issueKey}] was not found.");
        }

        DB::table('nw_issue_states')->updateOrInsert(
            [
                'project_id' => $projectId,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
            ],
            [
                'resolved_at' => now(),
                'resolved_through_occurred_at' => $latestOccurredAt,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->show($issueKey, ['project_id' => $projectId]);
    }

    private function showExceptionIssue(string $issueKey, string $groupHash, int $projectId): array
    {
        $payload = $this->exceptionService->show($groupHash, ['project_id' => $projectId]);
        $latestOccurredAt = $this->exceptionIssueQuery(['project_id' => $projectId], applyRange: false)
            ->where('exceptions.group_hash', $groupHash)
            ->max('exceptions.occurred_at');

        $state = $this->issueState($projectId, 'exception', $groupHash);
        $resolved = $latestOccurredAt !== null && $this->isResolvedAt($latestOccurredAt, $state);

        return [
            ...$payload,
            'eyebrow' => 'Issue detail',
            'subtitle' => 'Issue view for an exception group, with operator-managed resolution state.',
            'backTo' => $this->presenter->backTo('issues'),
            'tags' => array_values(array_filter([
                ['text' => $resolved ? 'Resolved' : 'Open', 'tone' => $resolved ? 'green' : 'red'],
                ['text' => 'Exception issue', 'tone' => 'mauve'],
                ...$this->detailTagsWithoutHandled($payload['tags'] ?? []),
            ])),
            'scope' => [
                ...($payload['scope'] ?? []),
                'issue_key' => $issueKey,
                'source_type' => 'exception',
                'source_key' => $groupHash,
                'resolved' => $resolved,
            ],
            'summaryPanels' => $this->appendResolutionPanel($payload['summaryPanels'] ?? [], $state),
            'actions' => $resolved ? [] : [$this->resolveAction($issueKey)],
        ];
    }

    private function showLogIssue(string $issueKey, string $sourceKey, int $projectId): array
    {
        $rows = $this->logIssueRows($sourceKey, $projectId)
            ->sortByDesc('occurred_at')
            ->values();

        if ($rows->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch issue [{$issueKey}] was not found.");
        }

        $latest = $rows->first();
        $first = $rows->last();
        $state = $this->issueState($projectId, 'log', $sourceKey);
        $resolved = $this->isResolvedAt($latest->occurred_at, $state);
        $routeLabel = $this->executionLabel($latest);
        $highestLevel = $rows
            ->sortByDesc(fn (object $row): int => $this->levelRank((string) $row->level))
            ->first()
            ->level;
        $highestTone = $this->levelTone((string) $highestLevel);

        return [
            'eyebrow' => 'Issue detail',
            'title' => (string) $latest->message,
            'subtitle' => 'Issue view for grouped error logs with operator-managed resolution state.',
            'backTo' => $this->presenter->backTo('issues'),
            'tags' => array_values(array_filter([
                ['text' => $resolved ? 'Resolved' : 'Open', 'tone' => $resolved ? 'green' : 'red'],
                ['text' => (string) $highestLevel, 'tone' => $highestTone],
                ['text' => (string) ($latest->execution_source ?: 'log'), 'tone' => 'blue'],
                $routeLabel ? ['text' => $routeLabel, 'tone' => 'mauve'] : null,
            ])),
            'scope' => [
                'project_id' => $projectId,
                'issue_key' => $issueKey,
                'source_type' => 'log',
                'source_key' => $sourceKey,
                'resolved' => $resolved,
            ],
            'metrics' => [
                [
                    'label' => 'OCCURRENCES',
                    'caption' => $this->presenter->longTimestamp($latest->occurred_at),
                    'value' => sprintf('%d occurrences', $rows->count()),
                    'meta' => [
                        $this->presenter->meta('executions '.$rows->pluck('execution_id')->filter()->unique()->count(), 'sky'),
                        $this->presenter->meta('users '.$rows->pluck('external_user_id')->filter()->unique()->count(), 'mauve'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $rows->count(), 'tone' => $highestTone],
                        ['value' => (float) $rows->pluck('execution_id')->filter()->unique()->count(), 'tone' => 'sky'],
                    ]),
                ],
                [
                    'label' => 'RUNTIME',
                    'caption' => 'Latest execution duration',
                    'value' => $this->presenter->duration((int) ($latest->duration_us ?? 0)),
                    'meta' => [
                        $this->presenter->meta('peak '.$this->presenter->bytes((int) ($latest->peak_memory_bytes ?? 0)), 'sky'),
                        $this->presenter->meta('level '.((string) $highestLevel), $highestTone),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) ($latest->duration_us ?? 0), 'tone' => $highestTone],
                        ['value' => (float) ($latest->peak_memory_bytes ?? 0), 'tone' => 'sky'],
                    ]),
                ],
            ],
            'summaryPanels' => $this->appendResolutionPanel([
                [
                    'title' => 'Occurrence',
                    'caption' => 'Derived from nw_logs and execution context.',
                    'entries' => [
                        $this->presenter->info('First seen', $this->presenter->longTimestamp($first->occurred_at)),
                        $this->presenter->info('Project', (string) $latest->project_name),
                        $this->presenter->info('Execution id', (string) ($latest->execution_id ?: 'n/a')),
                        $this->presenter->info('Route', $routeLabel ?: 'n/a'),
                        $this->presenter->info('User', (string) ($latest->user_name ?: $latest->username ?: $latest->external_user_id ?: 'n/a')),
                    ],
                ],
                [
                    'title' => 'Runtime',
                    'caption' => 'Latest execution counters mirrored from nw_executions.',
                    'entries' => [
                        $this->presenter->info('Source', (string) ($latest->execution_source ?: 'log')),
                        $this->presenter->info('Preview', (string) ($latest->execution_label ?: 'n/a')),
                        $this->presenter->info('Duration', $this->presenter->duration((int) ($latest->duration_us ?? 0))),
                        $this->presenter->info('Peak memory', $this->presenter->bytes((int) ($latest->peak_memory_bytes ?? 0))),
                    ],
                ],
            ], $state),
            'timeline' => null,
            'codePanels' => [
                [
                    'title' => 'Log Context',
                    'code' => $this->presenter->prettyJson($latest->context_json),
                ],
                [
                    'title' => 'Extra',
                    'code' => $this->presenter->prettyJson($latest->extra_json),
                ],
            ],
            'tables' => [
                [
                    'title' => 'Recent occurrences',
                    'caption' => 'Latest log events in this issue group.',
                    'searchPlaceholder' => 'grep log id…',
                    'columns' => [
                        ['key' => 'log', 'label' => 'Log', 'kind' => 'primary'],
                        ['key' => 'level', 'label' => 'Level', 'kind' => 'tone'],
                        ['key' => 'lastSeen', 'label' => 'Last seen', 'kind' => 'code'],
                    ],
                    'rows' => $rows->take(10)->map(fn (object $row): array => [
                        'id' => (string) $row->id,
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'logs', 'detailId' => (string) $row->id],
                            'query' => [
                                'project_id' => (string) $row->project_id,
                            ],
                        ],
                        'log' => $this->presenter->cell(
                            (string) $row->message,
                            ['meta' => $this->executionLabel($row)],
                        ),
                        'level' => $this->presenter->cell(
                            (string) $row->level,
                            ['tone' => $this->levelTone((string) $row->level)],
                        ),
                        'lastSeen' => $this->presenter->cell($this->presenter->shortTimestamp($row->occurred_at)),
                    ])->all(),
                ],
            ],
            'actions' => $resolved ? [] : [$this->resolveAction($issueKey)],
        ];
    }

    private function mapExceptionIssues(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (object $row): string => $this->issueCompositeKey((int) $row->project_id, 'exception', (string) $row->group_hash))
            ->map(function (Collection $group): array {
                $latest = $group->sortByDesc('occurred_at')->first();
                $routeLabel = $latest->route_name ?: $this->presenter->routeLabel($latest->method, $latest->route_path, $latest->request_url);

                return [
                    'project_id' => (int) $latest->project_id,
                    'source_type' => 'exception',
                    'source_key' => (string) $latest->group_hash,
                    'latest_occurred_at' => (string) $latest->occurred_at,
                    'latest_duration_us' => (int) ($latest->duration_us ?? 0),
                    'latest_meta' => 'latest runtime',
                    'severity_label' => 'critical',
                    'severity_tone' => 'red',
                    'count' => $group->count(),
                    'sort_at' => (string) $latest->occurred_at,
                    'user_keys' => $group->map(fn (object $row): string => (string) ($row->external_user_id ?: ''))->filter()->unique()->values()->all(),
                    'row' => [
                        'id' => sha1($latest->project_id.'|exception|'.$latest->group_hash),
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'issues', 'detailId' => $this->issueKey('exception', (string) $latest->group_hash)],
                            'query' => [
                                'project_id' => (string) $latest->project_id,
                            ],
                        ],
                        'issue' => $this->presenter->cell(
                            (string) $latest->message,
                            ['meta' => trim($routeLabel.' · '.$group->count().' occurrence(s)')],
                        ),
                        'severity' => $this->presenter->cell('critical', ['tone' => 'red']),
                        'lastSeen' => $this->presenter->cell($this->presenter->shortTimestamp($latest->occurred_at)),
                        'count' => $this->presenter->cell((string) $group->count()),
                    ],
                ];
            })
            ->values();
    }

    private function mapLogIssues(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (object $row): string => $this->issueCompositeKey((int) $row->project_id, 'log', $this->logSourceKey($row)))
            ->map(function (Collection $group): array {
                $latest = $group->sortByDesc('occurred_at')->first();
                $sourceKey = $this->logSourceKey($latest);
                $highestLevel = (string) $group
                    ->sortByDesc(fn (object $row): int => $this->levelRank((string) $row->level))
                    ->first()
                    ->level;
                $tone = $this->levelTone($highestLevel);
                $routeLabel = $this->executionLabel($latest);

                return [
                    'project_id' => (int) $latest->project_id,
                    'source_type' => 'log',
                    'source_key' => $sourceKey,
                    'latest_occurred_at' => (string) $latest->occurred_at,
                    'latest_duration_us' => (int) ($latest->duration_us ?? 0),
                    'latest_meta' => 'latest '.$highestLevel,
                    'severity_label' => $highestLevel,
                    'severity_tone' => $tone,
                    'count' => $group->count(),
                    'sort_at' => (string) $latest->occurred_at,
                    'user_keys' => $group->map(fn (object $row): string => (string) ($row->external_user_id ?: ''))->filter()->unique()->values()->all(),
                    'row' => [
                        'id' => sha1($latest->project_id.'|log|'.$sourceKey),
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'issues', 'detailId' => $this->issueKey('log', $sourceKey)],
                            'query' => [
                                'project_id' => (string) $latest->project_id,
                            ],
                        ],
                        'issue' => $this->presenter->cell(
                            (string) $latest->message,
                            ['meta' => trim($routeLabel.' · '.$group->count().' occurrence(s)')],
                        ),
                        'severity' => $this->presenter->cell($highestLevel, ['tone' => $tone]),
                        'lastSeen' => $this->presenter->cell($this->presenter->shortTimestamp($latest->occurred_at)),
                        'count' => $this->presenter->cell((string) $group->count()),
                    ],
                ];
            })
            ->values();
    }

    private function resolveScopedProjectId(string $sourceType, string $sourceKey, ?int $projectId): int
    {
        $projectIds = $sourceType === 'exception'
            ? $this->exceptionIssueQuery(['project_id' => $projectId], applyRange: false)
                ->where('exceptions.group_hash', $sourceKey)
                ->pluck('exceptions.project_id')
                ->unique()
                ->take(2)
            : $this->logIssueRows($sourceKey, $projectId)
                ->pluck('project_id')
                ->unique()
                ->take(2);

        if ($projectIds->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch issue [{$this->issueKey($sourceType, $sourceKey)}] was not found.");
        }

        if ($projectIds->count() > 1) {
            throw new ConflictHttpException(
                "Issue key [{$this->issueKey($sourceType, $sourceKey)}] exists in multiple projects. Pass project_id.",
            );
        }

        return (int) $projectIds->first();
    }

    private function logIssueRows(string $sourceKey, ?int $projectId): Collection
    {
        return $this->logIssueQuery(['project_id' => $projectId], applyRange: false)
            ->get()
            ->filter(fn (object $row): bool => $this->logSourceKey($row) === $sourceKey)
            ->values();
    }

    private function exceptionIssueQuery(array $filters, bool $applyRange): Builder
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
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $resolvedProjectId) => $query->where('exceptions.project_id', $resolvedProjectId))
            ->when($applyRange, fn (Builder $query) => $query->where('exceptions.occurred_at', '>=', $from))
            ->where('exceptions.handled', false)
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
                'exceptions.project_id',
                'exceptions.group_hash',
                'exceptions.occurred_at',
                'exceptions.message',
                'exceptions.external_user_id',
                'projects.name as project_name',
                'executions.duration_us',
                'details.method',
                'details.url as request_url',
                'details.route_name',
                'details.route_path',
                'users.name as user_name',
                'users.username',
            ]);
    }

    private function logIssueQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_logs as logs')
            ->join('nw_projects as projects', 'projects.id', '=', 'logs.project_id')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'logs.project_id')
                    ->on('executions.execution_id', '=', 'logs.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->leftJoin('nw_users as users', function ($join): void {
                $join->on('users.project_id', '=', 'logs.project_id')
                    ->on('users.external_user_id', '=', 'logs.external_user_id');
            })
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $resolvedProjectId) => $query->where('logs.project_id', $resolvedProjectId))
            ->when($applyRange, fn (Builder $query) => $query->where('logs.occurred_at', '>=', $from))
            ->whereIn('logs.level', ['error', 'critical', 'alert', 'emergency'])
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('logs.level', 'like', $like)
                        ->orWhere('logs.message', 'like', $like)
                        ->orWhere('logs.execution_id', 'like', $like)
                        ->orWhere('request_details.route_path', 'like', $like)
                        ->orWhere('request_details.url', 'like', $like);
                });
            })
            ->select([
                'logs.id',
                'logs.project_id',
                'logs.group_hash',
                'logs.occurred_at',
                'logs.execution_id',
                'logs.execution_source',
                'logs.external_user_id',
                'logs.level',
                'logs.message',
                'logs.context_json',
                'logs.extra_json',
                'projects.name as project_name',
                'executions.preview as execution_label',
                'executions.duration_us',
                'executions.peak_memory_bytes',
                'request_details.method as request_method',
                'request_details.route_path as request_route_path',
                'request_details.url as request_url',
                'users.name as user_name',
                'users.username',
            ]);
    }

    private function issueStateMap(Collection $issues): Collection
    {
        $projectIds = $issues->pluck('project_id')->unique()->values()->all();

        if ($projectIds === []) {
            return collect();
        }

        return DB::table('nw_issue_states')
            ->whereIn('project_id', $projectIds)
            ->get()
            ->keyBy(fn (object $state): string => $this->issueCompositeKey(
                (int) $state->project_id,
                (string) $state->source_type,
                (string) $state->source_key,
            ));
    }

    private function issueState(int $projectId, string $sourceType, string $sourceKey): ?object
    {
        return DB::table('nw_issue_states')
            ->where('project_id', $projectId)
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->first();
    }

    private function isResolvedIssue(array $issue, Collection $stateMap): bool
    {
        $state = $stateMap->get($this->issueCompositeKey(
            $issue['project_id'],
            $issue['source_type'],
            $issue['source_key'],
        ));

        return $this->isResolvedAt($issue['latest_occurred_at'], $state);
    }

    private function isResolvedAt(string $latestOccurredAt, ?object $state): bool
    {
        if ($state === null || $state->resolved_through_occurred_at === null) {
            return false;
        }

        return CarbonImmutable::parse($latestOccurredAt)->lessThanOrEqualTo(
            CarbonImmutable::parse($state->resolved_through_occurred_at),
        );
    }

    private function appendResolutionPanel(array $panels, ?object $state): array
    {
        if ($state === null) {
            return $panels;
        }

        $panels[] = [
            'title' => 'Resolution',
            'caption' => 'Operator-managed state for the issue queue.',
            'entries' => [
                $this->presenter->info('Resolved at', $this->presenter->longTimestamp($state->resolved_at)),
                $this->presenter->info('Resolved through', $this->presenter->longTimestamp($state->resolved_through_occurred_at)),
            ],
        ];

        return $panels;
    }

    private function detailTagsWithoutHandled(array $tags): array
    {
        return array_values(array_filter($tags, fn (array $tag): bool => ! in_array($tag['text'] ?? '', ['Handled', 'Unhandled'], true)));
    }

    private function resolveAction(string $issueKey): array
    {
        return [
            'key' => 'resolve',
            'label' => 'Mark resolved',
            'method' => 'patch',
            'endpoint' => "/api/issues/{$issueKey}/resolve",
            'primary' => true,
        ];
    }

    private function parseIssueKey(string $issueKey): array
    {
        if (str_starts_with($issueKey, 'ex_') && $issueKey !== 'ex_') {
            return [
                'source_type' => 'exception',
                'source_key' => substr($issueKey, 3),
            ];
        }

        if (str_starts_with($issueKey, 'log_') && $issueKey !== 'log_') {
            return [
                'source_type' => 'log',
                'source_key' => substr($issueKey, 4),
            ];
        }

        throw new NotFoundHttpException("Nightwatch issue [{$issueKey}] was not found.");
    }

    private function issueKey(string $sourceType, string $sourceKey): string
    {
        return $sourceType === 'exception' ? 'ex_'.$sourceKey : 'log_'.$sourceKey;
    }

    private function issueCompositeKey(int $projectId, string $sourceType, string $sourceKey): string
    {
        return implode('|', [$projectId, $sourceType, $sourceKey]);
    }

    private function logSourceKey(object $row): string
    {
        if ($row->group_hash) {
            return (string) $row->group_hash;
        }

        $path = $row->request_route_path ?: ((string) parse_url((string) $row->request_url, PHP_URL_PATH) ?: '/');

        return hash('sha1', (string) json_encode([
            (string) $row->level,
            (string) $row->message,
            (string) ($row->execution_source ?: 'log'),
            (string) $path,
        ]));
    }

    private function executionLabel(object $row): string
    {
        if ($row->execution_label) {
            return (string) $row->execution_label;
        }

        if ($row->request_method || $row->request_route_path || $row->request_url) {
            return $this->presenter->routeLabel(
                $row->request_method,
                $row->request_route_path,
                $row->request_url,
            );
        }

        return 'n/a';
    }

    private function levelTone(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'red',
            'warning', 'notice' => 'yellow',
            'info' => 'blue',
            default => 'green',
        };
    }

    private function levelRank(string $level): int
    {
        return match ($level) {
            'emergency' => 5,
            'alert' => 4,
            'critical' => 3,
            'error' => 2,
            'warning' => 1,
            default => 0,
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
            'range' => $filters['range'] ?? '24h',
            'search' => $filters['search'] ?? null,
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 15),
        ];
    }
}
