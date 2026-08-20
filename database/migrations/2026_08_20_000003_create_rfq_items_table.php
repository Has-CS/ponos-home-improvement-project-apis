<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rfq_items — line items on a Request for Quotation.
 *
 * Mirrors material_request_items' free-text escape hatch: catalog_item_id is
 * nullable, description is required when it's absent, and a CHECK enforces
 * that at least one identifies the item. This is deliberate, not a gap — a PM
 * fast-listing items on a call or a site walk should never be forced through
 * Catalog Item creation (trade_category_id/catalog_item_type_id/default_unit_id
 * are all required there) mid-list. The catalog only needs to catch up later,
 * and only for items worth keeping: VendorRate.catalog_item_id is NOT NULL, so
 * that is what eventually forces the item to be catalogued, not RFQ authoring.
 *
 * No cost_code_id — cost coding doesn't exist pre-project (see rfqs migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();

            $table->foreignId('catalog_item_id')
                ->nullable()
                ->constrained('catalog_items')
                ->restrictOnDelete();

            $table->foreignId('trade_category_id')
                ->nullable()
                ->constrained('trade_categories')
                ->restrictOnDelete();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 14, 3);

            // Specs/notes for the vendor — printed on the document.
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['rfq_id', 'sort_order']);
            $table->index('catalog_item_id');
        });

        DB::statement("ALTER TABLE rfq_items
                         ADD CONSTRAINT rfq_items_item_or_desc_check
                         CHECK (catalog_item_id IS NOT NULL OR description IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_items');
    }
};
