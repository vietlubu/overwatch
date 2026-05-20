<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createRawEventsTable();
        $this->createExecutionsTable();
        $this->createExecutionDetailTables();
        $this->createChildEventTables();
        $this->createJobsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('nw_jobs');
        Schema::dropIfExists('nw_cache_events');
        Schema::dropIfExists('nw_notification_events');
        Schema::dropIfExists('nw_mail_events');
        Schema::dropIfExists('nw_logs');
        Schema::dropIfExists('nw_queued_jobs');
        Schema::dropIfExists('nw_outgoing_requests');
        Schema::dropIfExists('nw_queries');
        Schema::dropIfExists('nw_exceptions');
        Schema::dropIfExists('nw_scheduled_task_details');
        Schema::dropIfExists('nw_job_attempt_details');
        Schema::dropIfExists('nw_command_details');
        Schema::dropIfExists('nw_request_details');
        Schema::dropIfExists('nw_executions');
        Schema::dropIfExists('nw_raw_events');
    }

    private function createRawEventsTable(): void
    {
        if ($this->driver() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TABLE nw_raw_events (
                    batch_id BIGINT NOT NULL REFERENCES nw_ingest_batches(id) ON DELETE CASCADE,
                    batch_record_index INTEGER NOT NULL,
                    project_id BIGINT NOT NULL REFERENCES nw_projects(id) ON DELETE CASCADE,
                    environment VARCHAR(64) NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    schema_version SMALLINT NOT NULL DEFAULT 1,
                    occurred_at TIMESTAMPTZ(6) NOT NULL,
                    group_hash CHAR(32) NULL,
                    trace_id VARCHAR(64) NULL,
                    execution_id VARCHAR(64) NULL,
                    execution_source VARCHAR(32) NULL,
                    execution_stage VARCHAR(32) NULL,
                    deployment_id BIGINT NULL REFERENCES nw_deployments(id) ON DELETE SET NULL,
                    server_id BIGINT NULL REFERENCES nw_servers(id) ON DELETE SET NULL,
                    external_user_id VARCHAR(255) NULL,
                    payload JSONB NOT NULL,
                    created_at TIMESTAMPTZ(6) NULL,
                    updated_at TIMESTAMPTZ(6) NULL
                ) PARTITION BY RANGE (occurred_at)
            SQL);

            DB::statement('CREATE INDEX nw_raw_events_project_env_occurred_idx ON nw_raw_events (project_id, environment, occurred_at DESC)');
            DB::statement('CREATE INDEX nw_raw_events_event_type_occurred_idx ON nw_raw_events (event_type, occurred_at DESC)');
            DB::statement('CREATE INDEX nw_raw_events_trace_idx ON nw_raw_events (trace_id)');
            DB::statement('CREATE INDEX nw_raw_events_execution_idx ON nw_raw_events (execution_id)');
            DB::statement('CREATE INDEX nw_raw_events_group_hash_idx ON nw_raw_events (group_hash, occurred_at DESC)');

            $this->createRawPartition(CarbonImmutable::now()->startOfMonth());
            $this->createRawPartition(CarbonImmutable::now()->addMonth()->startOfMonth());

            return;
        }

        Schema::create('nw_raw_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('nw_ingest_batches')->cascadeOnDelete();
            $table->unsignedInteger('batch_record_index');
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('environment', 64);
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestampTz('occurred_at', 6);
            $table->char('group_hash', 32)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('execution_id', 64)->nullable();
            $table->string('execution_source', 32)->nullable();
            $table->string('execution_stage', 32)->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained('nw_deployments')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('nw_servers')->nullOnDelete();
            $table->string('external_user_id')->nullable();
            $table->jsonb('payload');
            $table->timestamps();

            $table->unique(['batch_id', 'batch_record_index'], 'nw_raw_events_batch_row_unique');
            $table->index(['project_id', 'environment', 'occurred_at'], 'nw_raw_events_project_env_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'nw_raw_events_event_type_occurred_idx');
            $table->index('trace_id', 'nw_raw_events_trace_idx');
            $table->index('execution_id', 'nw_raw_events_execution_idx');
            $table->index(['group_hash', 'occurred_at'], 'nw_raw_events_group_hash_idx');
        });
    }

    private function createExecutionsTable(): void
    {
        Schema::create('nw_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('environment', 64);
            $table->foreignId('batch_id')->constrained('nw_ingest_batches')->cascadeOnDelete();
            $table->unsignedInteger('batch_record_index');
            $table->string('execution_id', 64);
            $table->enum('source', ['request', 'command', 'job', 'schedule']);
            $table->string('trace_id', 64);
            $table->char('group_hash', 32)->nullable();
            $table->string('preview')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->foreignId('deployment_id')->nullable()->constrained('nw_deployments')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('nw_servers')->nullOnDelete();
            $table->string('external_user_id')->nullable();
            $table->string('status', 32)->nullable();
            $table->unsignedInteger('exceptions')->default(0);
            $table->unsignedInteger('logs')->default(0);
            $table->unsignedInteger('queries')->default(0);
            $table->unsignedInteger('lazy_loads')->default(0);
            $table->unsignedInteger('jobs_queued')->default(0);
            $table->unsignedInteger('mail')->default(0);
            $table->unsignedInteger('notifications')->default(0);
            $table->unsignedInteger('outgoing_requests')->default(0);
            $table->unsignedInteger('files_read')->default(0);
            $table->unsignedInteger('files_written')->default(0);
            $table->unsignedInteger('cache_events')->default(0);
            $table->unsignedInteger('hydrated_models')->default(0);
            $table->unsignedBigInteger('peak_memory_bytes')->default(0);
            $table->text('exception_preview')->nullable();
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'batch_record_index'], 'nw_executions_batch_row_unique');
            $table->unique(['project_id', 'environment', 'execution_id'], 'nw_executions_project_env_execution_unique');
            $table->index(['project_id', 'environment', 'occurred_at'], 'nw_executions_project_env_occurred_idx');
            $table->index(['project_id', 'trace_id'], 'nw_executions_project_trace_idx');
            $table->index(['project_id', 'execution_id'], 'nw_executions_project_execution_idx');
            $table->index(['project_id', 'source', 'occurred_at'], 'nw_executions_project_source_occurred_idx');
            $table->index(['group_hash', 'occurred_at'], 'nw_executions_group_hash_occurred_idx');
        });
    }

    private function createExecutionDetailTables(): void
    {
        Schema::create('nw_request_details', function (Blueprint $table) {
            $table->foreignId('execution_row_id')->primary();
            $table->foreign('execution_row_id')->references('id')->on('nw_executions')->cascadeOnDelete();
            $table->string('execution_id', 64);
            $table->string('method', 16);
            $table->text('url');
            $table->string('route_name')->nullable();
            $table->jsonb('route_methods')->nullable();
            $table->string('route_domain')->nullable();
            $table->string('route_path')->nullable();
            $table->text('route_action')->nullable();
            $table->string('ip_text')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedBigInteger('request_bytes')->default(0);
            $table->unsignedBigInteger('response_bytes')->default(0);
            $table->unsignedBigInteger('bootstrap_us')->default(0);
            $table->unsignedBigInteger('before_middleware_us')->default(0);
            $table->unsignedBigInteger('action_us')->default(0);
            $table->unsignedBigInteger('render_us')->default(0);
            $table->unsignedBigInteger('after_middleware_us')->default(0);
            $table->unsignedBigInteger('sending_us')->default(0);
            $table->unsignedBigInteger('terminating_us')->default(0);
            $table->jsonb('headers_json')->nullable();
            $table->jsonb('request_payload_json')->nullable();
            $table->enum('request_payload_state', ['absent', 'present', 'not_enabled', 'unsupported_content_type', 'serialization_failed'])->default('absent');
            $table->timestamps();

            $table->index('execution_id', 'nw_request_details_execution_id_idx');
        });

        Schema::create('nw_command_details', function (Blueprint $table) {
            $table->foreignId('execution_row_id')->primary();
            $table->foreign('execution_row_id')->references('id')->on('nw_executions')->cascadeOnDelete();
            $table->string('execution_id', 64);
            $table->string('class');
            $table->string('name');
            $table->text('command');
            $table->unsignedSmallInteger('exit_code');
            $table->unsignedBigInteger('bootstrap_us')->default(0);
            $table->unsignedBigInteger('action_us')->default(0);
            $table->unsignedBigInteger('terminating_us')->default(0);
            $table->timestamps();

            $table->index('execution_id', 'nw_command_details_execution_id_idx');
        });

        Schema::create('nw_job_attempt_details', function (Blueprint $table) {
            $table->foreignId('execution_row_id')->primary();
            $table->foreign('execution_row_id')->references('id')->on('nw_executions')->cascadeOnDelete();
            $table->string('execution_id', 64);
            $table->string('job_id');
            $table->unsignedInteger('attempt')->default(1);
            $table->string('name');
            $table->string('connection');
            $table->string('queue');
            $table->enum('status', ['processed', 'released', 'failed']);
            $table->timestamps();

            $table->index('execution_id', 'nw_job_attempt_details_execution_id_idx');
            $table->index(['job_id', 'status'], 'nw_job_attempt_details_job_status_idx');
        });

        Schema::create('nw_scheduled_task_details', function (Blueprint $table) {
            $table->foreignId('execution_row_id')->primary();
            $table->foreign('execution_row_id')->references('id')->on('nw_executions')->cascadeOnDelete();
            $table->string('execution_id', 64);
            $table->text('name');
            $table->string('cron');
            $table->string('timezone')->nullable();
            $table->unsignedInteger('repeat_seconds')->default(0);
            $table->boolean('without_overlapping')->default(false);
            $table->boolean('on_one_server')->default(false);
            $table->boolean('run_in_background')->default(false);
            $table->boolean('even_in_maintenance_mode')->default(false);
            $table->enum('status', ['processed', 'skipped', 'failed']);
            $table->timestamps();

            $table->index('execution_id', 'nw_scheduled_task_details_execution_id_idx');
        });
    }

    private function createChildEventTables(): void
    {
        Schema::create('nw_exceptions', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('class');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('message');
            $table->string('code')->nullable();
            $table->jsonb('trace_frames_json')->nullable();
            $table->boolean('handled')->default(false);
            $table->string('php_version', 32)->nullable();
            $table->string('laravel_version', 32)->nullable();
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_exceptions');
        });

        Schema::create('nw_queries', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->text('sql');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->default(0);
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->string('connection')->nullable();
            $table->enum('connection_type', ['read', 'write', 'unknown'])->default('unknown');
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_queries');
        });

        Schema::create('nw_outgoing_requests', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('host')->nullable();
            $table->string('method', 16);
            $table->text('url');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->unsignedBigInteger('request_bytes')->default(0);
            $table->unsignedBigInteger('response_bytes')->default(0);
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_outgoing_requests');
        });

        Schema::create('nw_queued_jobs', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('job_id');
            $table->string('name');
            $table->string('connection');
            $table->string('queue');
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_queued_jobs');
            $table->index(['project_id', 'job_id'], 'nw_queued_jobs_project_job_idx');
        });

        Schema::create('nw_logs', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table, allowNullGroupHash: true);
            $table->string('level', 32);
            $table->text('message');
            $table->jsonb('context_json')->nullable();
            $table->jsonb('extra_json')->nullable();
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_logs', groupHashNullable: true);
        });

        Schema::create('nw_mail_events', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('mailer')->nullable();
            $table->string('class')->nullable();
            $table->string('subject')->nullable();
            $table->unsignedInteger('to_count')->default(0);
            $table->unsignedInteger('cc_count')->default(0);
            $table->unsignedInteger('bcc_count')->default(0);
            $table->unsignedInteger('attachments')->default(0);
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->boolean('failed')->default(false);
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_mail_events');
        });

        Schema::create('nw_notification_events', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('channel');
            $table->string('class');
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->boolean('failed')->default(false);
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_notification_events');
        });

        Schema::create('nw_cache_events', function (Blueprint $table) {
            $table->id();
            $this->addEventContextColumns($table);
            $table->string('store')->nullable();
            $table->string('cache_key');
            $table->enum('cache_event_type', ['hit', 'miss', 'write', 'write-failure', 'delete', 'delete-failure']);
            $table->unsignedBigInteger('duration_us')->default(0);
            $table->unsignedInteger('ttl_seconds')->default(0);
            $table->timestamps();

            $this->addEventIndexes($table, 'nw_cache_events');
        });
    }

    private function createJobsTable(): void
    {
        Schema::create('nw_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('environment', 64);
            $table->string('job_id');
            $table->string('first_trace_id', 64)->nullable();
            $table->string('enqueued_by_execution_id', 64)->nullable();
            $table->timestampTz('first_queued_at', 6)->nullable();
            $table->string('last_attempt_id', 64)->nullable();
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_status', 32)->nullable();
            $table->unsignedBigInteger('total_runtime_us')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'environment', 'job_id'], 'nw_jobs_project_env_job_unique');
            $table->index(['project_id', 'environment', 'last_attempt_at'], 'nw_jobs_project_env_last_attempt_idx');
        });
    }

    private function addEventContextColumns(Blueprint $table, bool $allowNullGroupHash = false): void
    {
        $table->foreignId('batch_id')->constrained('nw_ingest_batches')->cascadeOnDelete();
        $table->unsignedInteger('batch_record_index');
        $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
        $table->string('environment', 64);
        $table->timestampTz('occurred_at', 6);
        $groupHash = $table->char('group_hash', 32);

        if ($allowNullGroupHash) {
            $groupHash->nullable();
        }
        $table->string('trace_id', 64)->nullable();
        $table->string('execution_id', 64)->nullable();
        $table->string('execution_source', 32)->nullable();
        $table->string('execution_stage', 32)->nullable();
        $table->foreignId('deployment_id')->nullable()->constrained('nw_deployments')->nullOnDelete();
        $table->foreignId('server_id')->nullable()->constrained('nw_servers')->nullOnDelete();
        $table->string('external_user_id')->nullable();
    }

    private function addEventIndexes(Blueprint $table, string $tableName, bool $groupHashNullable = false): void
    {
        $table->unique(['batch_id', 'batch_record_index'], "{$tableName}_batch_row_unique");
        $table->index(['project_id', 'environment', 'occurred_at'], "{$tableName}_project_env_occurred_idx");
        $table->index(['project_id', 'trace_id'], "{$tableName}_project_trace_idx");
        $table->index(['project_id', 'execution_id'], "{$tableName}_project_execution_idx");

        if (! $groupHashNullable) {
            $table->index(['group_hash', 'occurred_at'], "{$tableName}_group_hash_occurred_idx");
        }
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    private function createRawPartition(CarbonImmutable $month): void
    {
        $start = $month->format('Y-m-01 00:00:00');
        $end = $month->addMonth()->format('Y-m-01 00:00:00');
        $name = sprintf('nw_raw_events_%s', $month->format('Ym'));

        DB::statement(sprintf(
            "CREATE TABLE IF NOT EXISTS %s PARTITION OF nw_raw_events FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $start,
            $end,
        ));

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_project_env_occurred_idx ON %1$s (project_id, environment, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_event_type_occurred_idx ON %1$s (event_type, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_trace_idx ON %1$s (trace_id)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_execution_idx ON %1$s (execution_id)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_group_hash_idx ON %1$s (group_hash, occurred_at DESC)',
            $name,
        ));
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %1$s_payload_gin_idx ON %1$s USING GIN (payload)',
            $name,
        ));
    }
};
