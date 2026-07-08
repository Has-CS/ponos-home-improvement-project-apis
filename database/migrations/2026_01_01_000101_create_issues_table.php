<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * issues — field issues, optionally raised from a daily log. project_id is
 * mandatory (cross-module entity must carry its tenancy key).
 *
 * severity is varchar + CHECK rather than a lookup table — stable internal
 * discriminator (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            $table->foreignId('daily_log_id')
                ->nullable()
                ->constrained('daily_logs')
                ->restrictOnDelete();

            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('issue_status_id')->constrained('issue_statuses')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('severity', 20)->nullable();

            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['project_id', 'issue_status_id']);
            $table->index('daily_log_id');
            $table->index('assigned_to');
        });

        DB::statement("ALTER TABLE issues
                         ADD CONSTRAINT issues_severity_check
                         CHECK (severity IS NULL OR severity IN ('low','medium','high','critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
