<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional product photo for a catalog item.
 *
 * Until now an item was text only, so the type-ahead pickers that material
 * requests, RFQs and purchase orders all share offered nothing but a name and a
 * SKU to identify a part by. One picture makes picking the right coupling
 * markedly easier for someone standing on a site.
 *
 * Holds a RELATIVE STORAGE PATH, never a URL and never image bytes — the file
 * lives on the `public` disk and the URL is derived in the API resources, the
 * same arrangement `users.picture_path` uses (see
 * 2026_01_01_000105_add_picture_path_to_users_table).
 *
 * The `public` disk rather than the private one, deliberately: catalog images
 * are served straight to the pickers, and the authenticated download route
 * resolves an attachment with no project_id to Admin-only — which is what
 * catalog items overwhelmingly are. Routing them through it would hide every
 * global item's photo from the Foremen, PMs and buyers who actually use the
 * picker. A product photo also is not commercially sensitive the way a vendor
 * rate is, so a public URL is the right call here.
 *
 * Nullable, and staying that way: every existing item predates the column, and
 * an image is optional by design rather than something to be backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
