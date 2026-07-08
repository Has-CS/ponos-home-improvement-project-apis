<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * delivery_items — quantity confirmation on receipt; one row per PO line
 * acknowledged in this delivery. Accepted vs received differ when a portion
 * is rejected at the gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')
                ->constrained('purchase_order_items')
                ->restrictOnDelete();

            $table->decimal('quantity_received', 14, 3);
            $table->decimal('quantity_accepted', 14, 3)->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('delivery_id');
            $table->index('purchase_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};
