<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_sequences — collision-safe generator for human-readable tracking IDs
 * (request_no, po_number, change_order_no).
 *
 * R-5: one counter row per (document_type, scope). The application increments
 * atomically:
 *
 *   UPDATE document_sequences
 *      SET last_value = last_value + 1
 *    WHERE document_type = ? AND scope_type = ? AND scope_id IS NOT DISTINCT FROM ?
 *   RETURNING last_value;
 *
 * That UPDATE takes a row-level lock, so two concurrent callers can never
 * mint the same number — which application-side MAX()+1 would do under load
 * and would intermittently violate the partial unique indexes on
 * request_no / po_number / change_order_no.
 *
 * Plain table — not append-only, no soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40); // material_request / purchase_order / change_order
            $table->string('scope_type', 40)->default('global'); // global | project
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('prefix', 20)->nullable();
            $table->bigInteger('last_value')->default(0);
            $table->timestampsTz();

            // Identifies the counter row uniquely. Nulls-not-distinct would be ideal
            // (Postgres 15+); for compatibility we use a normal index — the app must
            // always pass a non-null scope_id when scope_type='project', and use
            // scope_type='global' + a fixed scope_id (e.g. 0) for global counters.
            $table->unique(['document_type', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
