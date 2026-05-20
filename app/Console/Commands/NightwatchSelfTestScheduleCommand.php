<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class NightwatchSelfTestScheduleCommand extends Command
{
    protected $signature = 'nightwatch:self-test:schedule
        {scenario : processed or failed}
        {--run=}';

    protected $description = 'Execute a Nightwatch self-test scheduled task scenario.';

    public function handle(): int
    {
        return match ((string) $this->argument('scenario')) {
            'processed' => self::SUCCESS,
            'failed' => self::FAILURE,
            default => self::INVALID,
        };
    }
}
