<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchCleanupService;
use Illuminate\Console\Command;

class NightwatchCleanupCommand extends Command
{
    protected $signature = 'nightwatch:cleanup';

    protected $description = 'Clean up expired Nightwatch facts, raw events, and rollups.';

    public function handle(NightwatchCleanupService $cleanup): int
    {
        $result = $cleanup->cleanup();

        foreach ($result as $label => $count) {
            $this->line(sprintf('%s: %d', $label, $count));
        }

        return self::SUCCESS;
    }
}
