<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nw_issue_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('nw_projects')->cascadeOnDelete();
            $table->enum('source_type', ['exception', 'log']);
            $table->string('source_key');
            $table->timestampTz('resolved_at', 6);
            $table->timestampTz('resolved_through_occurred_at', 6);
            $table->timestamps();

            $table->unique(['project_id', 'source_type', 'source_key'], 'nw_issue_states_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nw_issue_states');
    }
};
