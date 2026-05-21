<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createRequestRouteRollup();
        $this->createExceptionGroupRollup();
        $this->createQueryGroupRollup();
        $this->createOutgoingHostRollup();
        $this->createJobQueueRollup();
        $this->createCommandRollup();
        $this->createScheduleRollup();
        $this->createLogLevelRollup();
    }

    public function down(): void
    {
        Schema::dropIfExists('nw_log_level_1m');
        Schema::dropIfExists('nw_schedule_1m');
        Schema::dropIfExists('nw_command_1m');
        Schema::dropIfExists('nw_job_queue_1m');
        Schema::dropIfExists('nw_outgoing_host_1m');
        Schema::dropIfExists('nw_query_group_1m');
        Schema::dropIfExists('nw_exception_group_1m');
        Schema::dropIfExists('nw_request_route_1m');
    }

    private function createRequestRouteRollup(): void
    {
        Schema::create('nw_request_route_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_request_route_1m');
            $table->string('method', 16)->nullable();
            $table->string('route_name')->nullable();
            $table->string('route_domain')->nullable();
            $table->string('route_path')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'method', 'route_name', 'route_domain', 'route_path'], 'nw_request_route_1m_unique');
        });
    }

    private function createExceptionGroupRollup(): void
    {
        Schema::create('nw_exception_group_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_exception_group_1m');
            $table->char('group_hash', 32)->nullable();
            $table->string('class');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'group_hash', 'class', 'file', 'line'], 'nw_exception_group_1m_unique');
        });
    }

    private function createQueryGroupRollup(): void
    {
        Schema::create('nw_query_group_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_query_group_1m');
            $table->char('group_hash', 32)->nullable();
            $table->string('connection')->nullable();
            $table->enum('connection_type', ['read', 'write', 'unknown'])->default('unknown');
            $table->string('file')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'group_hash', 'connection', 'connection_type', 'file'], 'nw_query_group_1m_unique');
        });
    }

    private function createOutgoingHostRollup(): void
    {
        Schema::create('nw_outgoing_host_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_outgoing_host_1m');
            $table->char('group_hash', 32)->nullable();
            $table->string('host')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'group_hash', 'host'], 'nw_outgoing_host_1m_unique');
        });
    }

    private function createJobQueueRollup(): void
    {
        Schema::create('nw_job_queue_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_job_queue_1m');
            $table->string('name');
            $table->string('connection');
            $table->string('queue');
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'name', 'connection', 'queue'], 'nw_job_queue_1m_unique');
        });
    }

    private function createCommandRollup(): void
    {
        Schema::create('nw_command_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_command_1m');
            $table->char('group_hash', 32)->nullable();
            $table->string('name');
            $table->string('class')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'group_hash', 'name', 'class'], 'nw_command_1m_unique');
        });
    }

    private function createScheduleRollup(): void
    {
        Schema::create('nw_schedule_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_schedule_1m');
            $table->char('group_hash', 32)->nullable();
            $table->text('name');
            $table->string('cron');
            $table->string('timezone')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'group_hash', 'cron', 'timezone'], 'nw_schedule_1m_unique');
        });
    }

    private function createLogLevelRollup(): void
    {
        Schema::create('nw_log_level_1m', function (Blueprint $table) {
            $this->addMetricColumns($table, 'nw_log_level_1m');
            $table->string('level', 32);
            $table->timestamps();

            $table->unique(['bucket_start', 'project_id', 'level'], 'nw_log_level_1m_unique');
        });
    }

    private function addMetricColumns(Blueprint $table, string $tableName): void
    {
        $table->id();
        $table->timestampTz('bucket_start', 6);
        $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
        $table->unsignedBigInteger('count')->default(0);
        $table->unsignedBigInteger('error_count')->default(0);
        $table->unsignedBigInteger('failure_count')->default(0);
        $table->unsignedBigInteger('sum_duration_us')->default(0);
        $table->unsignedBigInteger('p50_us')->default(0);
        $table->unsignedBigInteger('p95_us')->default(0);
        $table->unsignedBigInteger('p99_us')->default(0);
        $table->unsignedBigInteger('sum_request_bytes')->default(0);
        $table->unsignedBigInteger('sum_response_bytes')->default(0);

        $table->index(['project_id', 'bucket_start'], "{$tableName}_project_bucket_idx");
    }
};
