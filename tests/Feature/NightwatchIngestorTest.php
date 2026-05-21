<?php

namespace Tests\Feature;

use App\Nightwatch\NightwatchCleanupService;
use App\Nightwatch\NightwatchEventIngestor;
use App\Nightwatch\NightwatchProjectKeyManager;
use App\Nightwatch\NightwatchRollupService;
use App\Nightwatch\Exceptions\UnknownNightwatchTokenException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

use function json_encode;
use function str_repeat;

class NightwatchIngestorTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenHash;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        $secret = 'fixture-secret';
        $this->tokenHash = NightwatchProjectKeyManager::tokenHashForSecret($secret);

        $this->projectId = DB::table('nw_projects')->insertGetId([
            'name' => 'Demo Project',
            'slug' => 'demo-project',
            'is_active' => true,
            'token_hash' => $this->tokenHash,
            'secret_sha256' => NightwatchProjectKeyManager::secretSha256($secret),
            'secret_fingerprint' => NightwatchProjectKeyManager::secretFingerprint(NightwatchProjectKeyManager::secretSha256($secret)),
            'secret_last_four' => 'cret',
            'last_seen_at' => null,
            'tags' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_ingests_a_full_nightwatch_batch_and_builds_rollups(): void
    {
        $baseTime = CarbonImmutable::parse('2026-05-20 10:15:30 UTC');
        $requestTrace = (string) Str::uuid();
        $commandTrace = (string) Str::uuid();
        $scheduleTrace = (string) Str::uuid();
        $jobAttemptId = (string) Str::uuid();

        $records = [
            $this->userEvent($baseTime),
            $this->requestEvent($baseTime, $requestTrace),
            $this->commandEvent($baseTime->addSeconds(3), $commandTrace),
            $this->scheduledTaskEvent($baseTime->addSeconds(6), $scheduleTrace),
            $this->exceptionEvent($baseTime->addMilliseconds(10), $requestTrace, $requestTrace),
            $this->queryEvent($baseTime->addMilliseconds(20), $requestTrace, $requestTrace),
            $this->outgoingRequestEvent($baseTime->addMilliseconds(30), $requestTrace, $requestTrace),
            $this->queuedJobEvent($baseTime->addMilliseconds(40), $requestTrace, $requestTrace, 'job-1'),
            $this->jobAttemptEvent($baseTime->addSeconds(20), $requestTrace, $jobAttemptId, 'job-1'),
            $this->logEvent($baseTime->addMilliseconds(50), $requestTrace, $requestTrace),
            $this->mailEvent($baseTime->addMilliseconds(60), $requestTrace, $requestTrace),
            $this->notificationEvent($baseTime->addMilliseconds(70), $requestTrace, $requestTrace),
            $this->cacheEvent($baseTime->addMilliseconds(80), $requestTrace, $requestTrace),
        ];

        $inserted = app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload($records));

        $this->assertSame(13, $inserted);
        $this->assertDatabaseCount('nw_raw_events', 13);
        $this->assertDatabaseCount('nw_executions', 4);
        $this->assertDatabaseCount('nw_exceptions', 1);
        $this->assertDatabaseCount('nw_queries', 1);
        $this->assertDatabaseCount('nw_outgoing_requests', 1);
        $this->assertDatabaseCount('nw_queued_jobs', 1);
        $this->assertDatabaseCount('nw_logs', 1);
        $this->assertDatabaseCount('nw_mail_events', 1);
        $this->assertDatabaseCount('nw_notification_events', 1);
        $this->assertDatabaseCount('nw_cache_events', 1);
        $this->assertDatabaseHas('nw_ingest_batches', [
            'project_id' => $this->projectId,
            'token_hash' => $this->tokenHash,
            'ack_status' => 'accepted',
        ]);

        $this->assertDatabaseHas('nw_users', [
            'project_id' => $this->projectId,
            'external_user_id' => 'user-1',
            'username' => 'demo@example.com',
        ]);

        $this->assertDatabaseHas('nw_executions', [
            'execution_id' => $requestTrace,
            'source' => 'request',
            'status' => 'ok',
        ]);

        $this->assertDatabaseHas('nw_request_details', [
            'execution_id' => $requestTrace,
            'route_name' => 'orders.show',
            'request_payload_state' => 'absent',
        ]);

        $this->assertDatabaseHas('nw_job_attempt_details', [
            'execution_id' => $jobAttemptId,
            'job_id' => 'job-1',
            'status' => 'processed',
        ]);

        $job = DB::table('nw_jobs')
            ->where('project_id', $this->projectId)
            ->where('job_id', 'job-1')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame($requestTrace, $job->first_trace_id);
        $this->assertSame($requestTrace, $job->enqueued_by_execution_id);
        $this->assertSame($jobAttemptId, $job->last_attempt_id);
        $this->assertSame(1, (int) $job->attempt_count);

        app(NightwatchRollupService::class)->refresh(
            $baseTime->subMinute(),
            $baseTime->addMinute(),
        );

        $this->assertDatabaseHas('nw_request_route_1m', [
            'project_id' => $this->projectId,
            'method' => 'GET',
            'route_name' => 'orders.show',
            'count' => 1,
        ]);

        $this->assertDatabaseHas('nw_command_1m', [
            'project_id' => $this->projectId,
            'name' => 'orders:sync',
            'count' => 1,
        ]);

        $this->assertDatabaseHas('nw_schedule_1m', [
            'project_id' => $this->projectId,
            'cron' => '*/5 * * * *',
            'count' => 1,
        ]);
    }

    public function test_it_allows_the_same_execution_id_for_different_projects(): void
    {
        $sharedTrace = (string) Str::uuid();
        $time = CarbonImmutable::parse('2026-05-20 10:30:00 UTC');
        $secondSecret = 'fixture-secret-two';
        $secondTokenHash = NightwatchProjectKeyManager::tokenHashForSecret($secondSecret);
        $secondProjectId = DB::table('nw_projects')->insertGetId([
            'name' => 'Other Project',
            'slug' => 'other-project',
            'is_active' => true,
            'token_hash' => $secondTokenHash,
            'secret_sha256' => NightwatchProjectKeyManager::secretSha256($secondSecret),
            'secret_fingerprint' => NightwatchProjectKeyManager::secretFingerprint(NightwatchProjectKeyManager::secretSha256($secondSecret)),
            'secret_last_four' => '-two',
            'last_seen_at' => null,
            'tags' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ingestor = app(NightwatchEventIngestor::class);
        $ingestor->ingestWirePayload($this->wirePayload([$this->requestEvent($time, $sharedTrace)]));
        $ingestor->ingestWirePayload($this->wirePayload([$this->requestEvent($time->addSecond(), $sharedTrace)], $secondTokenHash));

        $this->assertSame(2, DB::table('nw_executions')->where('execution_id', $sharedTrace)->count());
        $this->assertDatabaseHas('nw_executions', [
            'project_id' => $this->projectId,
            'execution_id' => $sharedTrace,
        ]);
        $this->assertDatabaseHas('nw_executions', [
            'project_id' => $secondProjectId,
            'execution_id' => $sharedTrace,
        ]);
        $this->assertSame(2, DB::table('nw_request_details')->where('execution_id', $sharedTrace)->count());
    }

    public function test_it_rejects_revoked_or_inactive_keys(): void
    {
        DB::table('nw_projects')
            ->where('id', $this->projectId)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        $this->expectException(UnknownNightwatchTokenException::class);

        try {
            app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload([
                $this->requestEvent(CarbonImmutable::parse('2026-05-20 10:31:00 UTC'), (string) Str::uuid()),
            ]));
        } finally {
            $this->assertDatabaseHas('nw_ingest_batches', [
                'token_hash' => $this->tokenHash,
                'ack_status' => 'rejected',
            ]);
        }
    }

    public function test_it_preserves_fatal_exceptions_without_execution_id(): void
    {
        $time = CarbonImmutable::parse('2026-05-20 10:16:00 UTC');
        $trace = (string) Str::uuid();

        app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload([
            $this->fatalExceptionEvent($time, $trace),
        ]));

        $exception = DB::table('nw_exceptions')->first();

        $this->assertNotNull($exception);
        $this->assertNull($exception->execution_id);
        $this->assertSame('RuntimeException', class_basename($exception->class));
    }

    public function test_it_classifies_request_payload_states(): void
    {
        $baseTime = CarbonImmutable::parse('2026-05-20 10:20:00 UTC');

        $records = [
            $this->requestEvent($baseTime, (string) Str::uuid(), [
                'status_code' => 500,
                'payload' => '',
            ]),
            $this->requestEvent($baseTime->addSecond(), (string) Str::uuid(), [
                'status_code' => 500,
                'payload' => '{"_nightwatch_error":"NOT_ENABLED"}',
            ]),
            $this->requestEvent($baseTime->addSeconds(2), (string) Str::uuid(), [
                'status_code' => 500,
                'payload' => '{"_nightwatch_error":"UNSUPPORTED_CONTENT_TYPE"}',
            ]),
            $this->requestEvent($baseTime->addSeconds(3), (string) Str::uuid(), [
                'status_code' => 500,
                'payload' => '{"_nightwatch_error":"SERIALIZATION_FAILED"}',
            ]),
            $this->requestEvent($baseTime->addSeconds(4), (string) Str::uuid(), [
                'status_code' => 500,
                'payload' => '{"order_id":42,"status":"failed"}',
            ]),
        ];

        app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload($records));

        $states = DB::table('nw_request_details')
            ->orderBy('created_at')
            ->pluck('request_payload_state')
            ->all();

        $this->assertSame([
            'absent',
            'not_enabled',
            'unsupported_content_type',
            'serialization_failed',
            'present',
        ], $states);
    }

    public function test_it_stores_large_truncated_fields_without_loss(): void
    {
        $time = CarbonImmutable::parse('2026-05-20 10:25:00 UTC');
        $trace = (string) Str::uuid();
        $sql = str_repeat('Q', 65535);
        $message = str_repeat('M', 65535);
        $headers = json_encode([
            'x-long-header' => [str_repeat('H', 4000)],
        ], JSON_THROW_ON_ERROR);

        $records = [
            $this->requestEvent($time, $trace, [
                'status_code' => 500,
                'headers' => $headers,
            ]),
            $this->queryEvent($time->addMilliseconds(10), $trace, $trace, [
                'sql' => $sql,
            ]),
            $this->exceptionEvent($time->addMilliseconds(20), $trace, $trace, [
                'message' => $message,
                'trace' => json_encode([
                    ['file' => 'app/Services/Foo.php:12', 'source' => 'Foo::bar()', 'code' => ['12' => str_repeat('C', 2048)]],
                ], JSON_THROW_ON_ERROR),
            ]),
        ];

        app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload($records));

        $storedSql = DB::table('nw_queries')->value('sql');
        $storedMessage = DB::table('nw_exceptions')->value('message');
        $storedHeaders = DB::table('nw_request_details')->value('headers_json');

        $this->assertSame(65535, mb_strlen($storedSql));
        $this->assertSame(65535, mb_strlen($storedMessage));
        $this->assertStringContainsString('x-long-header', json_encode($storedHeaders, JSON_THROW_ON_ERROR));
    }

    public function test_cleanup_removes_expired_facts_and_rollups(): void
    {
        $oldTime = CarbonImmutable::now()->subDays(200);
        $currentTime = CarbonImmutable::now()->subDay();
        $oldTrace = (string) Str::uuid();
        $currentTrace = (string) Str::uuid();

        app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload([
            $this->requestEvent($oldTime, $oldTrace),
            $this->requestEvent($currentTime, $currentTrace),
        ]));

        DB::table('nw_request_route_1m')->insert([
            'bucket_start' => $oldTime->startOfMinute(),
            'project_id' => $this->projectId,
            'method' => 'GET',
            'route_name' => 'orders.show',
            'route_domain' => 'app.test',
            'route_path' => '/orders/{order}',
            'count' => 1,
            'error_count' => 0,
            'failure_count' => 0,
            'sum_duration_us' => 1000,
            'p50_us' => 1000,
            'p95_us' => 1000,
            'p99_us' => 1000,
            'sum_request_bytes' => 10,
            'sum_response_bytes' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(NightwatchCleanupService::class)->cleanup();

        $this->assertGreaterThan(0, $result['raw_events_deleted'] + $result['raw_partitions_dropped']);
        $this->assertDatabaseMissing('nw_executions', ['execution_id' => $oldTrace]);
        $this->assertDatabaseHas('nw_executions', ['execution_id' => $currentTrace]);
        $this->assertDatabaseMissing('nw_request_route_1m', [
            'project_id' => $this->projectId,
            'bucket_start' => $oldTime->startOfMinute(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function wirePayload(array $records, ?string $tokenHash = null): string
    {
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $payload = 'v1:'.($tokenHash ?? $this->tokenHash).':'.$json;

        return strlen($payload).':'.$payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function requestEvent(CarbonImmutable $time, string $trace, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'request',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'request-group-000000000000000000001',
            'trace_id' => $trace,
            'user' => 'user-1',
            'method' => 'GET',
            'url' => 'https://app.test/orders/1',
            'route_name' => 'orders.show',
            'route_methods' => ['GET', 'HEAD'],
            'route_domain' => 'app.test',
            'route_path' => '/orders/{order}',
            'route_action' => 'App\\Http\\Controllers\\OrderController@show',
            'ip' => '127.0.0.1',
            'duration' => 1200,
            'status_code' => 200,
            'request_size' => 128,
            'response_size' => 512,
            'bootstrap' => 100,
            'before_middleware' => 150,
            'action' => 500,
            'render' => 200,
            'after_middleware' => 100,
            'sending' => 100,
            'terminating' => 50,
            'exceptions' => 1,
            'logs' => 1,
            'queries' => 1,
            'lazy_loads' => 0,
            'jobs_queued' => 1,
            'mail' => 1,
            'notifications' => 1,
            'outgoing_requests' => 1,
            'files_read' => 0,
            'files_written' => 0,
            'cache_events' => 1,
            'hydrated_models' => 0,
            'peak_memory_usage' => 4096,
            'exception_preview' => 'Boom',
            'context' => '{"tenant":"acme"}',
            'headers' => '{"accept":["application/json"]}',
            'payload' => '',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandEvent(CarbonImmutable $time, string $trace, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'command',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'worker-1',
            '_group' => 'command-group-000000000000000000001',
            'trace_id' => $trace,
            'class' => 'App\\Console\\Commands\\SyncOrders',
            'name' => 'orders:sync',
            'command' => 'php artisan orders:sync --force',
            'exit_code' => 0,
            'duration' => 2100,
            'bootstrap' => 300,
            'action' => 1600,
            'terminating' => 200,
            'exceptions' => 0,
            'logs' => 0,
            'queries' => 0,
            'lazy_loads' => 0,
            'jobs_queued' => 0,
            'mail' => 0,
            'notifications' => 0,
            'outgoing_requests' => 0,
            'files_read' => 0,
            'files_written' => 0,
            'cache_events' => 0,
            'hydrated_models' => 0,
            'peak_memory_usage' => 2048,
            'exception_preview' => '',
            'context' => '{"scope":"backfill"}',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scheduledTaskEvent(CarbonImmutable $time, string $trace, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'scheduled-task',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'scheduler-1',
            '_group' => 'schedule-group-00000000000000000001',
            'trace_id' => $trace,
            'name' => 'orders:dispatch-sync',
            'cron' => '*/5 * * * *',
            'timezone' => 'UTC',
            'repeat_seconds' => 0,
            'without_overlapping' => true,
            'on_one_server' => true,
            'run_in_background' => false,
            'even_in_maintenance_mode' => false,
            'status' => 'processed',
            'duration' => 1800,
            'exceptions' => 0,
            'logs' => 0,
            'queries' => 0,
            'lazy_loads' => 0,
            'jobs_queued' => 0,
            'mail' => 0,
            'notifications' => 0,
            'outgoing_requests' => 0,
            'files_read' => 0,
            'files_written' => 0,
            'cache_events' => 0,
            'hydrated_models' => 0,
            'peak_memory_usage' => 1024,
            'exception_preview' => '',
            'context' => '{"source":"scheduler"}',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function exceptionEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 3,
            't' => 'exception',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'exception-group-0000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'class' => 'RuntimeException',
            'file' => 'app/Services/OrderService.php',
            'line' => 42,
            'message' => 'Boom',
            'code' => '500',
            'trace' => '[{"file":"app/Services/OrderService.php:42","source":"OrderService->sync()","code":{"42":"throw new RuntimeException();"}}]',
            'handled' => false,
            'php_version' => '8.4.10',
            'laravel_version' => '12.47.0',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fatalExceptionEvent(CarbonImmutable $time, string $trace, array $overrides = []): array
    {
        return array_replace([
            'v' => 3,
            't' => 'exception',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'fatal-group-00000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => '',
            'execution_preview' => 'GET /crash',
            'execution_stage' => 'terminating',
            'user' => 'user-1',
            'class' => 'RuntimeException',
            'file' => 'public/index.php',
            'line' => 12,
            'message' => 'Fatal crash',
            'code' => '500',
            'trace' => '',
            'handled' => false,
            'php_version' => '8.4.10',
            'laravel_version' => '12.47.0',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function queryEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'query',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'query-group-000000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'sql' => 'select * from "orders" where "id" = ? limit 1',
            'file' => 'app/Repositories/OrderRepository.php',
            'line' => 18,
            'duration' => 250,
            'connection' => 'pgsql',
            'connection_type' => 'read',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function outgoingRequestEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'outgoing-request',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'outgoing-group-0000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'host' => 'payments.test',
            'method' => 'POST',
            'url' => 'https://payments.test/charge',
            'duration' => 500,
            'request_size' => 256,
            'response_size' => 1024,
            'status_code' => 201,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function queuedJobEvent(CarbonImmutable $time, string $trace, string $executionId, string $jobId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'queued-job',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'queued-job-group-00000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'job_id' => $jobId,
            'name' => 'App\\Jobs\\SyncOrder',
            'connection' => 'redis',
            'queue' => 'orders',
            'duration' => 75,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function jobAttemptEvent(CarbonImmutable $time, string $trace, string $attemptId, string $jobId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'job-attempt',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'worker-1',
            '_group' => 'job-attempt-group-0000000000000000001',
            'trace_id' => $trace,
            'user' => 'user-1',
            'job_id' => $jobId,
            'attempt_id' => $attemptId,
            'attempt' => 1,
            'name' => 'App\\Jobs\\SyncOrder',
            'connection' => 'redis',
            'queue' => 'orders',
            'status' => 'processed',
            'duration' => 1500,
            'exceptions' => 0,
            'logs' => 0,
            'queries' => 0,
            'lazy_loads' => 0,
            'jobs_queued' => 0,
            'mail' => 0,
            'notifications' => 0,
            'outgoing_requests' => 0,
            'files_read' => 0,
            'files_written' => 0,
            'cache_events' => 0,
            'hydrated_models' => 0,
            'peak_memory_usage' => 3072,
            'exception_preview' => '',
            'context' => '{"worker":"default"}',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function logEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'log',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'level' => 'warning',
            'message' => 'Remote API is slow',
            'context' => '{"provider":"payments"}',
            'extra' => '{"channel":"stack"}',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mailEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'mail',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'mail-group-00000000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'mailer' => 'smtp',
            'class' => 'App\\Mail\\OrderShipped',
            'subject' => 'Order shipped',
            'to' => 1,
            'cc' => 0,
            'bcc' => 0,
            'attachments' => 1,
            'duration' => 400,
            'failed' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function notificationEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'notification',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'notification-group-0000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'channel' => 'mail',
            'class' => 'App\\Notifications\\OrderAlert',
            'duration' => 250,
            'failed' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cacheEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'cache-event',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'cache-group-0000000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'store' => 'redis',
            'key' => 'orders:1',
            'type' => 'hit',
            'duration' => 25,
            'ttl' => 300,
        ], $overrides);
    }

    private function userEvent(CarbonImmutable $time): array
    {
        return [
            'v' => 1,
            't' => 'user',
            'timestamp' => $this->floatTimestamp($time),
            'id' => 'user-1',
            'name' => 'Demo User',
            'username' => 'demo@example.com',
        ];
    }

    private function floatTimestamp(CarbonImmutable $time): float
    {
        return (float) $time->format('U.u');
    }
}
