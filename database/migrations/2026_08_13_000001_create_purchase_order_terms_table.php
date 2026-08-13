<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_order_terms — the Terms & Conditions a purchase order is issued
 * under, printed at the foot of the PO document.
 *
 * ONE table covers both scopes, the same way catalog_items already does:
 *
 *   project_id IS NULL  the generic default, applying to every project
 *   project_id = X      that project's override, winning over the default
 *
 * So "a single global setting" and "a per-project CRUD" are not two competing
 * designs here — they are the same row shape, and the resolution is a single
 * query (PurchaseOrderTermsService::resolveFor()).
 *
 * NOT versioned, and NOT delete-guarded: a purchase order snapshots the text
 * onto itself (terms_title / terms_body, added in 000002), so editing or
 * removing a row here can never alter an order already issued. The snapshot is
 * the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            // Optional heading override. The document falls back to a literal
            // "TERMS & CONDITIONS" when this is null.
            $table->string('title', 200)->nullable();

            // Plain text, one clause per line (blank lines allowed and
            // collapsed). Deliberately not HTML/Markdown: this text is authored
            // through a CRUD and rendered into a PDF, so stored markup would be
            // an injection surface in the generated document.
            $table->text('body');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
        });

        // At most one live override per project.
        DB::statement("CREATE UNIQUE INDEX purchase_order_terms_project_unique
                         ON purchase_order_terms (project_id)
                         WHERE project_id IS NOT NULL AND deleted_at IS NULL");

        // At most one live default. Postgres treats NULLs as DISTINCT in a
        // unique index, so the index above would happily allow any number of
        // project_id IS NULL rows — silently giving the system several
        // "defaults" and making resolveFor() pick one arbitrarily. Indexing a
        // constant expression across just those rows forces them to collide.
        DB::statement("CREATE UNIQUE INDEX purchase_order_terms_default_unique
                         ON purchase_order_terms ((true))
                         WHERE project_id IS NULL AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_terms');
    }
};
