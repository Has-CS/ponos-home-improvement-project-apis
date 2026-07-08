<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * estimate_line_items — snapshots both unit_price AND the exact vendor_rate_id
 * used. This means a quote can be reconstructed forensically years later
 * even if vendor rates have changed many times since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_line_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('estimate_version_id')
                ->constrained('estimate_versions')
                ->restrictOnDelete();

            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();

            $table->foreignId('cost_code_id')
                ->nullable()
                ->constrained('cost_codes')
                ->restrictOnDelete();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->foreignId('vendor_rate_id')
                ->nullable()
                ->constrained('vendor_rates')
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);  // snapshot
            $table->decimal('line_total', 16, 2);  // snapshot (qty × price)
            $table->integer('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('estimate_version_id');
            $table->index('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_line_items');
    }
};
