<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup table: rfq_statuses.
 *
 * Minimal lifecycle by design: draft -> sent. A Request for Quotation is
 * authored and edited by a single actor class (Admin/PM, no approval chain),
 * so there is nothing to route between reviewers — see RfqService. An
 * unwanted draft is deleted rather than cancelled.
 *
 * Same shape as material_request_statuses: soft-deletable, UNIQUE(code) as a
 * partial index so a soft-deleted row never blocks re-use of its code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("CREATE UNIQUE INDEX rfq_statuses_code_unique
                         ON rfq_statuses (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_statuses');
    }
};
