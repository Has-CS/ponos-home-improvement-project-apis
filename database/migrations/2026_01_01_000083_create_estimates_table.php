<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * estimates — Phase-2 scaffold. Estimate header; the active version is
 * denormalized as current_version_id for quick reads.
 *
 * NOTE: current_version_id is intentionally NOT in this migration. It points
 * to estimate_versions(id), which doesn't exist yet — and estimate_versions
 * points back here. We close the loop with an ALTER in
 * 2026_01_01_000086_add_current_version_fk_to_estimates.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name', 200);

            // current_version_id added later (see migration 000086).

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
