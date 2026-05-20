<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class NightwatchRollupCommand extends Command
{
    protected $signature = 'nightwatch:rollup {--from=} {--to=}';

    protected $description = 'Refresh 1-minute Nightwatch rollups for a time window.';

    public function handle(NightwatchRollupService $rollups): int
    {
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : null;
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : null;

        $rollups->refresh($from, $to);

        $this->info('Nightwatch rollups refreshed.');

        return self::SUCCESS;
    }
}
