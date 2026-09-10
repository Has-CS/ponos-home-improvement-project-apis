<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System protection for the catalog item type.
 *
 * `catalog_item_types` was not among the six tables that
 * 2026_01_01_000110_add_is_system_to_lookup_tables covered, so
 * LookupService::delete()'s `$lookup->is_system` check has been reading a
 * non-existent attribute here — null, therefore falsy — and these rows have had
 * no system protection at all.
 *
 * That mattered little while three types existed. Now that the seeder ships
 * `material` alone, it matters a lot: catalog_items.catalog_item_type_id is NOT
 * NULL, so deleting the last remaining type would make it impossible to create
 * a catalog item. The in-use guard already blocks deletion while any item
 * references the type, but that leaves exactly the case a new install is in —
 * an empty catalog — unprotected.
 *
 * Only `material` is flagged. Any `labor` / `subcontractor` rows already present
 * in an existing database are deliberately left untouched and still deletable
 * through the normal guarded path once nothing references them; removing them
 * is a separate decision, because live catalog items may still point at them
 * and the FK is restrictOnDelete.
 *
 * `after('label')` rather than the `after('sort_order')` its sibling migration
 * used — this table has no sort_order column. Matched by literal code string,
 * not a model constant, to keep the migration decoupled from app-layer class
 * definitions (the same convention as that migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_item_types', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('label');
        });

        // firstOrCreate in LookupSeeder only sets attributes on CREATE, so an
        // existing `material` row would never pick the flag up from the seeder.
        DB::table('catalog_item_types')->where('code', 'material')->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('catalog_item_types', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
