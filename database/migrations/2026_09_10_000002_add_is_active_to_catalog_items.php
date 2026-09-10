<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle flag letting a catalog item be RETIRED without being deleted.
 *
 * Until now an item could never leave the catalog. CatalogItemService::delete()
 * refuses to remove anything referenced by a vendor rate, an estimate line, a
 * material request or a purchase order — correctly, because those references
 * have to survive — and there was no other mechanism. A discontinued product
 * therefore stayed in the material-request, RFQ and purchase-order pickers
 * forever, and the catalog only ever grew.
 *
 * Deactivating hides an item from those pickers (CatalogItemService::search()
 * filters on this column) while leaving every historical reference intact and
 * readable: existing document lines still resolve their catalogItem relation,
 * and the detail endpoint still serves the item. Retiring is a listing concern,
 * never a data-integrity one.
 *
 * Shape copied from vendors.is_active — same default, same index, and the same
 * surrounding API (a dedicated PATCH .../status endpoint plus an optional
 * ?is_active= list filter), so the two lifecycle flags in this system behave
 * identically.
 *
 * The DB-side default means every existing row becomes active on migrate, with
 * no backfill and no behaviour change for anything already in the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_custom');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
