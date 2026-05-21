<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\UsesFileSqliteDatabase;
use Tests\TestCase;

class NightwatchSelfTestHarnessTest extends TestCase
{
    use UsesFileSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFileSqliteDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanupFileSqliteDatabase();

        parent::tearDown();
    }

    public function test_nightwatch_test_events_command_runs_end_to_end(): void
    {
        $this->artisan('nightwatch:test-events', [
            '--timeout' => 25,
        ])->assertSuccessful();

        $summary = DB::table('nw_raw_events')
            ->select('event_type', DB::raw('count(*) as aggregate'))
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        $this->assertSame([
            'cache-event' => 6,
            'command' => 4,
            'exception' => 3,
            'job-attempt' => 4,
            'log' => 1,
            'mail' => 1,
            'notification' => 1,
            'outgoing-request' => 1,
            'query' => 1,
            'queued-job' => 3,
            'request' => 6,
            'scheduled-task' => 3,
            'user' => 1,
        ], $summary);
    }

    public function test_self_test_routes_return_404_when_guard_is_disabled(): void
    {
        config(['overwatch.self_test.enabled' => false]);

        $this->get('/'.config('overwatch.self_test.route_prefix').'/exercise')
            ->assertNotFound();
    }
}
