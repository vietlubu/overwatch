<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Nightwatch\Facades\Nightwatch;

class NightwatchSelfTestEmitCommand extends Command
{
    protected $signature = 'nightwatch:self-test:command
        {scenario : success or failure}
        {--run=}';

    protected $description = 'Emit a dedicated Nightwatch self-test command event.';

    public function handle(): int
    {
        Nightwatch::sample(1);

        return match ((string) $this->argument('scenario')) {
            'success' => self::SUCCESS,
            'failure' => self::FAILURE,
            default => self::INVALID,
        };
    }
}
