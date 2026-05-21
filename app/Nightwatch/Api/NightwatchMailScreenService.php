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

final class NightwatchMailScreenService
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
        $failedCount = $rows->where('failed', true)->count();

        return [
            'kind' => 'collection',
            'eyebrow' => 'Mail events',
            'title' => 'Mail',
            'subtitle' => 'Grouped mailable deliveries from nw_mail_events with request-aware context.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'MAIL',
                    'caption' => sprintf('%d groups · %d total', $groups->count(), $rows->count()),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('failed '.$failedCount, $failedCount > 0 ? 'red' : 'green'),
                        $this->presenter->meta('recipients '.$rows->sum('to_count'), 'blue'),
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
                'title' => sprintf('%d Mail Groups', $groups->count()),
                'caption' => '',
                'searchPlaceholder' => 'grep subject, mailable…',
                'columns' => [
                    ['key' => 'mail', 'label' => 'Mail', 'kind' => 'primary'],
                    ['key' => 'recipients', 'label' => 'Recipients'],
                    ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                ],
                'rows' => $pagination['items']->map(fn (array $item): array => $item['row'])->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $groupHash, array $filters): array
    {
        $scopes = DB::table('nw_mail_events')
            ->where('group_hash', $groupHash)
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('project_id', $projectId))
            ->select(['project_id'])
            ->distinct()
            ->limit(2)
            ->get();

        if ($scopes->isEmpty()) {
            throw new NotFoundHttpException("Nightwatch mail group [{$groupHash}] was not found.");
        }

        if ($scopes->count() > 1) {
            throw new ConflictHttpException(
                "Mail group hash [{$groupHash}] exists in multiple projects. Pass project_id.",
            );
        }

        $events = $this->baseQuery([
            'project_id' => $scopes->first()->project_id,
        ], applyRange: false)
            ->where('mail.group_hash', $groupHash)
            ->orderByDesc('mail.occurred_at')
            ->get();

        $latest = $events->first();
        $durations = $events->pluck('duration_us')->all();
        $failedCount = $events->where('failed', true)->count();

        return [
            'eyebrow' => 'Mail detail',
            'title' => (string) ($latest->subject ?: $latest->class ?: 'Mail event'),
            'subtitle' => 'Grouped mail deliveries enriched with source execution context.',
            'backTo' => $this->presenter->backTo('mail'),
            'tags' => [
                [
                    'text' => (string) ($latest->mailer ?: 'default'),
                    'tone' => 'yellow',
                ],
                [
                    'text' => $failedCount > 0 ? 'failed' : 'sent',
                    'tone' => $failedCount > 0 ? 'red' : 'green',
                ],
            ],
            'scope' => [
                'project_id' => $latest->project_id,
                'group_hash' => $groupHash,
            ],
            'metrics' => [
                [
                    'label' => 'MAIL',
                    'caption' => sprintf('%d deliveries', $events->count()),
                    'value' => (string) $events->count(),
                    'meta' => [
                        $this->presenter->meta('to '.$events->sum('to_count'), 'blue'),
                        $this->presenter->meta('attachments '.$events->sum('attachments'), 'mauve'),
                    ],
                    'bars' => $this->presenter->multiToneSparkBars([
                        ['value' => (float) $events->sum('to_count'), 'tone' => 'blue'],
                        ['value' => (float) $events->sum('attachments'), 'tone' => 'mauve'],
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
                        $this->presenter->info('Class', (string) ($latest->class ?: 'n/a')),
                        $this->presenter->info('Mailer', (string) ($latest->mailer ?: 'default')),
                        $this->presenter->info('To count', (string) $latest->to_count),
                        $this->presenter->info('Attachments', (string) $latest->attachments),
                        $this->presenter->info('Duration', $this->presenter->duration((int) $latest->duration_us)),
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
                    'title' => 'Recent Mail',
                    'caption' => '',
                    'searchPlaceholder' => 'grep execution id…',
                    'columns' => [
                        ['key' => 'message', 'label' => 'Message', 'kind' => 'primary'],
                        ['key' => 'status', 'label' => 'Status', 'kind' => 'tone'],
                        ['key' => 'duration', 'label' => 'Duration', 'kind' => 'code'],
                    ],
                    'rows' => $events->map(fn (object $event): array => [
                        'id' => (string) $event->id,
                        'message' => $this->presenter->cell(
                            $this->presenter->shortTimestamp($event->occurred_at),
                            ['meta' => trim($this->executionLabel($event).' · '.($event->execution_id ?: 'n/a'))],
                        ),
                        'status' => $this->presenter->cell(
                            $event->failed ? 'failed' : 'sent',
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

        return DB::table('nw_mail_events as mail')
            ->join('nw_projects as projects', 'projects.id', '=', 'mail.project_id')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'mail.project_id')
                    ->on('executions.execution_id', '=', 'mail.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('mail.project_id', $projectId))
            ->when($applyRange, fn (Builder $query) => $query->where('mail.occurred_at', '>=', $from))
            ->when($search, function (Builder $query, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner
                        ->where('mail.subject', 'like', $like)
                        ->orWhere('mail.class', 'like', $like)
                        ->orWhere('mail.mailer', 'like', $like)
                        ->orWhere('mail.execution_id', 'like', $like)
                        ->orWhere('request_details.route_path', 'like', $like)
                        ->orWhere('request_details.url', 'like', $like);
                });
            })
            ->select([
                'mail.id',
                'mail.project_id',
                'mail.group_hash',
                'mail.occurred_at',
                'mail.execution_id',
                'mail.execution_source',
                'mail.mailer',
                'mail.class',
                'mail.subject',
                'mail.to_count',
                'mail.cc_count',
                'mail.bcc_count',
                'mail.attachments',
                'mail.duration_us',
                'mail.failed',
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
                'id' => sha1($latest->project_id.'|'.$latest->group_hash),
                'href' => [
                    'name' => 'screen',
                    'params' => ['screenKey' => 'mail', 'detailId' => $latest->group_hash],
                    'query' => [
                        'project_id' => (string) $latest->project_id,
                    ],
                ],
                'mail' => $this->presenter->cell(
                    (string) ($latest->subject ?: $latest->class ?: 'Mail event'),
                    ['meta' => (string) ($latest->class ?: $this->executionLabel($latest))],
                ),
                'recipients' => $this->presenter->cell((string) $group->sum('to_count')),
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
