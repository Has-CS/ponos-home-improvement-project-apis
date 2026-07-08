<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup table: discrepancy_types
 * See §3 (Module 0 — Shared lookups) in the schema document.
 * Soft-deletable; the UNIQUE(code) is implemented as a partial index (R-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancy_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // R-4: partial unique index — soft-deleted rows must not block re-use of a code.
        DB::statement("CREATE UNIQUE INDEX discrepancy_types_code_unique
                         ON discrepancy_types (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancy_types');
    }
};
