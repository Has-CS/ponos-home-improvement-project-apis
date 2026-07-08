<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_order_items — line items on a PO. Snapshots unit_price/line_total
 * (vendor rates change after a PO is cut; the PO must preserve what was
 * quoted) and references the exact vendor_rate_id used.
 *
 * cost_code_id is a snapshot from the originating material_request_item —
 * lets one PO carry lines on different cost codes (R-3 carry-through).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            $table->foreignId('material_request_item_id')
                ->nullable()
                ->constrained('material_request_items')
                ->restrictOnDelete();

            $table->foreignId('cost_code_id')
                ->nullable()
                ->constrained('cost_codes')
                ->restrictOnDelete();

            $table->foreignId('catalog_item_id')
                ->nullable()
                ->constrained('catalog_items')
                ->restrictOnDelete();

            $table->foreignId('vendor_rate_id')
                ->nullable()
                ->constrained('vendor_rates')
                ->restrictOnDelete();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->string('description', 255)->nullable();
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('unit_price', 14, 2);  // snapshot
            $table->decimal('line_total', 16, 2);  // snapshot

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('purchase_order_id');
            $table->index('material_request_item_id');
            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
