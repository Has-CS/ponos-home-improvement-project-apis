<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terms & Conditions on a purchase order.
 *
 * FK **and** snapshot — the third use of the idiom in this table, after
 * purchase_order_items (vendor_rate_id beside a frozen unit_price) and the
 * ship_to_* block. A PO is a semi-contractual document: the terms it was issued
 * under must stay exactly as printed, even after the company revises its
 * standard terms.
 *
 *  - terms_id    the reference. Which terms row was applied, for traceability.
 *  - terms_*     the snapshot. What actually prints.
 *
 * Resolved at create so a draft shows what it would carry, then RE-RESOLVED at
 * issue so the frozen copy is the terms in force at the moment the order was
 * placed (an admin may publish new terms while a PO sits in draft). After that
 * PurchaseOrderService::assertDraft() blocks every further edit, so no separate
 * freeze step is needed.
 *
 * All nullable, and deliberately NOT required at issue — unlike the ship-to
 * address. Terms are standing company content: if none is configured the block
 * simply doesn't print, rather than halting every purchase order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('terms_id')
                ->nullable()
                ->after('ship_to_project_code')
                ->constrained('purchase_order_terms')
                ->restrictOnDelete();

            $table->string('terms_title', 200)->nullable()->after('terms_id');
            $table->text('terms_body')->nullable()->after('terms_title');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['terms_id']);
            $table->dropColumn(['terms_id', 'terms_title', 'terms_body']);
        });
    }
};
