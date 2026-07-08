<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daily_logs — the Foreman's Daily Scope of Work. Photo evidence lives in
 * polymorphic `attachments` (attachable_type=DailyLog).
 *
 * has_issue is a denormalized flag that triggers the field-to-office
 * notification — set when the user simultaneously raises an `issues` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('logged_by')->constrained('users')->restrictOnDelete();

            $table->date('log_date');
            $table->text('work_description');
            $table->string('weather', 80)->nullable();
            $table->smallInteger('crew_count')->nullable();
            $table->boolean('has_issue')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['project_id', 'log_date']);
            $table->index('logged_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_logs');
    }
};
