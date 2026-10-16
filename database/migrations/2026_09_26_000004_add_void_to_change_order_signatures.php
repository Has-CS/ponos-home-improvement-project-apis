<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voiding an on-site authorization, VISIBLY.
 *
 * When a PM finds that the GC's representative signed the wrong thing, nothing
 * can edit it — that immutability is the whole value of the record. The remedy is
 * to abandon the change order and re-capture on site under a new signature.
 *
 * The obvious implementation is to soft-delete the signature row, and it is
 * wrong. change_order_signatures already uses SoftDeletes, so a delete would put
 * the superseded authorization behind a global scope: invisible to the document,
 * to the API and to anyone asking what happened, which is the exact opposite of
 * what a disputed signature needs. A voided authorization is evidence — evidence
 * that someone signed, that it was found defective, and why.
 *
 * Hence two plain columns and no delete. ChangeOrder::currentSignature() is the
 * single place that decides which row authorises a change order, so filtering
 * voided rows out of that one method keeps every consumer honest while the row
 * itself stays readable.
 *
 * void_reason is mandatory at the API layer rather than NOT NULL here, because
 * the column must stay null for every signature that was never voided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_order_signatures', function (Blueprint $table) {
            $table->timestampTz('voided_at')->nullable()->after('device_info');
            $table->text('void_reason')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('change_order_signatures', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
