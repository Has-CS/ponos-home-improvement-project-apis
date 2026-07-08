<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup table: units
 * See §3 (Module 0 — Shared lookups) in the schema document.
 * Soft-deletable; the UNIQUE(code) is implemented as a partial index (R-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('label', 60);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // R-4: partial unique index — soft-deleted rows must not block re-use of a code.
        DB::statement("CREATE UNIQUE INDEX units_code_unique
                         ON units (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
