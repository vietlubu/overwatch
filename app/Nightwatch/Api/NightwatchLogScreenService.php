<?php

namespace App\Nightwatch\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function max;
use function sprintf;

final class NightwatchLogScreenService
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
        $rows = $this->baseQuery($filters, applyRange: true)
            ->orderByDesc('logs.occurred_at')
            ->get();

        $pagination = $this->presenter->paginate($rows, $page, $perPage);
        $levelCounts = $rows->groupBy('level')->map(fn (Collection $items): int => $items->count());
        $topLevel = (string) ($levelCounts->sortDesc()->keys()->first() ?? 'n/a');

        return [
            'kind' => 'collection',
            'eyebrow' => 'Logs',
            'title' => 'Logs',
            'subtitle' => 'Log events from nw_logs with request-aware source context.',
            'filters' => $this->publicFilters($filters),
            'metrics' => [
                [
                    'label' => 'LOGS',
                    'caption' => sprintf('%d recent %s', $rows->count(), $topLevel),
                    'value' => (string) $rows->count(),
                    'meta' => [
                        $this->presenter->meta('level '.$topLevel, $this->levelTone($topLevel)),
                    ],
                    'bars' => $this->presenter->sparkBars(
                        $levelCounts->values()->all() ?: [0],
                        $this->levelTone($topLevel),
                    ),
                ],
            ],
            'table' => [
                'title' => 'Recent logs',
                'caption' => '',
                'searchPlaceholder' => 'grep level, message…',
                'columns' => [
                    ['key' => 'log', 'label' => 'Log', 'kind' => 'primary'],
                    ['key' => 'level', 'label' => 'Level', 'kind' => 'tone'],
                    ['key' => 'source', 'label' => 'Source'],
                ],
                'rows' => $pagination['items']->map(fn (object $row): array => $this->mapRow($row))->all(),
            ],
            'pagination' => $pagination['meta'],
        ];
    }

    public function show(string $logId, array $filters): array
    {
        $log = $this->baseQuery($filters, applyRange: false)
            ->where('logs.id', $logId)
            ->first();

        if ($log === null) {
            throw new NotFoundHttpException("Nightwatch log [{$logId}] was not found.");
        }

        return [
            'eyebrow' => 'Log detail',
            'title' => (string) $log->message,
            'subtitle' => 'Event-level log detail with source execution context.',
            'backTo' => $this->presenter->backTo('logs'),
            'tags' => [
                [
                    'text' => (string) $log->level,
                    'tone' => $this->levelTone((string) $log->level),
                ],
                [
                    'text' => (string) ($log->execution_source ?: 'log'),
                    'tone' => 'blue',
                ],
            ],
            'scope' => [
                'project_id' => $log->project_id,
                'log_id' => (string) $log->id,
            ],
            'summaryPanels' => [
                [
                    'title' => 'Source',
                    'caption' => '',
                    'entries' => [
                        $this->presenter->info('Occurred at', $this->presenter->longTimestamp($log->occurred_at)),
                        $this->presenter->info('Execution id', (string) ($log->execution_id ?: 'n/a')),
                        $this->presenter->info('Route', $this->executionLabel($log)),
                        $this->presenter->info('Level', (string) $log->level),
                    ],
                ],
            ],
            'timeline' => null,
            'codePanels' => [
                [
                    'title' => 'Log Context',
                    'code' => $this->presenter->prettyJson($log->context_json),
                ],
                [
                    'title' => 'Extra',
                    'code' => $this->presenter->prettyJson($log->extra_json),
                ],
            ],
        ];
    }

    private function baseQuery(array $filters, bool $applyRange): Builder
    {
        $search = $filters['search'] ?? null;
        $from = $this->resolveRangeStart($filters['range'] ?? '24h');

        return DB::table('nw_logs as logs')
            ->leftJoin('nw_executions as executions', function ($join): void {
                $join->on('executions.project_id', '=', 'logs.project_id')
                    ->on('executions.execution_id', '=', 'logs.execution_id');
            })
            ->leftJoin('nw_request_details as request_details', 'request_details.execution_row_id', '=', 'executions.id')
            ->when($filters['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->where('logs.project_id', $projectId))
            ->when($applyRange, fn (Builder $query) => $query->where('logs.occurred_at', '>=', $from))
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
                'logs.occurred_at',
                'logs.execution_id',
                'logs.execution_source',
                'logs.level',
                'logs.message',
                'logs.context_json',
                'logs.extra_json',
                'executions.preview as execution_label',
                'request_details.method as request_method',
                'request_details.route_path as request_route_path',
                'request_details.url as request_url',
            ]);
    }

    private function mapRow(object $row): array
    {
        return [
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
                ['meta' => $this->executionLabel($row).' · '.$this->presenter->shortTimestamp($row->occurred_at)],
            ),
            'level' => $this->presenter->cell(
                (string) $row->level,
                ['tone' => $this->levelTone((string) $row->level)],
            ),
            'source' => $this->presenter->cell((string) ($row->execution_source ?: 'log')),
        ];
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
