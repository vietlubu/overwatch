<?php

namespace App\Nightwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use function array_key_exists;
use function preg_match;
use function sprintf;

final class NightwatchPartitionManager
{
    /**
     * @var array<string, true>
     */
    private array $knownPartitions = [];

    public function ensureRawEventPartition(CarbonInterface $occurredAt): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        $baseMonth = CarbonImmutable::instance($occurredAt)->startOfMonth();
        $monthsAhead = (int) Config::get('overwatch.storage.partition_precreate_months', 2);

        for ($offset = 0; $offset < $monthsAhead; $offset++) {
            $this->createRawPartition($baseMonth->addMonths($offset));
        }
    }

    public function dropExpiredRawEventPartitions(CarbonInterface $cutoff): int
    {
        if ($this->driver() !== 'pgsql') {
            return 0;
        }

        $cutoffMonth = CarbonImmutable::instance($cutoff)->startOfMonth();
        $tables = DB::select("
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = current_schema()
              AND tablename LIKE 'nw_raw_events_%'
        ");

        $dropped = 0;

        foreach ($tables as $table) {
            if (! preg_match('/^nw_raw_events_(\d{6})$/', $table->tablename, $matches)) {
                continue;
            }

            $partitionMonth = CarbonImmutable::createFromFormat('Ym', $matches[1])->startOfMonth();

            if ($partitionMonth->lt($cutoffMonth)) {
                DB::statement(sprintf('DROP TABLE IF EXISTS %s', $table->tablename));
                unset($this->knownPartitions[$table->tablename]);
                $dropped++;
            }
        }

        return $dropped;
    }

    private function createRawPartition(CarbonImmutable $month): void
    {
        $name = sprintf('nw_raw_events_%s', $month->format('Ym'));

        if (array_key_exists($name, $this->knownPartitions)) {
            return;
        }

        $start = $month->format('Y-m-01 00:00:00');
        $end = $month->addMonth()->format('Y-m-01 00:00:00');

        DB::statement(sprintf(
            "CREATE TABLE IF NOT EXISTS %s PARTITION OF nw_raw_events FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $start,
            $end,
        ));

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_project_occurred_idx ON %1$s (project_id, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_event_type_occurred_idx ON %1$s (event_type, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS %1$s_trace_idx ON %1$s (trace_id)', $name));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS %1$s_execution_idx ON %1$s (execution_id)', $name));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_group_hash_idx ON %1$s (group_hash, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_payload_gin_idx ON %1$s USING GIN (payload)',
            $name,
        ));

        $this->knownPartitions[$name] = true;
    }

    private function driver(): string
    {
        return DB::getDriverName();
    }
}
