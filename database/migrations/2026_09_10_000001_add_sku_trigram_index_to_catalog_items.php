<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram search index for `sku`, completing the set added by
 * 2026_08_09_000001_add_trigram_search_indexes_to_catalog_items.
 *
 * The catalog type-ahead now matches SKU as well as name and description — a
 * buyer holding a supplier's part number should be able to type it straight
 * into the picker. That match uses a LEADING wildcard (sku ILIKE '%EW-E1%'),
 * which a btree index cannot serve: the partial unique index on `sku` enforces
 * uniqueness but does nothing for a contains-search, so without this index
 * Postgres falls back to a sequential scan on every keystroke — exactly the
 * problem the name/description indexes were created to avoid.
 *
 * pg_trgm is already enabled by that earlier migration; the guard here is
 * belt-and-braces in case this one is ever run against a database where it is
 * not. Partial (WHERE deleted_at IS NULL) for the same reason as its siblings:
 * the search only ever looks at live rows, so soft-deleted ones would be dead
 * weight in the index.
 *
 * Purely additive — no application code depends on the index existing, only its
 * performance does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS catalog_items_sku_trgm_idx
                         ON catalog_items USING gin (sku gin_trgm_ops)
                         WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog_items_sku_trgm_idx');

        // The extension is deliberately NOT dropped — it is cluster-shared and
        // other databases (and the sibling indexes) may depend on it. Same
        // reasoning as the migration that created it.
    }
};
