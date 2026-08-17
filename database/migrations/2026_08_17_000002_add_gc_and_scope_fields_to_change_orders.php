<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The General Contractor a change order is addressed to, plus the inclusions and
 * exclusions that define what the change actually covers.
 *
 * ---- GC: FK **and** snapshot ----
 *
 * The same shape purchase_orders uses for ship-to, and for the identical reason
 * argued there: "an address may be corrected or retired long after the order
 * went out, and an issued PO must keep printing what it printed on the day it
 * was issued." A change order is semi-contractual in exactly the same way.
 *
 *  - general_contractor_id  the reference. Which GC row was chosen — lets a UI
 *                           re-select it and links the CO back to the GC.
 *  - gc_*                   the snapshot. What actually prints. Written by
 *                           ChangeOrderService::prepareDocument(), the moment
 *                           the PDF is first rendered and filed.
 *
 * The freeze point differs from the PO's, which snapshots at create and is held
 * still by assertDraft(). A change order stays editable much further along (a PM
 * fixes the value at pending_document), so the snapshot is taken at the one
 * moment the document comes into existence. From pending_counter_sign onward
 * ChangeOrderService::assertEditable() blocks every edit, so nothing can drift
 * after it is taken.
 *
 * All nullable: a draft change order legitimately has no GC yet, and the live
 * relation is used as a fallback for pre-prepare draft renders.
 *
 * ---- Inclusions / exclusions ----
 *
 * Plain text, split on newlines into printable entries by the existing
 * PurchaseOrderTerm::splitClauses() — the house pattern for "a list rendered on
 * a document". Deliberately NOT a child table: a line would have to carry its
 * own price, cost code or quantity to justify one, and a change order's value is
 * a single decimal with no breakdown by design.
 *
 * No snapshot needed — these live on the change_orders row itself and are
 * already frozen by the same assertEditable() rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->foreignId('general_contractor_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_general_contractors')
                ->restrictOnDelete();

            $table->string('gc_name', 200)->nullable()->after('general_contractor_id');
            $table->string('gc_contact_name', 200)->nullable()->after('gc_name');
            $table->string('gc_street_1', 200)->nullable()->after('gc_contact_name');
            $table->string('gc_street_2', 200)->nullable()->after('gc_street_1');
            $table->string('gc_city', 120)->nullable()->after('gc_street_2');
            $table->string('gc_state', 120)->nullable()->after('gc_city');
            $table->string('gc_postal_code', 20)->nullable()->after('gc_state');
            $table->string('gc_country', 120)->nullable()->after('gc_postal_code');
            $table->string('gc_phone', 40)->nullable()->after('gc_country');
            $table->string('gc_email', 255)->nullable()->after('gc_phone');

            $table->text('inclusions')->nullable()->after('scope');
            $table->text('exclusions')->nullable()->after('inclusions');
        });
    }

    public function down(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->dropForeign(['general_contractor_id']);
            $table->dropColumn([
                'general_contractor_id',
                'gc_name',
                'gc_contact_name',
                'gc_street_1',
                'gc_street_2',
                'gc_city',
                'gc_state',
                'gc_postal_code',
                'gc_country',
                'gc_phone',
                'gc_email',
                'inclusions',
                'exclusions',
            ]);
        });
    }
};
