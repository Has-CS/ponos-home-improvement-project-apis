<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram search indexes for the material-request catalog type-ahead.
 *
 * The type-ahead matches with a LEADING wildcard (name ILIKE '%break%') so a
 * foreman can find "20A Breaker" by typing "break". A btree index cannot serve
 * that — Postgres falls back to a sequential scan of catalog_items on every
 * keystroke. A GIN index using gin_trgm_ops does serve it, which is what keeps
 * the search usable once the catalog is large.
 *
 * pg_trgm is NOT among the extensions enabled by migration 000001 (citext,
 * pgcrypto, btree_gist), so it is created here. Same IF NOT EXISTS guard and the
 * same caveat: the DB role running migrations needs CREATE EXTENSION rights, and
 * this is a no-op if a DBA pre-installed it.
 *
 * Both indexes are partial (WHERE deleted_at IS NULL) — the search only ever
 * looks at live rows, so soft-deleted ones are dead weight in the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS catalog_items_name_trgm_idx
                         ON catalog_items USING gin (name gin_trgm_ops)
                         WHERE deleted_at IS NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS catalog_items_description_trgm_idx
                         ON catalog_items USING gin (description gin_trgm_ops)
                         WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog_items_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS catalog_items_description_trgm_idx');

        // The extension is deliberately NOT dropped — it is cluster-shared, and
        // other databases may depend on it. Same reasoning as migration 000001.
    }
};
