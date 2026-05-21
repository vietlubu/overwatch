<?php

namespace App\Nightwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function ceil;
use function count;
use function sort;

final class NightwatchRollupService
{
    public function refresh(?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        $from = CarbonImmutable::instance($from ?? now()->subHour())->startOfMinute();
        $to = CarbonImmutable::instance($to ?? now())->startOfMinute();

        $this->refreshRequestRoute($from, $to);
        $this->refreshExceptionGroup($from, $to);
        $this->refreshQueryGroup($from, $to);
        $this->refreshOutgoingHost($from, $to);
        $this->refreshJobQueue($from, $to);
        $this->refreshCommand($from, $to);
        $this->refreshSchedule($from, $to);
        $this->refreshLogLevel($from, $to);
    }

    private function refreshRequestRoute(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_executions')
            ->join('nw_request_details', 'nw_request_details.execution_row_id', '=', 'nw_executions.id')
            ->whereBetween('nw_executions.occurred_at', [$from, $to])
            ->select([
                'nw_executions.project_id',
                'nw_executions.occurred_at',
                'nw_executions.duration_us',
                'nw_request_details.method',
                'nw_request_details.route_name',
                'nw_request_details.route_domain',
                'nw_request_details.route_path',
                'nw_request_details.status_code',
                'nw_request_details.request_bytes',
                'nw_request_details.response_bytes',
            ])
            ->get();

        DB::table('nw_request_route_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_request_route_1m',
            $this->groupRows($rows, fn ($row) => [
                'method' => $row->method,
                'route_name' => $row->route_name,
                'route_domain' => $row->route_domain,
                'route_path' => $row->route_path,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => (int) $row->status_code >= 500,
                'is_failure' => (int) $row->status_code >= 400,
                'request_bytes' => (int) $row->request_bytes,
                'response_bytes' => (int) $row->response_bytes,
            ]),
        );
    }

