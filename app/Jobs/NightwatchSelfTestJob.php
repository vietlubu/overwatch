<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class NightwatchSelfTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
        public string $scenario,
    ) {}

    public function handle(): void
    {
        match ($this->scenario) {
            'processed' => null,
            'released' => $this->release(30),
            'failed' => $this->fail(new RuntimeException("Nightwatch self-test job failed [{$this->runId}]")),
            default => throw new RuntimeException("Unknown self-test job scenario [{$this->scenario}]."),
        };
    }

    public function tries(): int
    {
        return match ($this->scenario) {
            'released' => 2,
            default => 1,
        };
    }
}
