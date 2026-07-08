<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vendor_rates — effective-dated vendor pricing. History is NEVER overwritten.
 *
 * A new price closes the prior row (effective_to = today) and inserts a new
 * row with effective_from = today, effective_to = NULL. superseded_by_id
 * chains the lineage.
 *
 * Partial unique index guarantees ONE open rate per (vendor, item).
 *
 * A stronger, optional EXCLUDE-USING-gist constraint stops *any* two periods
 * overlapping (not just two open ones). It's commented out at the bottom —
 * enable when you've confirmed btree_gist is available and you want overlap
 * prevention beyond open-rate uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('rate', 14, 2);
            $table->char('currency', 3)->default('USD');

            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // NULL = currently active.

            $table->string('source', 40)->nullable(); // phone / email / quote
            $table->text('notes')->nullable();

            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('vendor_rates')
                ->restrictOnDelete();

            $table->foreignId('entered_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['catalog_item_id', 'effective_from']);
            $table->index('vendor_id');
        });

        // Guard the dating itself.
        DB::statement("ALTER TABLE vendor_rates
                         ADD CONSTRAINT vendor_rates_dates_check
                         CHECK (effective_to IS NULL OR effective_to >= effective_from)");

        // One open rate per (vendor, item) while alive (§3, vendor_rates).
        DB::statement("CREATE UNIQUE INDEX vendor_rates_open_unique
                         ON vendor_rates (vendor_id, catalog_item_id)
                         WHERE effective_to IS NULL AND deleted_at IS NULL");

        // OPTIONAL — stronger guarantee: no two periods overlap for the same
        // (vendor, item). Requires btree_gist (enabled in 0001). Uncomment if
        // you want the planner to also reject overlaps among CLOSED periods.
        /*
        DB::statement("ALTER TABLE vendor_rates ADD CONSTRAINT vendor_rates_no_overlap
            EXCLUDE USING gist (
                vendor_id WITH =,
                catalog_item_id WITH =,
                daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[]') WITH &&
            ) WHERE (deleted_at IS NULL)");
        */
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_rates');
    }
};
