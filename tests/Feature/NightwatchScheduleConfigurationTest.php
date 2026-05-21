<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class NightwatchScheduleConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OVERWATCH_ROLLUP_SCHEDULE_EVERY_MINUTES');
        putenv('OVERWATCH_CLEANUP_SCHEDULE_DAILY_AT');
        putenv('OVERWATCH_ROLLUP_SCHEDULE_ENABLED');
        putenv('OVERWATCH_CLEANUP_SCHEDULE_ENABLED');

        parent::tearDown();
    }

    public function test_nightwatch_rollup_and_cleanup_are_scheduled_with_defaults(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $rollup = $events->first(fn ($event) => str_contains((string) $event->command, 'nightwatch:rollup'));
        $cleanup = $events->first(fn ($event) => str_contains((string) $event->command, 'nightwatch:cleanup'));

        $this->assertNotNull($rollup);
        $this->assertSame('* * * * *', $rollup->getExpression());

        $this->assertNotNull($cleanup);
        $this->assertSame('0 2 * * *', $cleanup->getExpression());
    }

    public function test_nightwatch_schedules_can_be_configured_from_environment(): void
    {
        putenv('OVERWATCH_ROLLUP_SCHEDULE_EVERY_MINUTES=5');
        putenv('OVERWATCH_CLEANUP_SCHEDULE_DAILY_AT=03:30');

        $this->refreshApplication();

        $events = collect($this->app->make(Schedule::class)->events());

        $rollup = $events->first(fn ($event) => str_contains((string) $event->command, 'nightwatch:rollup'));
        $cleanup = $events->first(fn ($event) => str_contains((string) $event->command, 'nightwatch:cleanup'));

        $this->assertNotNull($rollup);
        $this->assertSame('*/5 * * * *', $rollup->getExpression());

        $this->assertNotNull($cleanup);
        $this->assertSame('30 3 * * *', $cleanup->getExpression());
    }
}
