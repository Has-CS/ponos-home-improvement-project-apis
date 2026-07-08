<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup table: change_order_types
 * See §3 (Module 0 — Shared lookups) in the schema document.
 * Soft-deletable; the UNIQUE(code) is implemented as a partial index (R-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('label', 60);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // R-4: partial unique index — soft-deleted rows must not block re-use of a code.
        DB::statement("CREATE UNIQUE INDEX change_order_types_code_unique
                         ON change_order_types (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_types');
    }
};
