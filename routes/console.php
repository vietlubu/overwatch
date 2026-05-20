<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Nightwatch\Console\Sample as ConsoleSample;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$selfTestRunId = config('overwatch.self_test.run_id');

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
