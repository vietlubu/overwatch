<?php

namespace App\Nightwatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class NightwatchCleanupService
{
    public function __construct(
        private readonly NightwatchPartitionManager $partitions,
    ) {
        //
    }

    /**
     * @return array<string, int>
     */
    public function cleanup(): array
    {
        $retentionDays = (int) Config::get('overwatch.storage.retention_days', 30);
        $rollupRetentionDays = (int) Config::get('overwatch.storage.rollup_retention_days', 180);
        $factsCutoff = CarbonImmutable::now()->subDays($retentionDays);
        $rollupCutoff = CarbonImmutable::now()->subDays($rollupRetentionDays);

        $deleted = [
            'raw_partitions_dropped' => $this->partitions->dropExpiredRawEventPartitions($factsCutoff),
            'raw_events_deleted' => $this->deleteByDate('nw_raw_events', 'occurred_at', $factsCutoff),
            'ingest_batches_deleted' => $this->deleteByDate('nw_ingest_batches', 'received_at', $factsCutoff),
            'executions_deleted' => $this->deleteByDate('nw_executions', 'occurred_at', $factsCutoff),
            'exceptions_deleted' => $this->deleteByDate('nw_exceptions', 'occurred_at', $factsCutoff),
            'queries_deleted' => $this->deleteByDate('nw_queries', 'occurred_at', $factsCutoff),
            'outgoing_requests_deleted' => $this->deleteByDate('nw_outgoing_requests', 'occurred_at', $factsCutoff),
            'queued_jobs_deleted' => $this->deleteByDate('nw_queued_jobs', 'occurred_at', $factsCutoff),
            'logs_deleted' => $this->deleteByDate('nw_logs', 'occurred_at', $factsCutoff),
            'mail_deleted' => $this->deleteByDate('nw_mail_events', 'occurred_at', $factsCutoff),
            'notifications_deleted' => $this->deleteByDate('nw_notification_events', 'occurred_at', $factsCutoff),
            'cache_events_deleted' => $this->deleteByDate('nw_cache_events', 'occurred_at', $factsCutoff),
            'jobs_deleted' => $this->deleteJobs($factsCutoff),
            'request_route_rollups_deleted' => $this->deleteByDate('nw_request_route_1m', 'bucket_start', $rollupCutoff),
            'exception_group_rollups_deleted' => $this->deleteByDate('nw_exception_group_1m', 'bucket_start', $rollupCutoff),
            'query_group_rollups_deleted' => $this->deleteByDate('nw_query_group_1m', 'bucket_start', $rollupCutoff),
            'outgoing_host_rollups_deleted' => $this->deleteByDate('nw_outgoing_host_1m', 'bucket_start', $rollupCutoff),
            'job_queue_rollups_deleted' => $this->deleteByDate('nw_job_queue_1m', 'bucket_start', $rollupCutoff),
            'command_rollups_deleted' => $this->deleteByDate('nw_command_1m', 'bucket_start', $rollupCutoff),
            'schedule_rollups_deleted' => $this->deleteByDate('nw_schedule_1m', 'bucket_start', $rollupCutoff),
            'log_level_rollups_deleted' => $this->deleteByDate('nw_log_level_1m', 'bucket_start', $rollupCutoff),
        ];

        return $deleted;
    }

    private function deleteByDate(string $table, string $column, CarbonImmutable $cutoff): int
    {
        return DB::table($table)->where($column, '<', $cutoff)->delete();
    }

    private function deleteJobs(CarbonImmutable $cutoff): int
    {
        return DB::table('nw_jobs')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_attempt_at', '<', $cutoff)
                    ->orWhere(function ($nested) use ($cutoff) {
                        $nested->whereNull('last_attempt_at')
                            ->where('first_queued_at', '<', $cutoff);
                    });
            })
            ->delete();
    }
}