    private function refreshExceptionGroup(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_exceptions')
            ->whereBetween('occurred_at', [$from, $to])
            ->select(['project_id', 'occurred_at', 'group_hash', 'class', 'file', 'line'])
            ->get();

        DB::table('nw_exception_group_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_exception_group_1m',
            $this->groupRows($rows, fn ($row) => [
                'group_hash' => $row->group_hash,
                'class' => $row->class,
                'file' => $row->file,
                'line' => $row->line,
            ], fn () => [
                'duration' => 0,
                'is_error' => true,
                'is_failure' => true,
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    private function refreshQueryGroup(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_queries')
            ->whereBetween('occurred_at', [$from, $to])
            ->select(['project_id', 'occurred_at', 'group_hash', 'connection', 'connection_type', 'file', 'duration_us'])
            ->get();

        DB::table('nw_query_group_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_query_group_1m',
            $this->groupRows($rows, fn ($row) => [
                'group_hash' => $row->group_hash,
                'connection' => $row->connection,
                'connection_type' => $row->connection_type,
                'file' => $row->file,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => false,
                'is_failure' => false,
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    private function refreshOutgoingHost(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_outgoing_requests')
            ->whereBetween('occurred_at', [$from, $to])
            ->select(['project_id', 'occurred_at', 'group_hash', 'host', 'status_code', 'duration_us', 'request_bytes', 'response_bytes'])
            ->get();

        DB::table('nw_outgoing_host_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_outgoing_host_1m',
            $this->groupRows($rows, fn ($row) => [
                'group_hash' => $row->group_hash,
                'host' => $row->host,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => (int) $row->status_code >= 500,
                'is_failure' => (int) $row->status_code >= 400,
                'request_bytes' => (int) $row->request_bytes,
                'response_bytes' => (int) $row->response_bytes,
            ]),
        );
    }

    private function refreshJobQueue(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_executions')
            ->join('nw_job_attempt_details', 'nw_job_attempt_details.execution_row_id', '=', 'nw_executions.id')
            ->whereBetween('nw_executions.occurred_at', [$from, $to])
            ->select([
                'nw_executions.project_id',
                'nw_executions.occurred_at',
                'nw_executions.duration_us',
                'nw_job_attempt_details.name',
                'nw_job_attempt_details.connection',
                'nw_job_attempt_details.queue',
                'nw_job_attempt_details.status',
            ])
            ->get();

        DB::table('nw_job_queue_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_job_queue_1m',
            $this->groupRows($rows, fn ($row) => [
                'name' => $row->name,
                'connection' => $row->connection,
                'queue' => $row->queue,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => $row->status === 'failed',
                'is_failure' => $row->status !== 'processed',
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    private function refreshCommand(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_executions')
            ->join('nw_command_details', 'nw_command_details.execution_row_id', '=', 'nw_executions.id')
            ->whereBetween('nw_executions.occurred_at', [$from, $to])
            ->select([
                'nw_executions.project_id',
                'nw_executions.occurred_at',
                'nw_executions.duration_us',
                'nw_executions.group_hash',
                'nw_command_details.name',
                'nw_command_details.class',
                'nw_command_details.exit_code',
            ])
            ->get();

        DB::table('nw_command_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_command_1m',
            $this->groupRows($rows, fn ($row) => [
                'group_hash' => $row->group_hash,
                'name' => $row->name,
                'class' => $row->class,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => (int) $row->exit_code !== 0,
                'is_failure' => (int) $row->exit_code !== 0,
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    private function refreshSchedule(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_executions')
            ->join('nw_scheduled_task_details', 'nw_scheduled_task_details.execution_row_id', '=', 'nw_executions.id')
            ->whereBetween('nw_executions.occurred_at', [$from, $to])
            ->select([
                'nw_executions.project_id',
                'nw_executions.occurred_at',
                'nw_executions.duration_us',
                'nw_executions.group_hash',
                'nw_scheduled_task_details.name',
                'nw_scheduled_task_details.cron',
                'nw_scheduled_task_details.timezone',
                'nw_scheduled_task_details.status',
            ])
            ->get();

        DB::table('nw_schedule_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_schedule_1m',
            $this->groupRows($rows, fn ($row) => [
                'group_hash' => $row->group_hash,
                'name' => $row->name,
                'cron' => $row->cron,
                'timezone' => $row->timezone,
            ], fn ($row) => [
                'duration' => (int) $row->duration_us,
                'is_error' => $row->status === 'failed',
                'is_failure' => $row->status !== 'processed',
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    private function refreshLogLevel(CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = DB::table('nw_logs')
            ->whereBetween('occurred_at', [$from, $to])
            ->select(['project_id', 'occurred_at', 'level'])
            ->get();

        DB::table('nw_log_level_1m')->whereBetween('bucket_start', [$from, $to])->delete();

        $this->persistRollup(
            'nw_log_level_1m',
            $this->groupRows($rows, fn ($row) => [
                'level' => $row->level,
            ], fn ($row) => [
                'duration' => 0,
                'is_error' => in_array($row->level, ['error', 'critical', 'alert', 'emergency'], true),
                'is_failure' => in_array($row->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true),
                'request_bytes' => 0,
                'response_bytes' => 0,
            ]),
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  callable(object): array<string, mixed>  $dimensionResolver
     * @param  callable(object): array{duration: int, is_error: bool, is_failure: bool, request_bytes: int, response_bytes: int}  $metricResolver
     * @return list<array<string, mixed>>
     */
    private function groupRows(Collection $rows, callable $dimensionResolver, callable $metricResolver): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $bucketStart = CarbonImmutable::parse($row->occurred_at)->startOfMinute();
            $dimensions = $dimensionResolver($row);
            $metrics = $metricResolver($row);

            $key = json_encode([
                'bucket_start' => $bucketStart->toIso8601String(),
                'project_id' => $row->project_id,
                'dimensions' => $dimensions,
            ], JSON_THROW_ON_ERROR);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'bucket_start' => $bucketStart,
                    'project_id' => $row->project_id,
                    ...$dimensions,
                    'count' => 0,
                    'error_count' => 0,
                    'failure_count' => 0,
                    'sum_duration_us' => 0,
                    'durations' => [],
                    'sum_request_bytes' => 0,
                    'sum_response_bytes' => 0,
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['error_count'] += $metrics['is_error'] ? 1 : 0;
            $groups[$key]['failure_count'] += $metrics['is_failure'] ? 1 : 0;
            $groups[$key]['sum_duration_us'] += $metrics['duration'];
            $groups[$key]['durations'][] = $metrics['duration'];
            $groups[$key]['sum_request_bytes'] += $metrics['request_bytes'];
            $groups[$key]['sum_response_bytes'] += $metrics['response_bytes'];
        }

        return array_map(function (array $group) {
            $durations = $group['durations'];
            unset($group['durations']);
            $group['p50_us'] = $this->percentile($durations, 50);
            $group['p95_us'] = $this->percentile($durations, 95);
            $group['p99_us'] = $this->percentile($durations, 99);
            $group['created_at'] = now();
            $group['updated_at'] = now();

            return $group;
        }, array_values($groups));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistRollup(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table($table)->insert($rows);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (int) $values[$index];
    }
}
