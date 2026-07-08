<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * discrepancies — short shipments, damage, substitutions, overages, wrong
 * item. delivery_item_id is nullable because some discrepancies (e.g. an
 * entire pallet missing) attach to the delivery as a whole rather than a
 * specific line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();

            $table->foreignId('delivery_item_id')
                ->nullable()
                ->constrained('delivery_items')
                ->restrictOnDelete();

            $table->foreignId('discrepancy_type_id')
                ->constrained('discrepancy_types')
                ->restrictOnDelete();

            $table->text('description');

            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('delivery_id');
            $table->index('discrepancy_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancies');
    }
};
