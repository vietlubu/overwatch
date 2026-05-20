<?php

namespace App\Nightwatch\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_filter;
use function array_map;
use function count;
use function max;
use function min;
use function round;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

final class NightwatchJobScreenService
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
        $jobs = $this->baseJobQuery($filters, applyRange: true)
            ->orderByRaw('coalesce(nw_jobs.last_attempt_at, nw_jobs.first_queued_at) desc')
            ->get();

        $latestAttempts = $this->latestAttemptsForJobs($jobs);
        $latestQueues = $this->latestQueuesForJobs($jobs);

        $items = $jobs
            ->map(function (object $job) use ($latestAttempts, $latestQueues): array {
                $key = $this->jobKey((int) $job->project_id, (string) $job->environment, (string) $job->job_id);
                $latestAttempt = $latestAttempts->get($key);
                $latestQueue = $latestQueues->get($key);
                $name = $this->jobName($job, $latestAttempt, $latestQueue);
                $queue = $this->jobQueue($latestAttempt, $latestQueue);
                $connection = $this->jobConnection($latestAttempt, $latestQueue);
                $status = (string) ($job->last_status ?: 'queued');

                return [
                    'key' => $key,
                    'sort_at' => (string) ($job->last_attempt_at ?: $job->first_queued_at ?: $job->updated_at),
                    'status' => $status,
                    'search' => strtolower(implode(' ', array_filter([
                        $job->job_id,
                        $name,
                        $queue,
                        $connection,
                        $job->project_name,
                        $job->environment,
                        $status,
                        $job->enqueued_by_execution_id,
                        $job->last_attempt_id,
                    ]))),
                    'row' => [
                        'id' => sha1($key),
                        'href' => [
                            'name' => 'screen',
                            'params' => ['screenKey' => 'jobs', 'detailId' => (string) $job->job_id],
                            'query' => [
                                'project_id' => (string) $job->project_id,
                                'environment' => (string) $job->environment,
                            ],
                        ],
                        'job' => $this->presenter->cell(
                            $name,
                            ['meta' => $this->jobMeta($job, $connection, $queue)],
                        ),
                        'attempts' => $this->presenter->cell((string) $job->attempt_count),
                        'status' => $this->presenter->cell(
                            $status,
                            ['tone' => $this->jobStatusTone($status)],
                        ),
                        'queue' => $this->presenter->cell($queue ?: 'n/a'),
                    ],
                ];
            })
            ->filter(function (array $item) use ($filters): bool {
                $search = trim((string) ($filters['search'] ?? ''));

                return $search === '' || str_contains($item['search'], strtolower($search));
            })
            ->sortByDesc('sort_at')
            ->values();

        $pagination = $this->presenter->paginate($items, $page, $perPage);
        $jobKeys = $items->pluck('key')->flip();
        $attempts = $this->attemptQuery($filters, applyRange: true)
            ->get()
            ->filter(fn (object $attempt): bool => $jobKeys->has(
                $this->jobKey((int) $attempt->project_id, (string) $attempt->environment, (string) $attempt->job_id),
            ))
            ->values();
        $durations = $attempts->pluck('duration_us')->all();
        $processedCount = $items->where('status', 'processed')->count();
        $releasedCount = $items->where('status', 'released')->count();
        $failedCount = $items->where('status', 'failed')->count();
        $queuedCount = $items->where('status', 'queued')->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Queued work',
            'title' => 'Jobs',
            'subtitle' => 'Job queue rollups and per-job drill-down sourced from nw_jobs, nw_queued_jobs, and nw_job_attempt_details.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'JOBS',
                    'caption' => sprintf('%d failed', $failedCount),
                    'value' => sprintf('%d processed', $processedCount),
                    'meta' => [
                        $this->presenter->meta('released '.$releasedCount, 'yellow'),
                        $this->presenter->meta('queued '.$queuedCount, 'sky'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $processedCount, 'tone' => 'green'],
                        ['value' => (float) $releasedCount, 'tone' => 'yellow'],
                        ['value' => (float) $failedCount, 'tone' => 'red'],
                        ['value' => (float) $queuedCount, 'tone' => 'sky'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $attempts->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) min($durations)).' - '.$this->presenter->duration((int) max($durations)),
                    'value' => $attempts->isEmpty()
                        ? 'n/a'
                        : $this->presenter->duration((int) round($attempts->avg('duration_us') ?? 0)),
                    'meta' => [
                        $this->presenter->meta('attempts '.$attempts->count(), 'blue'),
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durations, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars($durations === [] ? [0] : $durations, 'mauve'),
                ],
            ],
            'table' => [
                'title' => sprintf('%d Jobs', $items->count()),
                'caption' => 'A collection-level payload can join current state from nw_jobs and recent attempts.',
                'searchPlaceholder' => 'grep queue, class…',
                'columns' => [
                    ['key' => 'job', 'label' => 'Job', 'kind' => 'primary'],
                    ['key' => 'attempts', 'label' => 'Attempts'],
                    ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                    ['key' => 'queue', 'label' => 'Queue', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $jobId, array $filters): array
    {
        $scopes = DB::table('nw_jobs')
            ->where('job_id', $jobId)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('environment', $environment))
            ->select(['project_id', 'environment'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch job [{$jobId}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Job id [{$jobId}] exists in multiple project/environment scopes. Pass project_id and environment.",
            );
        }

        $scope = [
            'project_id' => (int) $scopes->first()->project_id,
            'environment' => (string) $scopes->first()->environment,
        ];

        $job = $this->baseJobQuery($scope, applyRange: false)
            ->where('nw_jobs.job_id', $jobId)
            ->firstOrFail();
        $attempts = $this->attemptQuery([...$scope, 'job_id' => $jobId], applyRange: false)
            ->orderByDesc('executions.occurred_at')
            ->get();
        $queues = $this->queuedQuery([...$scope, 'job_id' => $jobId], applyRange: false)
            ->orderByDesc('queued.occurred_at')
            ->get();

        $latestAttempt = $attempts->first();
        $latestQueue = $queues->first();
        $firstQueue = $queues->sortBy('occurred_at')->first();
        $jobName = $this->jobName($job, $latestAttempt, $latestQueue);
        $queue = $this->jobQueue($latestAttempt, $latestQueue);
        $connection = $this->jobConnection($latestAttempt, $latestQueue);
        $failedAttempts = $attempts->where('status', 'failed')->count();
        $durationValues = $attempts->pluck('duration_us')->all();
        $avgDuration = $attempts->isEmpty()
            ? 'n/a'
            : $this->presenter->duration((int) round($attempts->avg('duration_us') ?? 0));
        $latestStatus = (string) ($job->last_status ?: 'queued');
        $contextPanel = $latestAttempt
            ? [
                'title' => 'Latest Attempt Context',
                'code' => $this->presenter->prettyJson($latestAttempt->context_json),
            ]
            : null;
        $exceptionPanel = $latestAttempt && $latestAttempt->exception_preview
            ? [
                'title' => 'Exception Preview',
                'code' => (string) $latestAttempt->exception_preview,
            ]
            : null;

        return [
            'eyebrow' => 'Job detail',
            'title' => $jobName,
            'subtitle' => 'Consolidated job view centered on nw_jobs with last attempt facts from nw_job_attempt_details.',
            'backTo' => $this->presenter->backTo('jobs'),
            'tags' => array_values(array_filter([
                ['text' => $latestStatus, 'tone' => $this->jobStatusTone($latestStatus)],
                $queue ? ['text' => $queue, 'tone' => 'sky'] : null,
                $connection ? ['text' => $connection, 'tone' => 'mauve'] : null,
            ])),
            'scope' => [
                'project_id' => (int) $job->project_id,
                'environment' => (string) $job->environment,
                'job_id' => (string) $job->job_id,
            ],
            'metrics' => [
                [
                    'label' => 'ATTEMPTS',
                    'caption' => $job->last_attempt_id
                        ? 'last attempt '.$job->last_attempt_id
                        : 'queued, awaiting worker',
                    'value' => (string) $job->attempt_count,
                    'meta' => [
                        $this->presenter->meta('failed '.$failedAttempts, $failedAttempts > 0 ? 'red' : 'green'),
                        $this->presenter->meta('queued '.$queues->count(), 'sky'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $job->attempt_count, 'tone' => 'green'],
                        ['value' => (float) $failedAttempts, 'tone' => 'red'],
                    ]),
                ],
                [
                    'label' => 'DURATION',
                    'caption' => $attempts->isEmpty()
                        ? 'runtime unavailable'
                        : $this->presenter->duration((int) min($durationValues)).' - '.$this->presenter->duration((int) max($durationValues)),
                    'value' => $avgDuration,
                    'meta' => [
                        $this->presenter->meta('total '.$this->presenter->duration((int) $job->total_runtime_us), 'blue'),
                        $this->presenter->meta('P95 '.$this->presenter->duration($this->presenter->percentile($durationValues, 0.95)), 'yellow'),
                    ],
                    'bars' => $this->presenter->sparkBars($durationValues === [] ? [0] : $durationValues, 'mauve'),
                ],
            ],
            'summaryPanels' => [
                [
                    'title' => 'Info',
                    'caption' => 'Current state assembled from nw_jobs plus the latest queue/attempt record.',
                    'entries' => [
                        $this->presenter->info('Job id', (string) $job->job_id),
                        $this->presenter->info('Connection', $connection ?: 'n/a'),
                        $this->presenter->info('Queue', $queue ?: 'n/a'),
                        $this->presenter->info('First queued at', $firstQueue?->occurred_at ? $this->presenter->longTimestamp($firstQueue->occurred_at) : 'n/a'),
                        $this->presenter->info('Last status', $latestStatus),
                    ],
                ],
                [
                    'title' => 'Activity',
                    'caption' => 'Most recent attempt counters borrowed from nw_executions.',
                    'entries' => [
                        $this->presenter->info('Enqueued by execution', (string) ($job->enqueued_by_execution_id ?: 'n/a')),
                        $this->presenter->info('Last attempt at', $job->last_attempt_at ? $this->presenter->longTimestamp($job->last_attempt_at) : 'n/a'),
                        $this->presenter->info('Queries', (string) ($latestAttempt->queries ?? 0)),
                        $this->presenter->info('Logs', (string) ($latestAttempt->logs ?? 0)),
                        $this->presenter->info('Peak memory', $this->presenter->bytes((int) ($latestAttempt->peak_memory_bytes ?? 0))),
                    ],
                ],
            ],
            'timeline' => null,
            'codePanels' => array_values(array_filter([$exceptionPanel, $contextPanel])),
            'tables' => [
                [
                    'title' => 'Attempts',
                    'caption' => 'Recent worker executions for this job id.',
                    'searchPlaceholder' => 'grep attempt id…',
                    'columns' => [
                        ['key' => 'attempt', 'label' => 'Attempt', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'when', 'label' => 'When', 'kind' => 'code'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $attempts->map(fn (object $attempt): array => [
                        'id' => $attempt->attempt_execution_id,
                        'attempt' => $this->presenter->cell(
                            'attempt #'.$attempt->attempt,
                            ['meta' => 'exec '.$attempt->attempt_execution_id],
                        ),
                        'status' => $this->presenter->cell(
                            (string) $attempt->status,
                            ['tone' => $this->jobStatusTone((string) $attempt->status)],
                        ),
                        'when' => $this->presenter->cell($this->presenter->shortTimestamp($attempt->occurred_at)),
                        'duration' => $this->presenter->cell($this->presenter->duration((int) $attempt->duration_us)),
                    ])->all(),
                ],
            ],
        ];
    }

    private function baseJobQuery(array $filters, bool $applyRange): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_jobs')
            ->join('nw_projects as projects', 'projects.id', '=', 'nw_jobs.project_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('nw_jobs.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('nw_jobs.environment', $environment))
            ->when($applyRange, function (Builder $query) use ($from): void {
                $query->where(function (Builder $range) use ($from): void {
                    $range
                        ->where('nw_jobs.last_attempt_at', '>=', $from)
                        ->orWhere(function (Builder $queued) use ($from): void {
                            $queued
                                ->whereNull('nw_jobs.last_attempt_at')
                                ->where('nw_jobs.first_queued_at', '>=', $from);
                        });
                });
            })
            ->select([
                'projects.name as project_name',
                'nw_jobs.project_id',
                'nw_jobs.environment',
                'nw_jobs.job_id',
                'nw_jobs.first_trace_id',
                'nw_jobs.enqueued_by_execution_id',
                'nw_jobs.first_queued_at',
                'nw_jobs.last_attempt_id',
                'nw_jobs.last_attempt_at',
                'nw_jobs.attempt_count',
                'nw_jobs.last_status',
                'nw_jobs.total_runtime_us',
                'nw_jobs.updated_at',
            ]);
    }

    private function attemptQuery(array $filters, bool $applyRange): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_job_attempt_details as attempts')
            ->join('nw_executions as executions', 'executions.id', '=', 'attempts.execution_row_id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('executions.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('executions.environment', $environment))
            ->when($filters['job_id'] ?? null, fn (Builder $query, string $jobId) => $query->where('attempts.job_id', $jobId))
            ->when($filters['project_ids'] ?? null, fn (Builder $query, array $projectIds) => $query->whereIn('executions.project_id', $projectIds))
            ->when($filters['job_ids'] ?? null, fn (Builder $query, array $jobIds) => $query->whereIn('attempts.job_id', $jobIds))
            ->when($applyRange, fn (Builder $query) => $query->where('executions.occurred_at', '>=', $from))
            ->select([
                'executions.project_id',
                'executions.environment',
                'executions.execution_id as attempt_execution_id',
                'executions.trace_id',
                'executions.occurred_at',
                'executions.duration_us',
                'executions.status as execution_status',
                'executions.queries',
                'executions.logs',
                'executions.notifications',
                'executions.outgoing_requests',
                'executions.cache_events',
                'executions.hydrated_models',
                'executions.peak_memory_bytes',
                'executions.exception_preview',
                'executions.context_json',
                'attempts.execution_id',
                'attempts.job_id',
                'attempts.attempt',
                'attempts.name',
                'attempts.connection',
                'attempts.queue',
                'attempts.status',
            ]);
    }

    private function queuedQuery(array $filters, bool $applyRange): Builder
    {
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_queued_jobs as queued')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('queued.project_id', $projectId))
            ->when($filters['environment'] ?? null, fn (Builder $query, string $environment) => $query->where('queued.environment', $environment))
            ->when($filters['job_id'] ?? null, fn (Builder $query, string $jobId) => $query->where('queued.job_id', $jobId))
            ->when($filters['project_ids'] ?? null, fn (Builder $query, array $projectIds) => $query->whereIn('queued.project_id', $projectIds))
            ->when($filters['job_ids'] ?? null, fn (Builder $query, array $jobIds) => $query->whereIn('queued.job_id', $jobIds))
            ->when($applyRange, fn (Builder $query) => $query->where('queued.occurred_at', '>=', $from))
            ->select([
                'queued.project_id',
                'queued.environment',
                'queued.job_id',
                'queued.execution_id',
                'queued.occurred_at',
                'queued.name',
                'queued.connection',
                'queued.queue',
                'queued.duration_us',
            ]);
    }

    private function latestAttemptsForJobs(Collection $jobs): Collection
    {
        if ($jobs->isEmpty()) {
            return collect();
        }

        return $this->attemptQuery([
            'project_ids' => $jobs->pluck('project_id')->unique()->values()->all(),
            'job_ids' => $jobs->pluck('job_id')->unique()->values()->all(),
        ], applyRange: false)
            ->orderByDesc('executions.occurred_at')
            ->get()
            ->groupBy(fn (object $attempt): string => $this->jobKey(
                (int) $attempt->project_id,
                (string) $attempt->environment,
                (string) $attempt->job_id,
            ))
            ->map(fn (Collection $group): object => $group->first());
    }

    private function latestQueuesForJobs(Collection $jobs): Collection
    {
        if ($jobs->isEmpty()) {
            return collect();
        }

        return $this->queuedQuery([
            'project_ids' => $jobs->pluck('project_id')->unique()->values()->all(),
            'job_ids' => $jobs->pluck('job_id')->unique()->values()->all(),
        ], applyRange: false)
            ->orderByDesc('queued.occurred_at')
            ->get()
            ->groupBy(fn (object $queue): string => $this->jobKey(
                (int) $queue->project_id,
                (string) $queue->environment,
                (string) $queue->job_id,
            ))
            ->map(fn (Collection $group): object => $group->first());
    }

    private function jobName(object $job, ?object $latestAttempt, ?object $latestQueue): string
    {
        return (string) ($latestAttempt?->name ?: $latestQueue?->name ?: $job->job_id);
    }

    private function jobQueue(?object $latestAttempt, ?object $latestQueue): string
    {
        return (string) ($latestAttempt?->queue ?: $latestQueue?->queue ?: '');
    }

    private function jobConnection(?object $latestAttempt, ?object $latestQueue): string
    {
        return (string) ($latestAttempt?->connection ?: $latestQueue?->connection ?: '');
    }

    private function jobMeta(object $job, string $connection, string $queue): string
    {
        return implode(' · ', array_filter([
            $connection,
            $queue,
            $job->project_name,
            $job->environment,
        ]));
    }

    private function jobStatusTone(?string $status): string
    {
        return match ($status) {
            'failed' => 'red',
            'released' => 'yellow',
            'queued' => 'sky',
            default => 'green',
        };
    }

    private function jobKey(int $projectId, string $environment, string $jobId): string
    {
        return implode('|', [$projectId, $environment, $jobId]);
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
