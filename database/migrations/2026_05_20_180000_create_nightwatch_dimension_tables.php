<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nw_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->char('token_hash', 7)->unique();
            $table->char('secret_sha256', 64);
            $table->char('secret_fingerprint', 16);
            $table->char('secret_last_four', 4);
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('nw_ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('nw_projects')->nullOnDelete();
            $table->char('token_hash', 7);
            $table->string('protocol_version', 16);
            $table->string('transport', 16);
            $table->unsignedInteger('payload_bytes');
            $table->unsignedInteger('record_count')->default(0);
            $table->enum('ack_status', ['received', 'accepted', 'rejected'])->default('received');
            $table->text('parse_error')->nullable();
            $table->timestampTz('received_at', 6);
            $table->timestampTz('processed_at', 6)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'received_at'], 'nw_ingest_batches_project_received_idx');
            $table->index(['token_hash', 'received_at'], 'nw_ingest_batches_token_received_idx');
            $table->index(['ack_status', 'received_at'], 'nw_ingest_batches_ack_received_idx');
        });

        Schema::create('nw_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name'], 'nw_servers_project_name_unique');
        });

        Schema::create('nw_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('name', 255);
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name'], 'nw_deployments_project_name_unique');
        });

        Schema::create('nw_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->string('external_user_id', 255);
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->timestampTz('first_seen_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->timestamps();

            $table->unique(['project_id', 'external_user_id'], 'nw_users_project_external_unique');
            $table->index(['project_id', 'last_seen_at'], 'nw_users_project_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nw_users');
        Schema::dropIfExists('nw_deployments');
        Schema::dropIfExists('nw_servers');
        Schema::dropIfExists('nw_ingest_batches');
        Schema::dropIfExists('nw_projects');
    }
};
