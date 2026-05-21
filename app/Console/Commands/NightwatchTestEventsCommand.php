<?php

namespace App\Console\Commands;

use App\Nightwatch\SelfTest\NightwatchSelfTestHarness;
use Illuminate\Console\Command;
use Laravel\Nightwatch\Facades\Nightwatch;

class NightwatchTestEventsCommand extends Command
{
    protected $signature = 'nightwatch:test-events
        {--run= : Reuse a specific run identifier}
        {--listener-port= : Fixed listener TCP port}
        {--web-port= : Fixed primary web port}
        {--secondary-web-port= : Fixed secondary web port}
        {--timeout= : Startup / verification timeout in seconds}';

    protected $description = 'Run the local Nightwatch TCP ingest self-test harness end-to-end.';

    public function handle(NightwatchSelfTestHarness $harness): int
    {
        Nightwatch::pause();
        Nightwatch::dontSample();

        $result = $harness->run(
            runId: $this->option('run') ? (string) $this->option('run') : null,
            listenerPort: $this->option('listener-port') ? (int) $this->option('listener-port') : null,
            webPort: $this->option('web-port') ? (int) $this->option('web-port') : null,
            secondaryWebPort: $this->option('secondary-web-port') ? (int) $this->option('secondary-web-port') : null,
            timeout: $this->option('timeout') ? (int) $this->option('timeout') : null,
        );

        $this->info("Nightwatch self-test completed for run [{$result['run_id']}] on project [{$result['project_id']}].");
        $this->table(
            ['Event Type', 'Count'],
            collect($result['summary'])
                ->sortKeys()
                ->map(fn (int $count, string $eventType) => [$eventType, $count])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
