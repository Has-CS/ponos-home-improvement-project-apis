<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which trades each vendor supplies — a vendor <-> trade_category pivot.
 *
 * The client needs to find vendors by the kind of work they supply ("who does
 * Doors", "who does Electrical"). That vocabulary already exists as
 * trade_categories — the same taxonomy catalog items are classified by, with its
 * own CRUD and seed data — so vendors reuse it rather than growing a second list
 * that could drift from the first.
 *
 * MANY-to-many because suppliers rarely serve one trade: a joinery shop supplies
 * both Doors and Framing & Carpentry, and should be found under either.
 *
 * Named by Laravel's alphabetical pivot convention (trade_category < vendor),
 * matching the existing project_user pivot, so the belongsToMany relations need
 * no explicit table argument.
 *
 * The composite primary key is the uniqueness guarantee — a vendor cannot carry
 * the same trade twice. Its leading column serves the filter direction
 * (trade -> vendors); the separate vendor_id index serves loading a page of
 * vendors' trades for the list.
 *
 * restrictOnDelete() on both keys per the codebase-wide rule. Both parents only
 * ever SOFT-delete, which that constraint cannot see, so the matching guard lives
 * in TradeCategoryService::delete() — the same arrangement VendorService::delete()
 * already uses for vendor rates.
 *
 * Pivot rows are relationships, not records: no soft deletes here. Re-assigning a
 * vendor's trades replaces its rows outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_category_vendor', function (Blueprint $table) {
            $table->foreignId('trade_category_id')
                ->constrained('trade_categories')
                ->restrictOnDelete();

            $table->foreignId('vendor_id')
                ->constrained('vendors')
                ->restrictOnDelete();

            $table->timestampsTz();

            $table->primary(['trade_category_id', 'vendor_id']);
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_category_vendor');
    }
};
