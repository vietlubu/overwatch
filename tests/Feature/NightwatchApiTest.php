<?php

namespace Tests\Feature;

use App\Nightwatch\NightwatchEventIngestor;
use App\Nightwatch\NightwatchProjectKeyManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

use function json_encode;

class NightwatchApiTest extends TestCase
{
    use RefreshDatabase;

    private int $projectOneId;

    private string $projectOneTokenHash;

    private int $projectTwoId;

    private string $projectTwoTokenHash;

    private bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        ['project_id' => $this->projectOneId, 'token_hash' => $this->projectOneTokenHash] = $this->createProjectWithToken(
            name: 'API Project',
            slug: 'api-project',
            secret: 'api-project-secret',
        );

        ['project_id' => $this->projectTwoId, 'token_hash' => $this->projectTwoTokenHash] = $this->createProjectWithToken(
            name: 'Other Project',
            slug: 'other-project',
            secret: 'other-project-secret',
        );
    }

    public function test_requests_index_returns_grouped_routes_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/requests?project_id={$this->projectOneId}&environment=production&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.route.text', 'GET /orders/{order}');
        $response->assertJsonPath('table.rows.0.requests.text', '3');
        $response->assertJsonPath('table.rows.0.users.text', '2');
        $response->assertJsonPath('table.rows.0.failures.text', '2');
        $response->assertJsonPath('pagination.total', 3);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_request_show_returns_scoped_execution_detail(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/requests/exec-orders-2?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'GET /orders/{order}');
        $response->assertJsonPath('tags.0.text', '502 ERROR');
        $response->assertJsonPath('summaryPanels.0.entries.4.value', 'present');
        $response->assertJsonPath('summaryPanels.1.entries.0.value', '3');
        $response->assertJsonPath('tables.0.rows.0.execution.text', 'exec-orders-3');
        $this->assertStringContainsString('accept: application/json', (string) $response->json('codePanels.0.code'));
    }

    public function test_request_show_returns_not_found_for_unknown_execution(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/requests/missing-execution?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath('message', 'Nightwatch request execution [missing-execution] was not found.');
    }

    public function test_request_show_requires_scope_when_execution_id_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/requests/duplicate-execution');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Execution id [duplicate-execution] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_exceptions_index_returns_grouped_statuses(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/exceptions?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.exception.text', 'Charge gateway timeout');
        $response->assertJsonPath('table.rows.0.status.text', 'unhandled');
        $response->assertJsonPath('pagination.total', 2);
        $this->assertStringContainsString('2 occurrence(s)', (string) $response->json('table.rows.0.exception.meta'));
    }

    public function test_exception_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $response = $this->getJson("/api/exceptions/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'Charge gateway timeout');
        $response->assertJsonPath('metrics.0.value', '2 occurrences');
        $response->assertJsonPath('summaryPanels.0.entries.1.value', 'API Project');
        $response->assertJsonPath('tables.0.rows.0.execution.text', 'exec-orders-3');
        $this->assertStringContainsString('RuntimeException: Charge gateway timeout', (string) $response->json('codePanels.0.code'));
    }

    public function test_exception_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/exceptions/cccccccccccccccccccccccccccccccc?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch exception group [cccccccccccccccccccccccccccccccc] was not found.',
        );
    }

    public function test_exception_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/exceptions/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Exception group hash [bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    private function seedFixtures(): void
    {
        if ($this->seeded) {
            return;
        }

        $this->seeded = true;
        $baseTime = CarbonImmutable::now()->subMinutes(40);
        $groupHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $duplicateGroupHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $this->ingest($this->projectOneTokenHash, [
            $this->userEvent($baseTime, 'user-1', 'Alice Nguyen', 'alice@example.com'),
            $this->userEvent($baseTime->addSecond(), 'user-2', 'Bob Tran', 'bob@example.com'),
            $this->requestEvent($baseTime->addMinute(), 'duplicate-execution', [
                'user' => 'user-1',
                'url' => 'https://app.test/duplicate-one',
                'route_name' => 'duplicate.one',
                'route_path' => '/duplicate-one',
                'status_code' => 200,
                'duration' => 1300,
                'exceptions' => 1,
                'exception_preview' => 'Duplicate scope exception',
            ]),
            $this->exceptionEvent($baseTime->addMinutes(2), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateGroupHash,
                'message' => 'Duplicate scope exception',
                'file' => 'app/Services/ScopeService.php',
                'line' => 12,
                'handled' => false,
            ]),
            $this->requestEvent($baseTime->addMinutes(10), 'exec-orders-1', [
                'user' => 'user-1',
                'url' => 'https://app.test/orders/1',
                'route_name' => 'orders.show',
                'route_path' => '/orders/{order}',
                'status_code' => 200,
                'duration' => 1200,
                'exceptions' => 0,
                'queries' => 1,
                'logs' => 0,
                'notifications' => 0,
                'outgoing_requests' => 0,
                'cache_events' => 0,
                'exception_preview' => '',
            ]),
            $this->requestEvent($baseTime->addMinutes(20), 'exec-orders-2', [
                'user' => 'user-1',
                'url' => 'https://app.test/orders/2',
                'route_name' => 'orders.show',
                'route_path' => '/orders/{order}',
                'status_code' => 502,
                'duration' => 2400,
                'queries' => 3,
                'logs' => 1,
                'notifications' => 1,
                'outgoing_requests' => 1,
                'cache_events' => 1,
                'exception_preview' => 'Charge gateway timeout',
                'headers' => '{"accept":["application/json"],"content-type":["application/json"]}',
                'payload' => '{"order_id":42,"retry":true}',
            ]),
            $this->queryEvent($baseTime->addMinutes(20)->addMilliseconds(10), 'exec-orders-2', 'exec-orders-2', [
                'sql' => 'select * from "payments" where "order_id" = ?',
                'duration' => 480,
            ]),
            $this->logEvent($baseTime->addMinutes(20)->addMilliseconds(20), 'exec-orders-2', 'exec-orders-2'),
            $this->outgoingRequestEvent($baseTime->addMinutes(20)->addMilliseconds(30), 'exec-orders-2', 'exec-orders-2'),
            $this->notificationEvent($baseTime->addMinutes(20)->addMilliseconds(40), 'exec-orders-2', 'exec-orders-2'),
            $this->cacheEvent($baseTime->addMinutes(20)->addMilliseconds(50), 'exec-orders-2', 'exec-orders-2'),
            $this->exceptionEvent($baseTime->addMinutes(20)->addMilliseconds(60), 'exec-orders-2', 'exec-orders-2', [
                '_group' => $groupHash,
                'message' => 'Charge gateway timeout',
                'file' => 'app/Services/BillingService.php',
                'line' => 51,
                'handled' => true,
                'trace' => '[{"file":"app/Services/BillingService.php:51","source":"BillingService->charge()","code":{"51":"throw new RuntimeException(\"Charge gateway timeout\");"}}]',
            ]),
            $this->requestEvent($baseTime->addMinutes(30), 'exec-billing-1', [
                'user' => 'user-2',
                'method' => 'POST',
                'url' => 'https://app.test/billing/charge',
                'route_name' => 'billing.charge',
                'route_path' => '/billing/charge',
                'status_code' => 201,
                'duration' => 4100,
                'exceptions' => 0,
                'queries' => 0,
                'logs' => 0,
                'notifications' => 0,
                'outgoing_requests' => 0,
                'cache_events' => 0,
                'exception_preview' => '',
            ]),
            $this->requestEvent($baseTime->addMinutes(35), 'exec-orders-3', [
                'user' => 'user-2',
                'url' => 'https://app.test/orders/3',
                'route_name' => 'orders.show',
                'route_path' => '/orders/{order}',
                'status_code' => 500,
                'duration' => 1800,
                'queries' => 2,
                'exception_preview' => 'Charge gateway timeout',
            ]),
            $this->exceptionEvent($baseTime->addMinutes(35)->addMilliseconds(10), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $groupHash,
                'message' => 'Charge gateway timeout',
                'file' => 'app/Services/BillingService.php',
                'line' => 51,
                'handled' => false,
                'trace' => '[{"file":"app/Services/BillingService.php:51","source":"BillingService->charge()","code":{"51":"throw new RuntimeException(\"Charge gateway timeout\");"}}]',
            ]),
        ]);

        $this->ingest($this->projectTwoTokenHash, [
            $this->userEvent($baseTime, 'user-9', 'Carol Vu', 'carol@example.com'),
            $this->requestEvent($baseTime->addMinutes(5), 'duplicate-execution', [
                'user' => 'user-9',
                'url' => 'https://other.test/duplicate-two',
                'route_name' => 'duplicate.two',
                'route_domain' => 'other.test',
                'route_path' => '/duplicate-two',
                'status_code' => 500,
                'duration' => 1600,
                'exceptions' => 1,
                'exception_preview' => 'Duplicate scope exception',
            ]),
            $this->exceptionEvent($baseTime->addMinutes(6), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateGroupHash,
                'message' => 'Duplicate scope exception',
                'file' => 'app/Services/ScopeService.php',
                'line' => 12,
                'handled' => false,
            ]),
        ]);
    }

    /**
     * @return array{project_id: int, token_hash: string}
     */
    private function createProjectWithToken(string $name, string $slug, string $secret): array
    {
        $projectId = DB::table('nw_projects')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tokenHash = NightwatchProjectKeyManager::tokenHashForSecret($secret);

        DB::table('nw_ingest_tokens')->insert([
            'project_id' => $projectId,
            'environment' => 'production',
            'token_hash' => $tokenHash,
            'key_name' => 'primary',
            'secret_sha256' => NightwatchProjectKeyManager::secretSha256($secret),
            'secret_fingerprint' => NightwatchProjectKeyManager::secretFingerprint(NightwatchProjectKeyManager::secretSha256($secret)),
            'secret_last_four' => substr($secret, -4),
            'is_active' => true,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'project_id' => $projectId,
            'token_hash' => $tokenHash,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function ingest(string $tokenHash, array $records): void
    {
        app(NightwatchEventIngestor::class)->ingestWirePayload($this->wirePayload($records, $tokenHash));
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function wirePayload(array $records, string $tokenHash): string
    {
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $payload = 'v1:'.$tokenHash.':'.$json;

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
            'peak_memory_usage' => 4096,
            'exception_preview' => '',
            'context' => '{"tenant":"acme"}',
            'headers' => '{"accept":["application/json"]}',
            'payload' => '',
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

    /**
     * @return array<string, mixed>
     */
    private function userEvent(CarbonImmutable $time, string $id, string $name, string $username): array
    {
        return [
            'v' => 1,
            't' => 'user',
            'timestamp' => $this->floatTimestamp($time),
            'id' => $id,
            'name' => $name,
            'username' => $username,
        ];
    }

    private function floatTimestamp(CarbonImmutable $time): float
    {
        return (float) $time->format('U.u');
    }
}
