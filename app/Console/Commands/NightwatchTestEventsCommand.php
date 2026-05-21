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
        {--timeout= : Startup / verification timeout in seconds}
        {--days-back=0 : Replay the generated event set from N days ago through today}
        {--concurrent-min=1 : Minimum replay batches to generate per day}
        {--concurrent-max=1 : Maximum replay batches to generate per day}
        {--users=1 : Target distinct self-test users to reuse across replayed data}';

    protected $description = 'Run the local Nightwatch TCP ingest self-test harness end-to-end.';

    public function handle(NightwatchSelfTestHarness $harness): int
    {
        $daysBack = (int) $this->option('days-back');
        $concurrentMin = (int) $this->option('concurrent-min');
        $concurrentMax = (int) $this->option('concurrent-max');
        $users = (int) $this->option('users');

        if ($daysBack < 0) {
            $this->components->error('The --days-back option must be zero or greater.');

            return self::INVALID;
        }

        if ($concurrentMin < 1) {
            $this->components->error('The --concurrent-min option must be at least 1.');

            return self::INVALID;
        }

        if ($concurrentMax < $concurrentMin) {
            $this->components->error('The --concurrent-max option must be greater than or equal to --concurrent-min.');

            return self::INVALID;
        }

        if ($users < 1) {
            $this->components->error('The --users option must be at least 1.');

            return self::INVALID;
        }

        Nightwatch::pause();
        Nightwatch::dontSample();

        $result = $harness->run(
            runId: $this->option('run') ? (string) $this->option('run') : null,
            listenerPort: $this->option('listener-port') ? (int) $this->option('listener-port') : null,
            webPort: $this->option('web-port') ? (int) $this->option('web-port') : null,
            secondaryWebPort: $this->option('secondary-web-port') ? (int) $this->option('secondary-web-port') : null,
            timeout: $this->option('timeout') ? (int) $this->option('timeout') : null,
            daysBack: $daysBack,
            concurrentMin: $concurrentMin,
            concurrentMax: $concurrentMax,
            userCount: $users,
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

        if (($result['replayed_batches'] ?? 0) > 0) {
            $this->newLine();
            $this->table(
                ['Replay Setting', 'Value'],
                [
                    ['Days back', (string) ($result['days_back'] ?? $daysBack)],
                    ['Concurrent range', sprintf('%d-%d', $result['concurrent_min'] ?? $concurrentMin, $result['concurrent_max'] ?? $concurrentMax)],
                    ['Replay users', (string) ($result['user_count'] ?? $users)],
                    ['Replay batches', (string) $result['replayed_batches']],
                    ['Replay events', (string) ($result['replayed_events'] ?? 0)],
                    ['Total raw events', (string) ($result['total_raw_events'] ?? 0)],
                ],
            );
        }

        return self::SUCCESS;
    }
}
