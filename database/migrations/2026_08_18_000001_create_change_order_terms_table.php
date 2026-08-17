<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * change_order_terms — the standing contractual text printed at the foot of a
 * change-order document: Payment Terms, Changes, and Acceptance.
 *
 * These three sections appear on the paperwork the company currently produces by
 * hand, and until now had no home in the schema at all — the Acceptance prose was
 * hardcoded into the Blade template, and the other two were simply missing.
 *
 * ONE table covers both scopes, exactly as purchase_order_terms does:
 *
 *   project_id IS NULL  the generic default, applying to every project
 *   project_id = X      that project's override, winning over the default
 *
 * So "a single global setting" and "a per-project CRUD" are not competing
 * designs — they are the same row shape, resolved in a single query
 * (ChangeOrderTermsService::resolveFor()).
 *
 * THREE body columns rather than one, unlike the PO's single `body`: the
 * document prints three separately headed sections, not one clause list.
 *
 * NOT versioned, and NOT delete-guarded: a change order snapshots the text onto
 * itself when its document is prepared (see the columns added in 000002), so
 * editing or removing a row here can never alter a document already issued. The
 * snapshot is the history.
 *
 * Plain text, one paragraph per line (blank lines allowed and collapsed).
 * Deliberately not HTML/Markdown: this text is authored through a CRUD and
 * rendered into a PDF, so stored markup would be an injection surface in the
 * generated document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->text('payment_terms_body')->nullable();
            $table->text('changes_body')->nullable();
            $table->text('acceptance_body')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
        });

        // At most one live override per project.
        DB::statement('CREATE UNIQUE INDEX change_order_terms_project_unique
                         ON change_order_terms (project_id)
                         WHERE project_id IS NOT NULL AND deleted_at IS NULL');

        // At most one live default. Postgres treats NULLs as DISTINCT in a
        // unique index, so the index above would happily allow any number of
        // project_id IS NULL rows — silently giving the system several
        // "defaults" and making resolveFor() pick one arbitrarily. Indexing a
        // constant expression across just those rows forces them to collide.
        DB::statement('CREATE UNIQUE INDEX change_order_terms_default_unique
                         ON change_order_terms ((true))
                         WHERE project_id IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_terms');
    }
};
