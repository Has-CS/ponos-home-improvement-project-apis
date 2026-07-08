<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * material_request_items — line items on a material request.
 *
 * R-3: cost_code_id is mandatory at the line so a single request can mix codes.
 *
 * A-6: lines may be free-text (catalog_item_id NULL + description set), since
 * field crews may need items not yet catalogued. CHECK enforces that at least
 * one identifies the item.
 *
 * PM/Admin add/remove uses soft delete + activity_logs audit, preserving edit history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')
                ->constrained('material_requests')
                ->restrictOnDelete();

            $table->foreignId('cost_code_id')->constrained('cost_codes')->restrictOnDelete();

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
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('material_request_id');
            $table->index('catalog_item_id');
            $table->index('cost_code_id');
        });

        // A-6: must identify the item somehow.
        DB::statement("ALTER TABLE material_request_items
                         ADD CONSTRAINT material_request_items_item_or_desc_check
                         CHECK (catalog_item_id IS NOT NULL OR description IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_items');
    }
};
