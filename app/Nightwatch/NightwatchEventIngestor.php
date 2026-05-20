<?php

namespace App\Nightwatch;

use App\Nightwatch\Exceptions\UnknownNightwatchTokenException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

use function array_key_exists;
use function floor;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

final class NightwatchEventIngestor
{
    /**
     * @var array<string, int>
     */
    private array $serverIds = [];

    /**
     * @var array<string, int>
     */
    private array $deploymentIds = [];

    public function __construct(
        private readonly NightwatchPayloadParser $parser,
        private readonly NightwatchPartitionManager $partitions,
    ) {
        //
    }

    public function ingestWirePayload(string $wirePayload, string $transport = 'tcp'): int
    {
        $parts = $this->parser->split($wirePayload);
        $token = $this->findToken($parts['token_hash']);

        $batchId = $this->createBatch(
            projectId: $token['project_id'] ?? null,
            environment: $token['environment'] ?? null,
            ingestTokenId: $token['id'] ?? null,
            tokenHash: $parts['token_hash'],
            protocolVersion: $parts['protocol_version'],
            transport: $transport,
            payloadBytes: $parts['length'],
        );

        try {
            if ($token === null) {
                throw new UnknownNightwatchTokenException("Unknown Nightwatch token hash [{$parts['token_hash']}].");
            }

            $parsed = $this->parser->parse($wirePayload);

            if ($parsed->isPing) {
                $this->markBatchAccepted($batchId, 0);

                return 0;
            }

            $inserted = DB::transaction(function () use ($parsed, $token, $batchId) {
                $count = 0;

                foreach ($parsed->records as $index => $record) {
                    $this->ingestRecord($token, $batchId, $index, $record);
                    $count++;
                }

                return $count;
            });

            $this->touchToken($token['id']);
            $this->markBatchAccepted($batchId, $inserted);

            return $inserted;
        } catch (Throwable $e) {
            $this->markBatchRejected($batchId, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @param  array{id: int, project_id: int, environment: string}  $token
     * @param  array<string, mixed>  $record
     */
    private function ingestRecord(array $token, int $batchId, int $index, array $record): void
    {
        $normalized = $this->normalizeRecord($token, $batchId, $index, $record);

        $this->partitions->ensureRawEventPartition($normalized['occurred_at']);

        DB::table('nw_raw_events')->insert([
            'batch_id' => $batchId,
            'batch_record_index' => $index,
            'project_id' => $token['project_id'],
            'environment' => $token['environment'],
            'event_type' => $normalized['event_type'],
            'schema_version' => $normalized['schema_version'],
            'occurred_at' => $normalized['occurred_at'],
            'group_hash' => $normalized['group_hash'],
            'trace_id' => $normalized['trace_id'],
            'execution_id' => $normalized['execution_id'],
            'execution_source' => $normalized['execution_source'],
            'execution_stage' => $normalized['execution_stage'],
            'deployment_id' => $normalized['deployment_id'],
            'server_id' => $normalized['server_id'],
            'external_user_id' => $normalized['external_user_id'],
            'payload' => $this->jsonValue($record),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        match ($normalized['event_type']) {
            'user' => $this->upsertUser($token, $normalized['occurred_at'], $record),
            'request' => $this->storeRequestSummary($normalized, $record),
            'command' => $this->storeCommandSummary($normalized, $record),
            'job-attempt' => $this->storeJobAttemptSummary($normalized, $record),
            'scheduled-task' => $this->storeScheduledTaskSummary($normalized, $record),
            'exception' => $this->storeException($normalized, $record),
            'query' => $this->storeQuery($normalized, $record),
            'outgoing-request' => $this->storeOutgoingRequest($normalized, $record),
            'queued-job' => $this->storeQueuedJob($normalized, $record),
            'log' => $this->storeLog($normalized, $record),
            'mail' => $this->storeMail($normalized, $record),
            'notification' => $this->storeNotification($normalized, $record),
            'cache-event' => $this->storeCacheEvent($normalized, $record),
            default => null,
        };
    }

    /**
     * @param  array{id: int, project_id: int, environment: string}  $token
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $token, int $batchId, int $index, array $record): array
    {
        $occurredAt = $this->timestampToCarbon($record['timestamp'] ?? null);
        $eventType = (string) ($record['t'] ?? 'unknown');

        return [
            'batch_id' => $batchId,
            'batch_record_index' => $index,
            'project_id' => $token['project_id'],
            'environment' => $token['environment'],
            'schema_version' => (int) ($record['v'] ?? 1),
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'group_hash' => $this->nullableString($record['_group'] ?? null),
            'trace_id' => $this->nullableString($record['trace_id'] ?? null),
            'execution_id' => $this->resolveExecutionId($eventType, $record),
            'execution_source' => $this->resolveExecutionSource($eventType, $record),
            'execution_stage' => $this->nullableString($record['execution_stage'] ?? null),
            'deployment_id' => $this->resolveDeploymentId($token['project_id'], $token['environment'], $record['deploy'] ?? null, $occurredAt),
            'server_id' => $this->resolveServerId($token['project_id'], $token['environment'], $record['server'] ?? null, $occurredAt),
            'external_user_id' => $this->nullableString($record['user'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeRequestSummary(array $normalized, array $record): void
    {
        $statusCode = (int) ($record['status_code'] ?? 0);
        $contextJson = $this->decodeJsonString($record['context'] ?? null);
        [$payloadState, $requestPayload] = $this->parseRequestPayload($record['payload'] ?? null);

        $executionRowId = $this->upsertExecution($normalized, [
            'source' => 'request',
            'trace_id' => $normalized['trace_id'],
            'preview' => $this->nullableString($record['execution_preview'] ?? null),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'status' => $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'failure' : 'ok'),
            'exceptions' => (int) ($record['exceptions'] ?? 0),
            'logs' => (int) ($record['logs'] ?? 0),
            'queries' => (int) ($record['queries'] ?? 0),
            'lazy_loads' => (int) ($record['lazy_loads'] ?? 0),
            'jobs_queued' => (int) ($record['jobs_queued'] ?? 0),
            'mail' => (int) ($record['mail'] ?? 0),
            'notifications' => (int) ($record['notifications'] ?? 0),
            'outgoing_requests' => (int) ($record['outgoing_requests'] ?? 0),
            'files_read' => (int) ($record['files_read'] ?? 0),
            'files_written' => (int) ($record['files_written'] ?? 0),
            'cache_events' => (int) ($record['cache_events'] ?? 0),
            'hydrated_models' => (int) ($record['hydrated_models'] ?? 0),
            'peak_memory_bytes' => (int) ($record['peak_memory_usage'] ?? 0),
            'exception_preview' => $this->nullableString($record['exception_preview'] ?? null),
            'context_json' => $this->jsonValue($contextJson),
        ]);

        DB::table('nw_request_details')->updateOrInsert(
            ['execution_row_id' => $executionRowId],
            [
                'execution_id' => $normalized['execution_id'],
                'method' => (string) ($record['method'] ?? ''),
                'url' => (string) ($record['url'] ?? ''),
                'route_name' => $this->nullableString($record['route_name'] ?? null),
                'route_methods' => $this->jsonValue($this->arrayOrNull($record['route_methods'] ?? null)),
                'route_domain' => $this->nullableString($record['route_domain'] ?? null),
                'route_path' => $this->nullableString($record['route_path'] ?? null),
                'route_action' => $this->nullableString($record['route_action'] ?? null),
                'ip_text' => $this->nullableString($record['ip'] ?? null),
                'status_code' => $statusCode,
                'request_bytes' => (int) ($record['request_size'] ?? 0),
                'response_bytes' => (int) ($record['response_size'] ?? 0),
                'bootstrap_us' => (int) ($record['bootstrap'] ?? 0),
                'before_middleware_us' => (int) ($record['before_middleware'] ?? 0),
                'action_us' => (int) ($record['action'] ?? 0),
                'render_us' => (int) ($record['render'] ?? 0),
                'after_middleware_us' => (int) ($record['after_middleware'] ?? 0),
                'sending_us' => (int) ($record['sending'] ?? 0),
                'terminating_us' => (int) ($record['terminating'] ?? 0),
                'headers_json' => $this->jsonValue($this->decodeJsonString($record['headers'] ?? null)),
                'request_payload_json' => $this->jsonValue($requestPayload),
                'request_payload_state' => $payloadState,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeCommandSummary(array $normalized, array $record): void
    {
        $exitCode = (int) ($record['exit_code'] ?? 0);

        $executionRowId = $this->upsertExecution($normalized, [
            'source' => 'command',
            'trace_id' => $normalized['trace_id'],
            'preview' => $this->nullableString($record['execution_preview'] ?? $record['name'] ?? null),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'exceptions' => (int) ($record['exceptions'] ?? 0),
            'logs' => (int) ($record['logs'] ?? 0),
            'queries' => (int) ($record['queries'] ?? 0),
            'lazy_loads' => (int) ($record['lazy_loads'] ?? 0),
            'jobs_queued' => (int) ($record['jobs_queued'] ?? 0),
            'mail' => (int) ($record['mail'] ?? 0),
            'notifications' => (int) ($record['notifications'] ?? 0),
            'outgoing_requests' => (int) ($record['outgoing_requests'] ?? 0),
            'files_read' => (int) ($record['files_read'] ?? 0),
            'files_written' => (int) ($record['files_written'] ?? 0),
            'cache_events' => (int) ($record['cache_events'] ?? 0),
            'hydrated_models' => (int) ($record['hydrated_models'] ?? 0),
            'peak_memory_bytes' => (int) ($record['peak_memory_usage'] ?? 0),
            'exception_preview' => $this->nullableString($record['exception_preview'] ?? null),
            'context_json' => $this->jsonValue($this->decodeJsonString($record['context'] ?? null)),
        ]);

        DB::table('nw_command_details')->updateOrInsert(
            ['execution_row_id' => $executionRowId],
            [
                'execution_id' => $normalized['execution_id'],
                'class' => (string) ($record['class'] ?? ''),
                'name' => (string) ($record['name'] ?? ''),
                'command' => (string) ($record['command'] ?? ''),
                'exit_code' => $exitCode,
                'bootstrap_us' => (int) ($record['bootstrap'] ?? 0),
                'action_us' => (int) ($record['action'] ?? 0),
                'terminating_us' => (int) ($record['terminating'] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeJobAttemptSummary(array $normalized, array $record): void
    {
        $status = (string) ($record['status'] ?? 'processed');

        $executionRowId = $this->upsertExecution($normalized, [
            'source' => 'job',
            'trace_id' => $normalized['trace_id'],
            'preview' => $this->nullableString($record['execution_preview'] ?? $record['name'] ?? null),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'status' => $status,
            'exceptions' => (int) ($record['exceptions'] ?? 0),
            'logs' => (int) ($record['logs'] ?? 0),
            'queries' => (int) ($record['queries'] ?? 0),
            'lazy_loads' => (int) ($record['lazy_loads'] ?? 0),
            'jobs_queued' => (int) ($record['jobs_queued'] ?? 0),
            'mail' => (int) ($record['mail'] ?? 0),
            'notifications' => (int) ($record['notifications'] ?? 0),
            'outgoing_requests' => (int) ($record['outgoing_requests'] ?? 0),
            'files_read' => (int) ($record['files_read'] ?? 0),
            'files_written' => (int) ($record['files_written'] ?? 0),
            'cache_events' => (int) ($record['cache_events'] ?? 0),
            'hydrated_models' => (int) ($record['hydrated_models'] ?? 0),
            'peak_memory_bytes' => (int) ($record['peak_memory_usage'] ?? 0),
            'exception_preview' => $this->nullableString($record['exception_preview'] ?? null),
            'context_json' => $this->jsonValue($this->decodeJsonString($record['context'] ?? null)),
        ]);

        DB::table('nw_job_attempt_details')->updateOrInsert(
            ['execution_row_id' => $executionRowId],
            [
                'execution_id' => $normalized['execution_id'],
                'job_id' => (string) ($record['job_id'] ?? ''),
                'attempt' => (int) ($record['attempt'] ?? 1),
                'name' => (string) ($record['name'] ?? ''),
                'connection' => (string) ($record['connection'] ?? ''),
                'queue' => (string) ($record['queue'] ?? ''),
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->upsertJobFromAttempt($normalized, $record);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeScheduledTaskSummary(array $normalized, array $record): void
    {
        $status = (string) ($record['status'] ?? 'processed');

        $executionRowId = $this->upsertExecution($normalized, [
            'source' => 'schedule',
            'trace_id' => $normalized['trace_id'],
            'preview' => $this->nullableString($record['name'] ?? $record['execution_preview'] ?? null),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'status' => $status,
            'exceptions' => (int) ($record['exceptions'] ?? 0),
            'logs' => (int) ($record['logs'] ?? 0),
            'queries' => (int) ($record['queries'] ?? 0),
            'lazy_loads' => (int) ($record['lazy_loads'] ?? 0),
            'jobs_queued' => (int) ($record['jobs_queued'] ?? 0),
            'mail' => (int) ($record['mail'] ?? 0),
            'notifications' => (int) ($record['notifications'] ?? 0),
            'outgoing_requests' => (int) ($record['outgoing_requests'] ?? 0),
            'files_read' => (int) ($record['files_read'] ?? 0),
            'files_written' => (int) ($record['files_written'] ?? 0),
            'cache_events' => (int) ($record['cache_events'] ?? 0),
            'hydrated_models' => (int) ($record['hydrated_models'] ?? 0),
            'peak_memory_bytes' => (int) ($record['peak_memory_usage'] ?? 0),
            'exception_preview' => $this->nullableString($record['exception_preview'] ?? null),
            'context_json' => $this->jsonValue($this->decodeJsonString($record['context'] ?? null)),
        ]);

        DB::table('nw_scheduled_task_details')->updateOrInsert(
            ['execution_row_id' => $executionRowId],
            [
                'execution_id' => $normalized['execution_id'],
                'name' => (string) ($record['name'] ?? ''),
                'cron' => (string) ($record['cron'] ?? ''),
                'timezone' => $this->nullableString($record['timezone'] ?? null),
                'repeat_seconds' => (int) ($record['repeat_seconds'] ?? 0),
                'without_overlapping' => (bool) ($record['without_overlapping'] ?? false),
                'on_one_server' => (bool) ($record['on_one_server'] ?? false),
                'run_in_background' => (bool) ($record['run_in_background'] ?? false),
                'even_in_maintenance_mode' => (bool) ($record['even_in_maintenance_mode'] ?? false),
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeException(array $normalized, array $record): void
    {
        DB::table('nw_exceptions')->insert([
            ...$this->eventContextInsert($normalized),
            'class' => (string) ($record['class'] ?? ''),
            'file' => $this->nullableString($record['file'] ?? null),
            'line' => $this->nullableInt($record['line'] ?? null),
            'message' => (string) ($record['message'] ?? ''),
            'code' => $this->nullableString($record['code'] ?? null),
            'trace_frames_json' => $this->jsonValue($this->decodeJsonString($record['trace'] ?? null)),
            'handled' => (bool) ($record['handled'] ?? false),
            'php_version' => $this->nullableString($record['php_version'] ?? null),
            'laravel_version' => $this->nullableString($record['laravel_version'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeQuery(array $normalized, array $record): void
    {
        DB::table('nw_queries')->insert([
            ...$this->eventContextInsert($normalized),
            'sql' => (string) ($record['sql'] ?? ''),
            'file' => $this->nullableString($record['file'] ?? null),
            'line' => (int) ($record['line'] ?? 0),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'connection' => $this->nullableString($record['connection'] ?? null),
            'connection_type' => (string) ($record['connection_type'] ?? 'unknown'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeOutgoingRequest(array $normalized, array $record): void
    {
        DB::table('nw_outgoing_requests')->insert([
            ...$this->eventContextInsert($normalized),
            'host' => $this->nullableString($record['host'] ?? null),
            'method' => (string) ($record['method'] ?? ''),
            'url' => (string) ($record['url'] ?? ''),
            'status_code' => (int) ($record['status_code'] ?? 0),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'request_bytes' => (int) ($record['request_size'] ?? 0),
            'response_bytes' => (int) ($record['response_size'] ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeQueuedJob(array $normalized, array $record): void
    {
        DB::table('nw_queued_jobs')->insert([
            ...$this->eventContextInsert($normalized),
            'job_id' => (string) ($record['job_id'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'connection' => (string) ($record['connection'] ?? ''),
            'queue' => (string) ($record['queue'] ?? ''),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->upsertJobFromQueue($normalized, $record);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeLog(array $normalized, array $record): void
    {
        DB::table('nw_logs')->insert([
            ...$this->eventContextInsert($normalized),
            'level' => (string) ($record['level'] ?? 'debug'),
            'message' => (string) ($record['message'] ?? ''),
            'context_json' => $this->jsonValue($this->decodeJsonString($record['context'] ?? null)),
            'extra_json' => $this->jsonValue($this->decodeJsonString($record['extra'] ?? null)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeMail(array $normalized, array $record): void
    {
        DB::table('nw_mail_events')->insert([
            ...$this->eventContextInsert($normalized),
            'mailer' => $this->nullableString($record['mailer'] ?? null),
            'class' => $this->nullableString($record['class'] ?? null),
            'subject' => $this->nullableString($record['subject'] ?? null),
            'to_count' => (int) ($record['to'] ?? 0),
            'cc_count' => (int) ($record['cc'] ?? 0),
            'bcc_count' => (int) ($record['bcc'] ?? 0),
            'attachments' => (int) ($record['attachments'] ?? 0),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'failed' => (bool) ($record['failed'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeNotification(array $normalized, array $record): void
    {
        DB::table('nw_notification_events')->insert([
            ...$this->eventContextInsert($normalized),
            'channel' => (string) ($record['channel'] ?? ''),
            'class' => (string) ($record['class'] ?? ''),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'failed' => (bool) ($record['failed'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function storeCacheEvent(array $normalized, array $record): void
    {
        DB::table('nw_cache_events')->insert([
            ...$this->eventContextInsert($normalized),
            'store' => $this->nullableString($record['store'] ?? null),
            'cache_key' => (string) ($record['key'] ?? ''),
            'cache_event_type' => (string) ($record['type'] ?? 'hit'),
            'duration_us' => (int) ($record['duration'] ?? 0),
            'ttl_seconds' => (int) ($record['ttl'] ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $attributes
     */
    private function upsertExecution(array $normalized, array $attributes): int
    {
        $existing = DB::table('nw_executions')
            ->select('id')
            ->where('project_id', $normalized['project_id'])
            ->where('environment', $normalized['environment'])
            ->where('execution_id', $normalized['execution_id'])
            ->first();

        $values = [
            'project_id' => $normalized['project_id'],
            'environment' => $normalized['environment'],
            'batch_id' => $normalized['batch_id'],
            'batch_record_index' => $normalized['batch_record_index'],
            'execution_id' => $normalized['execution_id'],
            'group_hash' => $normalized['group_hash'],
            'occurred_at' => $normalized['occurred_at'],
            'deployment_id' => $normalized['deployment_id'],
            'server_id' => $normalized['server_id'],
            'external_user_id' => $normalized['external_user_id'],
            'updated_at' => now(),
            ...$attributes,
        ];

        if ($existing !== null) {
            DB::table('nw_executions')
                ->where('id', $existing->id)
                ->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('nw_executions')->insertGetId([
            ...$values,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{id: int, project_id: int, environment: string}  $token
     * @param  array<string, mixed>  $record
     */
    private function upsertUser(array $token, CarbonInterface $occurredAt, array $record): void
    {
        $externalUserId = $this->nullableString($record['id'] ?? null);

        if ($externalUserId === null) {
            return;
        }

        $existing = DB::table('nw_users')
            ->where('project_id', $token['project_id'])
            ->where('environment', $token['environment'])
            ->where('external_user_id', $externalUserId)
            ->first();

        if ($existing === null) {
            DB::table('nw_users')->insert([
                'project_id' => $token['project_id'],
                'environment' => $token['environment'],
                'external_user_id' => $externalUserId,
                'name' => $this->nullableString($record['name'] ?? null),
                'username' => $this->nullableString($record['username'] ?? null),
                'first_seen_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('nw_users')
            ->where('id', $existing->id)
            ->update([
                'name' => $this->nullableString($record['name'] ?? null),
                'username' => $this->nullableString($record['username'] ?? null),
                'last_seen_at' => $occurredAt,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function upsertJobFromQueue(array $normalized, array $record): void
    {
        $jobId = (string) ($record['job_id'] ?? '');

        if ($jobId === '') {
            return;
        }

        $existing = DB::table('nw_jobs')
            ->where('project_id', $normalized['project_id'])
            ->where('environment', $normalized['environment'])
            ->where('job_id', $jobId)
            ->first();

        $values = [
            'first_trace_id' => $normalized['trace_id'],
            'enqueued_by_execution_id' => $normalized['execution_id'],
            'first_queued_at' => $normalized['occurred_at'],
            'last_attempt_id' => $existing?->last_attempt_id,
            'last_attempt_at' => $existing?->last_attempt_at,
            'attempt_count' => $existing?->attempt_count ?? 0,
            'last_status' => $existing?->last_status ?? 'queued',
            'total_runtime_us' => $existing?->total_runtime_us ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('nw_jobs')->updateOrInsert(
            [
                'project_id' => $normalized['project_id'],
                'environment' => $normalized['environment'],
                'job_id' => $jobId,
            ],
            $values,
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $record
     */
    private function upsertJobFromAttempt(array $normalized, array $record): void
    {
        $jobId = (string) ($record['job_id'] ?? '');

        if ($jobId === '') {
            return;
        }

        $existing = DB::table('nw_jobs')
            ->where('project_id', $normalized['project_id'])
            ->where('environment', $normalized['environment'])
            ->where('job_id', $jobId)
            ->first();

        DB::table('nw_jobs')->updateOrInsert(
            [
                'project_id' => $normalized['project_id'],
                'environment' => $normalized['environment'],
                'job_id' => $jobId,
            ],
            [
                'first_trace_id' => $existing?->first_trace_id ?? $normalized['trace_id'],
                'enqueued_by_execution_id' => $existing?->enqueued_by_execution_id,
                'first_queued_at' => $existing?->first_queued_at,
                'last_attempt_id' => $normalized['execution_id'],
                'last_attempt_at' => $normalized['occurred_at'],
                'attempt_count' => (int) ($record['attempt'] ?? $existing?->attempt_count ?? 1),
                'last_status' => (string) ($record['status'] ?? 'processed'),
                'total_runtime_us' => (int) (($existing?->total_runtime_us ?? 0) + (int) ($record['duration'] ?? 0)),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function eventContextInsert(array $normalized): array
    {
        return Arr::only($normalized, [
            'batch_id',
            'batch_record_index',
            'project_id',
            'environment',
            'occurred_at',
            'group_hash',
            'trace_id',
            'execution_id',
            'execution_source',
            'execution_stage',
            'deployment_id',
            'server_id',
            'external_user_id',
        ]);
    }

    private function createBatch(
        ?int $projectId,
        ?string $environment,
        ?int $ingestTokenId,
        string $tokenHash,
        string $protocolVersion,
        string $transport,
        int $payloadBytes,
    ): int {
        return (int) DB::table('nw_ingest_batches')->insertGetId([
            'project_id' => $projectId,
            'environment' => $environment,
            'ingest_token_id' => $ingestTokenId,
            'token_hash' => $tokenHash,
            'protocol_version' => $protocolVersion,
            'transport' => $transport,
            'payload_bytes' => $payloadBytes,
            'record_count' => 0,
            'ack_status' => 'received',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markBatchAccepted(int $batchId, int $recordCount): void
    {
        DB::table('nw_ingest_batches')
            ->where('id', $batchId)
            ->update([
                'ack_status' => 'accepted',
                'record_count' => $recordCount,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markBatchRejected(int $batchId, string $message): void
    {
        DB::table('nw_ingest_batches')
            ->where('id', $batchId)
            ->update([
                'ack_status' => 'rejected',
                'parse_error' => $message,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{id: int, project_id: int, environment: string}|null
     */
    private function findToken(string $tokenHash): ?array
    {
        $token = DB::table('nw_ingest_tokens')
            ->join('nw_projects', 'nw_projects.id', '=', 'nw_ingest_tokens.project_id')
            ->select([
                'nw_ingest_tokens.id',
                'nw_ingest_tokens.project_id',
                'nw_ingest_tokens.environment',
            ])
            ->where('nw_ingest_tokens.token_hash', $tokenHash)
            ->where('nw_ingest_tokens.is_active', true)
            ->whereNull('nw_ingest_tokens.revoked_at')
            ->where('nw_projects.is_active', true)
            ->first();

        if ($token === null) {
            return null;
        }

        return [
            'id' => (int) $token->id,
            'project_id' => (int) $token->project_id,
            'environment' => (string) $token->environment,
        ];
    }

    private function touchToken(int $tokenId): void
    {
        DB::table('nw_ingest_tokens')
            ->where('id', $tokenId)
            ->update([
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function resolveServerId(int $projectId, string $environment, mixed $server, CarbonInterface $occurredAt): ?int
    {
        $name = $this->nullableString($server);

        if ($name === null) {
            return null;
        }

        $key = "{$projectId}:{$environment}:{$name}";

        if (array_key_exists($key, $this->serverIds)) {
            return $this->serverIds[$key];
        }

        $existing = DB::table('nw_servers')
            ->where('project_id', $projectId)
            ->where('environment', $environment)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            DB::table('nw_servers')->where('id', $existing->id)->update([
                'last_seen_at' => $occurredAt,
                'updated_at' => now(),
            ]);

            return $this->serverIds[$key] = (int) $existing->id;
        }

        return $this->serverIds[$key] = (int) DB::table('nw_servers')->insertGetId([
            'project_id' => $projectId,
            'environment' => $environment,
            'name' => $name,
            'last_seen_at' => $occurredAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveDeploymentId(int $projectId, string $environment, mixed $deployment, CarbonInterface $occurredAt): ?int
    {
        $name = $this->nullableString($deployment);

        if ($name === null) {
            return null;
        }

        $key = "{$projectId}:{$environment}:{$name}";

        if (array_key_exists($key, $this->deploymentIds)) {
            return $this->deploymentIds[$key];
        }

        $existing = DB::table('nw_deployments')
            ->where('project_id', $projectId)
            ->where('environment', $environment)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            DB::table('nw_deployments')->where('id', $existing->id)->update([
                'last_seen_at' => $occurredAt,
                'updated_at' => now(),
            ]);

            return $this->deploymentIds[$key] = (int) $existing->id;
        }

        return $this->deploymentIds[$key] = (int) DB::table('nw_deployments')->insertGetId([
            'project_id' => $projectId,
            'environment' => $environment,
            'name' => $name,
            'last_seen_at' => $occurredAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveExecutionId(string $eventType, array $record): ?string
    {
        return match ($eventType) {
            'request', 'command', 'scheduled-task' => $this->nullableString($record['execution_id'] ?? $record['trace_id'] ?? null),
            'job-attempt' => $this->nullableString($record['attempt_id'] ?? $record['execution_id'] ?? null),
            default => $this->nullableString($record['execution_id'] ?? null),
        };
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveExecutionSource(string $eventType, array $record): ?string
    {
        return $this->nullableString(match ($eventType) {
            'request' => 'request',
            'command' => 'command',
            'job-attempt' => 'job',
            'scheduled-task' => 'schedule',
            default => $record['execution_source'] ?? null,
        });
    }

    private function timestampToCarbon(mixed $timestamp): CarbonInterface
    {
        if ($timestamp === null || $timestamp === '') {
            return now()->toImmutable();
        }

        $seconds = (float) $timestamp;
        $whole = (int) floor($seconds);
        $micros = (int) round(($seconds - $whole) * 1_000_000);

        return CarbonImmutable::createFromTimestampUTC($whole)->addMicroseconds($micros);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function decodeJsonString(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                '_nightwatch_decode_error' => true,
                '_nightwatch_raw' => $value,
            ];
        }
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function parseRequestPayload(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return ['absent', null];
        }

        $decoded = $this->decodeJsonString($payload);

        if (! is_array($decoded)) {
            return ['present', $decoded];
        }

        $nightwatchError = $decoded['_nightwatch_error'] ?? null;

        return match ($nightwatchError) {
            'NOT_ENABLED' => ['not_enabled', null],
            'UNSUPPORTED_CONTENT_TYPE' => ['unsupported_content_type', null],
            'SERIALIZATION_FAILED' => ['serialization_failed', null],
            default => ['present', $decoded],
        };
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
