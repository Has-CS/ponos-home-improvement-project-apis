<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * estimate_versions — each change creates a new immutable version. Never
 * overwrite. status uses varchar + CHECK because it's a stable internal
 * discriminator (draft/finalized/superseded), not a user-managed workflow
 * vocabulary (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->restrictOnDelete();
            $table->integer('version_no');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('estimate_id');
        });

        DB::statement("ALTER TABLE estimate_versions
                         ADD CONSTRAINT estimate_versions_status_check
                         CHECK (status IN ('draft','finalized','superseded'))");

        DB::statement("CREATE UNIQUE INDEX estimate_versions_estimate_version_unique
                         ON estimate_versions (estimate_id, version_no)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_versions');
    }
};
