<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram search index for vendor names.
 *
 * The vendor-rate list gained a free-text `search` that matches the catalog item
 * (name, SKU) OR the vendor name — because those are the two things anyone
 * actually remembers about a price. The item half is already served by the GIN
 * indexes from 2026_08_09_000001_add_trigram_search_indexes_to_catalog_items.
 *
 * `vendors.name` has only a plain btree, which cannot serve a leading-wildcard
 * match (name ILIKE '%riverside%') — Postgres falls back to a sequential scan.
 * Negligible at today's handful of vendors, and exactly the thing that stops
 * being negligible as the list grows, which is why its sibling indexes exist.
 *
 * pg_trgm is already enabled by that earlier migration; the guard is
 * belt-and-braces for a database where it is not. Partial (WHERE deleted_at IS
 * NULL) for the same reason as its siblings: the search only looks at live rows.
 *
 * Purely additive — no application code depends on the index existing, only its
 * performance does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS vendors_name_trgm_idx
                         ON vendors USING gin (name gin_trgm_ops)
                         WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vendors_name_trgm_idx');

        // The extension is deliberately NOT dropped — it is cluster-shared and
        // the catalog_items indexes depend on it.
    }
};
