<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Nightwatch\Console\Sample as ConsoleSample;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$rollupScheduleEnabled = (bool) config('overwatch.scheduling.rollup.enabled', true);
$rollupScheduleEveryMinutes = (int) config('overwatch.scheduling.rollup.every_minutes', 1);
$cleanupScheduleEnabled = (bool) config('overwatch.scheduling.cleanup.enabled', true);
$cleanupScheduleDailyAt = (string) config('overwatch.scheduling.cleanup.daily_at', '02:00');
$selfTestRunId = config('overwatch.self_test.run_id');

if ($rollupScheduleEnabled) {
    $rollupSchedule = Schedule::command('nightwatch:rollup')
        ->name('nightwatch-rollup')
        ->description('Refresh Nightwatch 1-minute rollups.')
        ->withoutOverlapping();

    if ($rollupScheduleEveryMinutes === 1) {
        $rollupSchedule->everyMinute();
    } else {
        $rollupSchedule->cron(sprintf('*/%d * * * *', $rollupScheduleEveryMinutes));
    }
}

if ($cleanupScheduleEnabled) {
    Schedule::command('nightwatch:cleanup')
        ->name('nightwatch-cleanup')
        ->description('Clean up expired Nightwatch facts, raw events, and rollups.')
        ->dailyAt($cleanupScheduleDailyAt)
        ->withoutOverlapping();
}

if (config('overwatch.self_test.enabled') && $selfTestRunId) {
    Schedule::command("nightwatch:self-test:schedule processed --run={$selfTestRunId}")
        ->name("nightwatch-self-test-processed-{$selfTestRunId}")
        ->description("Nightwatch self-test scheduled task processed [{$selfTestRunId}]")
        ->everyMinute()
        ->tap(ConsoleSample::always());

    Schedule::command("nightwatch:self-test:schedule skipped --run={$selfTestRunId}")
        ->name("nightwatch-self-test-skipped-{$selfTestRunId}")
        ->description("Nightwatch self-test scheduled task skipped [{$selfTestRunId}]")
        ->everyMinute()
        ->tap(ConsoleSample::always())
        ->skip(fn () => true);

    Schedule::command("nightwatch:self-test:schedule failed --run={$selfTestRunId}")
        ->name("nightwatch-self-test-failed-{$selfTestRunId}")
        ->description("Nightwatch self-test scheduled task failed [{$selfTestRunId}]")
        ->everyMinute()
        ->tap(ConsoleSample::always());
}
