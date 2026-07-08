<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activity_logs — central polymorphic audit spine. One row per significant
 * action; modeled on Spatie ActivityLog and extended with a nullable
 * project_id for scoped audit queries.
 *
 * Append-only in practice (A-9). deleted_at is present only for schema
 * uniformity; never set.
 *
 * Scale note (§5): this table grows fastest. Designed to be range-partitioned
 * by created_at when production volume warrants it — append-only + time-ordered
 * means partitioning can be introduced without a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 80)->nullable();
            $table->string('event', 60);
            $table->string('description', 255)->nullable();

            $table->foreignId('causer_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Polymorphic subject — no DB-level FK.
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->jsonb('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('causer_id');
            $table->index('project_id');
            $table->index('event');
        });

        DB::statement("ALTER TABLE activity_logs
                         ALTER COLUMN ip_address TYPE INET USING ip_address::inet");

        // GIN index on the jsonb diff for fast attribute-level audit queries.
        DB::statement("CREATE INDEX activity_logs_properties_gin
                         ON activity_logs
                         USING GIN (properties)");
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
