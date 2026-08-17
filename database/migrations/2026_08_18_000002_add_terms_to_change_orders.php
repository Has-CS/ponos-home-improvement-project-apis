<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Payment Terms / Changes / Acceptance text a change order is issued under.
 *
 * FK **and** snapshot, the third block on this document to take that shape after
 * the GC (gc_*) and the purchase order's ship-to and terms before it. Same
 * argument every time: revising the company's standard text must never rewrite a
 * document already in a General Contractor's hands.
 *
 *  - terms_id             the reference. Which set was resolved — traceability
 *                         only; nothing reads through it to print.
 *  - payment_terms_body   the snapshot. What actually prints.
 *  - changes_body
 *  - acceptance_body
 *
 * Written by ChangeOrderService::prepareDocument(), alongside the GC snapshot
 * already taken there. From pending_counter_sign onward assertEditable() blocks
 * every edit, so nothing drifts after the freeze.
 *
 * All nullable, and all-nulls is a legitimate outcome: unlike the GC — which
 * prepareDocument() refuses to proceed without, because the sheet is addressed to
 * them — missing terms never block preparation. The sections simply do not print.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->foreignId('terms_id')
                ->nullable()
                ->after('exclusions')
                ->constrained('change_order_terms')
                ->restrictOnDelete();

            $table->text('payment_terms_body')->nullable()->after('terms_id');
            $table->text('changes_body')->nullable()->after('payment_terms_body');
            $table->text('acceptance_body')->nullable()->after('changes_body');
        });
    }

    public function down(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->dropForeign(['terms_id']);
            $table->dropColumn(['terms_id', 'payment_terms_body', 'changes_body', 'acceptance_body']);
        });
    }
};
