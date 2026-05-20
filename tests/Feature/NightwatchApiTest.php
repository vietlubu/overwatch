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

    public function test_jobs_index_returns_filtered_jobs_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/jobs?project_id={$this->projectOneId}&environment=production&search=SendReceipt&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.job.text', 'App\\Jobs\\SendReceipt');
        $response->assertJsonPath('table.rows.0.attempts.text', '1');
        $response->assertJsonPath('table.rows.0.status.text', 'failed');
        $response->assertJsonPath('table.rows.0.queue.text', 'billing');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_job_show_returns_scoped_job_detail(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/jobs/job-sync-order?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'App\\Jobs\\SyncOrder');
        $response->assertJsonPath('tags.0.text', 'processed');
        $response->assertJsonPath('summaryPanels.0.entries.1.value', 'redis');
        $response->assertJsonPath('summaryPanels.1.entries.2.value', '2');
        $response->assertJsonPath('tables.0.rows.0.attempt.text', 'attempt #1');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '1.80ms');
        $this->assertStringContainsString('"worker": "orders"', (string) $response->json('codePanels.0.code'));
    }

    public function test_job_show_returns_not_found_for_unknown_job(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/jobs/missing-job?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath('message', 'Nightwatch job [missing-job] was not found.');
    }

    public function test_job_show_requires_scope_when_job_id_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/jobs/duplicate-job');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Job id [duplicate-job] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_commands_index_returns_grouped_commands_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/commands?project_id={$this->projectOneId}&environment=production&search=send-scheduled-notification&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.command.text', 'app:send-scheduled-notification');
        $response->assertJsonPath('table.rows.0.status.text', 'successful');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_command_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = 'cccccccccccccccccccccccccccccccc';
        $response = $this->getJson("/api/commands/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'app:send-scheduled-notification');
        $response->assertJsonPath('tags.0.text', 'successful');
        $response->assertJsonPath('summaryPanels.0.entries.1.value', 'App\\Console\\Commands\\SendScheduledNotification');
        $response->assertJsonPath('summaryPanels.0.entries.3.value', 'command');
        $response->assertJsonPath('tables.0.rows.0.run.meta', 'exec cmd-send-scheduled-2');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '561.67ms');
        $this->assertStringContainsString('php artisan app:send-scheduled-notification', (string) $response->json('codePanels.0.code'));
    }

    public function test_command_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/commands/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch command group [eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee] was not found.',
        );
    }

    public function test_command_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/commands/dddddddddddddddddddddddddddddddd');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Command group hash [dddddddddddddddddddddddddddddddd] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_scheduled_tasks_index_returns_grouped_tasks_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/scheduled-tasks?project_id={$this->projectOneId}&environment=production&search=send-scheduled-notification&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.task.text', 'php artisan app:send-scheduled-notification');
        $response->assertJsonPath('table.rows.0.schedule.text', '*/5 * * * *');
        $response->assertJsonPath('table.rows.0.status.text', 'processed');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_scheduled_task_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = 'ffffffffffffffffffffffffffffffff';
        $response = $this->getJson("/api/scheduled-tasks/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'php artisan app:send-scheduled-notification');
        $response->assertJsonPath('tags.0.text', 'processed');
        $response->assertJsonPath('summaryPanels.0.entries.1.value', 'Asia/Ho_Chi_Minh');
        $response->assertJsonPath('summaryPanels.1.entries.3.value', '1');
        $response->assertJsonPath('tables.0.rows.0.run.meta', 'exec sched-send-notification-2');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '633.66ms');
        $this->assertStringContainsString('"source": "scheduler"', (string) $response->json('codePanels.0.code'));
    }

    public function test_scheduled_task_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/scheduled-tasks/12121212121212121212121212121212?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch scheduled task group [12121212121212121212121212121212] was not found.',
        );
    }

    public function test_scheduled_task_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/scheduled-tasks/abababababababababababababababab');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Scheduled task group hash [abababababababababababababababab] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_queries_index_returns_grouped_queries_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/queries?project_id={$this->projectOneId}&environment=production&search=activity_logs&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.query.meta', 'app/Models/ActivityLog.php:44');
        $response->assertJsonPath('table.rows.0.connection.text', 'pgsql / write');
        $response->assertJsonPath('table.rows.0.calls.text', '2');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_query_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = '99999999999999999999999999999999';
        $response = $this->getJson("/api/queries/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $this->assertStringContainsString('insert into activity_logs', (string) $response->json('title'));
        $response->assertJsonPath('tags.0.text', 'write');
        $response->assertJsonPath('tags.1.text', 'pgsql');
        $response->assertJsonPath('summaryPanels.0.entries.0.value', 'app/Models/ActivityLog.php');
        $response->assertJsonPath('summaryPanels.0.entries.2.value', 'write');
        $response->assertJsonPath('tables.0.rows.0.call.meta', 'GET /orders/{order} · exec-orders-3');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '26.27ms');
        $this->assertStringContainsString('activity_logs', (string) $response->json('codePanels.0.code'));
    }

    public function test_query_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/queries/56565656565656565656565656565656?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch query group [56565656565656565656565656565656] was not found.',
        );
    }

    public function test_query_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/queries/78787878787878787878787878787878');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Query group hash [78787878787878787878787878787878] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_notifications_index_returns_grouped_notifications_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/notifications?project_id={$this->projectOneId}&environment=production&search=PostViewed&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.notification.text', 'App\\Notifications\\PostViewed');
        $response->assertJsonPath('table.rows.0.channel.text', 'database');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_notification_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = '31313131313131313131313131313131';
        $response = $this->getJson("/api/notifications/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'App\\Notifications\\PostViewed');
        $response->assertJsonPath('tags.0.text', 'database');
        $response->assertJsonPath('tags.1.text', 'successful');
        $response->assertJsonPath('summaryPanels.0.entries.0.value', 'database');
        $response->assertJsonPath('summaryPanels.1.entries.0.value', 'exec-orders-3');
        $response->assertJsonPath('tables.0.rows.0.event.meta', 'GET /orders/{order} · exec-orders-3');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '14.42ms');
    }

    public function test_notification_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/notifications/34343434343434343434343434343434?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch notification group [34343434343434343434343434343434] was not found.',
        );
    }

    public function test_notification_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/notifications/32323232323232323232323232323232');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Notification group hash [32323232323232323232323232323232] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_mail_index_returns_grouped_mail_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/mail?project_id={$this->projectOneId}&environment=production&search=Weekly%20digest&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.mail.text', 'Weekly digest');
        $response->assertJsonPath('table.rows.0.recipients.text', '5');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_mail_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = '41414141414141414141414141414141';
        $response = $this->getJson("/api/mail/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'Weekly digest');
        $response->assertJsonPath('tags.0.text', 'smtp');
        $response->assertJsonPath('tags.1.text', 'sent');
        $response->assertJsonPath('summaryPanels.0.entries.0.value', 'App\\Mail\\WeeklyDigestMail');
        $response->assertJsonPath('summaryPanels.0.entries.2.value', '2');
        $response->assertJsonPath('tables.0.rows.0.message.meta', 'GET /orders/{order} · exec-orders-3');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '16.42ms');
    }

    public function test_mail_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/mail/43434343434343434343434343434343?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch mail group [43434343434343434343434343434343] was not found.',
        );
    }

    public function test_mail_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/mail/42424242424242424242424242424242');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Mail group hash [42424242424242424242424242424242] exists in multiple project/environment scopes. Pass project_id and environment.',
        );
    }

    public function test_cache_index_returns_grouped_keys_and_pagination(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/cache?project_id={$this->projectOneId}&environment=production&search=lightyear&per_page=1");

        $response->assertOk();
        $response->assertJsonPath('kind', 'collection');
        $response->assertJsonPath('table.rows.0.key.text', 'lightyear:cache:session:9f0b');
        $response->assertJsonPath('table.rows.0.store.text', 'redis');
        $response->assertJsonPath('table.rows.0.events.text', '2');
        $response->assertJsonPath('table.rows.0.type.text', 'miss');
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonCount(1, 'table.rows');
    }

    public function test_cache_show_returns_scoped_group_detail(): void
    {
        $this->seedFixtures();

        $groupHash = '51515151515151515151515151515151';
        $response = $this->getJson("/api/cache/{$groupHash}?project_id={$this->projectOneId}&environment=production");

        $response->assertOk();
        $response->assertJsonPath('title', 'lightyear:cache:session:9f0b');
        $response->assertJsonPath('tags.0.text', 'redis');
        $response->assertJsonPath('tags.1.text', 'miss');
        $response->assertJsonPath('summaryPanels.0.entries.1.value', '60s');
        $response->assertJsonPath('summaryPanels.0.entries.2.value', '2');
        $response->assertJsonPath('tables.0.rows.0.event.meta', 'GET /orders/{order} · exec-orders-3');
        $response->assertJsonPath('tables.0.rows.0.duration.text', '310μs');
    }

    public function test_cache_show_returns_not_found_for_unknown_group(): void
    {
        $this->seedFixtures();

        $response = $this->getJson("/api/cache/53535353535353535353535353535353?project_id={$this->projectOneId}&environment=production");

        $response->assertNotFound();
        $response->assertJsonPath(
            'message',
            'Nightwatch cache group [53535353535353535353535353535353] was not found.',
        );
    }

    public function test_cache_show_requires_scope_when_group_hash_is_ambiguous(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/cache/52525252525252525252525252525252');

        $response->assertStatus(409);
        $response->assertJsonPath(
            'message',
            'Cache group hash [52525252525252525252525252525252] exists in multiple project/environment scopes. Pass project_id and environment.',
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
        $commandGroupHash = 'cccccccccccccccccccccccccccccccc';
        $duplicateCommandGroupHash = 'dddddddddddddddddddddddddddddddd';
        $scheduledTaskGroupHash = 'ffffffffffffffffffffffffffffffff';
        $duplicateScheduledTaskGroupHash = 'abababababababababababababababab';
        $queryGroupHash = '99999999999999999999999999999999';
        $duplicateQueryGroupHash = '78787878787878787878787878787878';
        $notificationGroupHash = '31313131313131313131313131313131';
        $duplicateNotificationGroupHash = '32323232323232323232323232323232';
        $mailGroupHash = '41414141414141414141414141414141';
        $duplicateMailGroupHash = '42424242424242424242424242424242';
        $cacheGroupHash = '51515151515151515151515151515151';
        $duplicateCacheGroupHash = '52525252525252525252525252525252';

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
            $this->commandEvent($baseTime->addMinutes(3), 'duplicate-command-run-one', [
                '_group' => $duplicateCommandGroupHash,
                'name' => 'nightwatch:rollup',
                'class' => 'App\\Console\\Commands\\NightwatchRollupCommand',
                'command' => 'php artisan nightwatch:rollup',
                'duration' => 312180,
            ]),
            $this->scheduledTaskEvent($baseTime->addMinutes(4), 'duplicate-schedule-run-one', [
                '_group' => $duplicateScheduledTaskGroupHash,
                'name' => 'php artisan nightwatch:rollup',
                'cron' => '*/1 * * * *',
                'timezone' => 'UTC',
                'status' => 'processed',
                'duration' => 401840,
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
            $this->commandEvent($baseTime->addMinutes(11), 'cmd-send-scheduled-1', [
                '_group' => $commandGroupHash,
                'execution_source' => 'schedule',
                'name' => 'app:send-scheduled-notification',
                'class' => 'App\\Console\\Commands\\SendScheduledNotification',
                'command' => 'php artisan app:send-scheduled-notification',
                'duration' => 514980,
                'queries' => 1,
                'context' => '{"scope":"scheduler"}',
            ]),
            $this->commandEvent($baseTime->addMinutes(12), 'cmd-send-scheduled-2', [
                '_group' => $commandGroupHash,
                'execution_source' => 'schedule',
                'name' => 'app:send-scheduled-notification',
                'class' => 'App\\Console\\Commands\\SendScheduledNotification',
                'command' => 'php artisan app:send-scheduled-notification',
                'duration' => 561670,
                'queries' => 2,
                'context' => '{"scope":"scheduler"}',
            ]),
            $this->commandEvent($baseTime->addMinutes(13), 'cmd-cache-warm-1', [
                '_group' => 'edededededededededededededededed',
                'name' => 'cache:warm-home',
                'class' => 'App\\Console\\Commands\\WarmHomepageCache',
                'command' => 'php artisan cache:warm-home',
                'exit_code' => 1,
                'duration' => 676720,
                'exception_preview' => 'Cache warm failed',
                'context' => '{"scope":"homepage"}',
            ]),
            $this->scheduledTaskEvent($baseTime->addMinutes(14), 'sched-send-notification-1', [
                '_group' => $scheduledTaskGroupHash,
                'name' => 'php artisan app:send-scheduled-notification',
                'cron' => '*/5 * * * *',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'without_overlapping' => true,
                'on_one_server' => false,
                'run_in_background' => false,
                'status' => 'processed',
                'duration' => 576520,
                'jobs_queued' => 0,
                'context' => '{"source":"scheduler"}',
            ]),
            $this->scheduledTaskEvent($baseTime->addMinutes(15), 'sched-send-notification-2', [
                '_group' => $scheduledTaskGroupHash,
                'name' => 'php artisan app:send-scheduled-notification',
                'cron' => '*/5 * * * *',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'without_overlapping' => true,
                'on_one_server' => false,
                'run_in_background' => false,
                'status' => 'processed',
                'duration' => 633660,
                'jobs_queued' => 1,
                'context' => '{"source":"scheduler"}',
            ]),
            $this->scheduledTaskEvent($baseTime->addMinutes(16), 'sched-cache-prune-1', [
                '_group' => 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
                'name' => 'php artisan cache:prune-stale-tags',
                'cron' => '*/15 * * * *',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'without_overlapping' => false,
                'on_one_server' => true,
                'run_in_background' => true,
                'status' => 'skipped',
                'duration' => 185120,
                'context' => '{"source":"scheduler"}',
            ]),
            $this->queuedJobEvent($baseTime->addMinutes(8), 'duplicate-trace-one', 'duplicate-execution', 'duplicate-job', [
                'name' => 'App\\Jobs\\DuplicateScopeJob',
                'queue' => 'shared',
            ]),
            $this->jobAttemptEvent($baseTime->addMinutes(9), 'duplicate-trace-one', 'duplicate-job-attempt-one', 'duplicate-job', [
                'name' => 'App\\Jobs\\DuplicateScopeJob',
                'queue' => 'shared',
                'status' => 'processed',
                'duration' => 1200,
                'context' => '{"worker":"shared"}',
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
                'mail' => 1,
                'exception_preview' => 'Charge gateway timeout',
                'headers' => '{"accept":["application/json"],"content-type":["application/json"]}',
                'payload' => '{"order_id":42,"retry":true}',
            ]),
            $this->queryEvent($baseTime->addMinutes(20)->addMilliseconds(10), 'exec-orders-2', 'exec-orders-2', [
                'sql' => 'select * from "payments" where "order_id" = ?',
                'duration' => 480,
            ]),
            $this->queryEvent($baseTime->addMinutes(20)->addMilliseconds(11), 'exec-orders-2', 'exec-orders-2', [
                '_group' => $queryGroupHash,
                'sql' => 'insert into activity_logs (`user_id`, `type`, `data`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
                'file' => 'app/Models/ActivityLog.php',
                'line' => 44,
                'duration' => 6620,
                'connection' => 'pgsql',
                'connection_type' => 'write',
            ]),
            $this->queuedJobEvent($baseTime->addMinutes(21), 'job-trace-sync-order', 'exec-orders-2', 'job-sync-order', [
                'name' => 'App\\Jobs\\SyncOrder',
                'queue' => 'orders',
                'duration' => 90,
            ]),
            $this->jobAttemptEvent($baseTime->addMinutes(22), 'job-trace-sync-order', 'job-attempt-sync-order', 'job-sync-order', [
                'name' => 'App\\Jobs\\SyncOrder',
                'queue' => 'orders',
                'status' => 'processed',
                'duration' => 1800,
                'queries' => 2,
                'logs' => 1,
                'peak_memory_usage' => 8192,
                'context' => '{"worker":"orders"}',
            ]),
            $this->logEvent($baseTime->addMinutes(20)->addMilliseconds(20), 'exec-orders-2', 'exec-orders-2'),
            $this->outgoingRequestEvent($baseTime->addMinutes(20)->addMilliseconds(30), 'exec-orders-2', 'exec-orders-2'),
            $this->cacheEvent($baseTime->addMinutes(20)->addMilliseconds(50), 'exec-orders-2', 'exec-orders-2', [
                '_group' => $cacheGroupHash,
                'store' => 'redis',
                'key' => 'lightyear:cache:session:9f0b',
                'type' => 'write',
                'duration' => 910,
                'ttl' => 60,
            ]),
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
                'mail' => 1,
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
                'notifications' => 1,
                'mail' => 1,
                'cache_events' => 1,
                'exception_preview' => 'Charge gateway timeout',
            ]),
            $this->queuedJobEvent($baseTime->addMinutes(36), 'job-trace-send-receipt', 'exec-orders-3', 'job-send-receipt', [
                'name' => 'App\\Jobs\\SendReceipt',
                'connection' => 'sqs',
                'queue' => 'billing',
                'duration' => 110,
            ]),
            $this->queryEvent($baseTime->addMinutes(35)->addMilliseconds(5), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $queryGroupHash,
                'sql' => 'insert into activity_logs (`user_id`, `type`, `data`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
                'file' => 'app/Models/ActivityLog.php',
                'line' => 44,
                'duration' => 26270,
                'connection' => 'pgsql',
                'connection_type' => 'write',
            ]),
            $this->notificationEvent($baseTime->addMinutes(20)->addMilliseconds(40), 'exec-orders-2', 'exec-orders-2', [
                '_group' => $notificationGroupHash,
                'channel' => 'database',
                'class' => 'App\\Notifications\\PostViewed',
                'duration' => 7910,
            ]),
            $this->notificationEvent($baseTime->addMinutes(35)->addMilliseconds(20), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $notificationGroupHash,
                'channel' => 'database',
                'class' => 'App\\Notifications\\PostViewed',
                'duration' => 14420,
            ]),
            $this->mailEvent($baseTime->addMinutes(20)->addMilliseconds(45), 'exec-orders-2', 'exec-orders-2', [
                '_group' => $mailGroupHash,
                'mailer' => 'smtp',
                'class' => 'App\\Mail\\WeeklyDigestMail',
                'subject' => 'Weekly digest',
                'to' => 3,
                'duration' => 18550,
            ]),
            $this->mailEvent($baseTime->addMinutes(35)->addMilliseconds(25), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $mailGroupHash,
                'mailer' => 'smtp',
                'class' => 'App\\Mail\\WeeklyDigestMail',
                'subject' => 'Weekly digest',
                'to' => 2,
                'duration' => 16420,
            ]),
            $this->cacheEvent($baseTime->addMinutes(35)->addMilliseconds(30), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $cacheGroupHash,
                'store' => 'redis',
                'key' => 'lightyear:cache:session:9f0b',
                'type' => 'miss',
                'duration' => 310,
                'ttl' => 60,
            ]),
            $this->jobAttemptEvent($baseTime->addMinutes(37), 'job-trace-send-receipt', 'job-attempt-send-receipt', 'job-send-receipt', [
                'name' => 'App\\Jobs\\SendReceipt',
                'connection' => 'sqs',
                'queue' => 'billing',
                'status' => 'failed',
                'duration' => 2600,
                'exceptions' => 1,
                'exception_preview' => 'Receipt mail transport failed',
                'context' => '{"worker":"billing"}',
            ]),
            $this->queuedJobEvent($baseTime->addMinutes(38), 'job-trace-fanout', 'exec-orders-3', 'job-fanout-analytics', [
                'name' => 'App\\Jobs\\FanOutAnalytics',
                'queue' => 'analytics',
                'duration' => 75,
            ]),
            $this->exceptionEvent($baseTime->addMinutes(35)->addMilliseconds(10), 'exec-orders-3', 'exec-orders-3', [
                '_group' => $groupHash,
                'message' => 'Charge gateway timeout',
                'file' => 'app/Services/BillingService.php',
                'line' => 51,
                'handled' => false,
                'trace' => '[{"file":"app/Services/BillingService.php:51","source":"BillingService->charge()","code":{"51":"throw new RuntimeException(\"Charge gateway timeout\");"}}]',
            ]),
            $this->notificationEvent($baseTime->addMinutes(6)->addMilliseconds(20), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateNotificationGroupHash,
                'channel' => 'mail',
                'class' => 'App\\Notifications\\DuplicateScopeAlert',
                'duration' => 1800,
            ]),
            $this->mailEvent($baseTime->addMinutes(6)->addMilliseconds(25), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateMailGroupHash,
                'mailer' => 'smtp',
                'class' => 'App\\Mail\\DuplicateDigestMail',
                'subject' => 'Duplicate digest',
                'to' => 1,
                'duration' => 2200,
            ]),
            $this->cacheEvent($baseTime->addMinutes(6)->addMilliseconds(30), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateCacheGroupHash,
                'store' => 'redis',
                'key' => 'duplicate:cache:1',
                'type' => 'write',
                'duration' => 420,
                'ttl' => 30,
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
            $this->commandEvent($baseTime->addMinutes(3), 'duplicate-command-run-two', [
                '_group' => $duplicateCommandGroupHash,
                'name' => 'nightwatch:rollup',
                'class' => 'App\\Console\\Commands\\NightwatchRollupCommand',
                'command' => 'php artisan nightwatch:rollup',
                'duration' => 401840,
            ]),
            $this->scheduledTaskEvent($baseTime->addMinutes(4), 'duplicate-schedule-run-two', [
                '_group' => $duplicateScheduledTaskGroupHash,
                'name' => 'php artisan nightwatch:rollup',
                'cron' => '*/1 * * * *',
                'timezone' => 'UTC',
                'status' => 'processed',
                'duration' => 390110,
            ]),
            $this->queryEvent($baseTime->addMinutes(5)->addMilliseconds(10), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateQueryGroupHash,
                'sql' => 'update jobs set reserved_at = ? where id = ?',
                'file' => 'app/Queue/Worker.php',
                'line' => 120,
                'duration' => 80900,
                'connection' => 'pgsql',
                'connection_type' => 'write',
            ]),
            $this->queuedJobEvent($baseTime->addMinutes(7), 'duplicate-trace-two', 'duplicate-execution', 'duplicate-job', [
                'name' => 'App\\Jobs\\DuplicateScopeJob',
                'queue' => 'shared',
            ]),
            $this->jobAttemptEvent($baseTime->addMinutes(8), 'duplicate-trace-two', 'duplicate-job-attempt-two', 'duplicate-job', [
                'name' => 'App\\Jobs\\DuplicateScopeJob',
                'queue' => 'shared',
                'status' => 'processed',
                'duration' => 1300,
                'context' => '{"worker":"shared-2"}',
            ]),
            $this->exceptionEvent($baseTime->addMinutes(6), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateGroupHash,
                'message' => 'Duplicate scope exception',
                'file' => 'app/Services/ScopeService.php',
                'line' => 12,
                'handled' => false,
            ]),
            $this->notificationEvent($baseTime->addMinutes(6)->addMilliseconds(20), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateNotificationGroupHash,
                'channel' => 'mail',
                'class' => 'App\\Notifications\\DuplicateScopeAlert',
                'duration' => 2400,
            ]),
            $this->mailEvent($baseTime->addMinutes(6)->addMilliseconds(25), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateMailGroupHash,
                'mailer' => 'smtp',
                'class' => 'App\\Mail\\DuplicateDigestMail',
                'subject' => 'Duplicate digest',
                'to' => 1,
                'duration' => 2600,
            ]),
            $this->cacheEvent($baseTime->addMinutes(6)->addMilliseconds(30), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateCacheGroupHash,
                'store' => 'redis',
                'key' => 'duplicate:cache:1',
                'type' => 'miss',
                'duration' => 510,
                'ttl' => 30,
            ]),
        ]);

        $this->ingest($this->projectOneTokenHash, [
            $this->queryEvent($baseTime->addMinutes(6), 'duplicate-execution', 'duplicate-execution', [
                '_group' => $duplicateQueryGroupHash,
                'sql' => 'update jobs set reserved_at = ? where id = ?',
                'file' => 'app/Queue/Worker.php',
                'line' => 120,
                'duration' => 70200,
                'connection' => 'pgsql',
                'connection_type' => 'write',
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
    private function mailEvent(CarbonImmutable $time, string $trace, string $executionId, array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            't' => 'mail',
            'timestamp' => $this->floatTimestamp($time),
            'deploy' => 'deploy-a',
            'server' => 'api-1',
            '_group' => 'mail-group-0000000000000000000000000001',
            'trace_id' => $trace,
            'execution_source' => 'request',
            'execution_id' => $executionId,
            'execution_preview' => 'GET /orders/1',
            'execution_stage' => 'action',
            'user' => 'user-1',
            'mailer' => 'smtp',
            'class' => 'App\\Mail\\OrderAlertMail',
            'subject' => 'Order alert',
            'to' => 1,
            'cc' => 0,
            'bcc' => 0,
            'attachments' => 0,
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
