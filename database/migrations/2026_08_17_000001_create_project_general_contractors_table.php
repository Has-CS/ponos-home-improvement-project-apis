<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_general_contractors — the General Contractor(s) a project runs under.
 *
 * Ponos operates as a subcontractor beneath a GC on at least some projects, and
 * the change-order document is addressed TO that GC. Until now no GC existed
 * anywhere in the schema: `gc_decisions` is a three-row lookup
 * (pending/approved/rejected) and change_orders.gc_decision_by is the INTERNAL
 * user who recorded the GC's out-of-band answer. There was no name, address or
 * contact to print, so the change-order PDF fell back to the project's client.
 *
 * `clients` is a different party and is deliberately left alone: a client is the
 * owner commissioning the project, a GC is who Ponos contracts under. A project
 * can legitimately have both.
 *
 * Modelled directly on project_delivery_addresses — project-owned rows, several
 * per project, one flagged primary — because the requirement is the same shape:
 * structured party details attached to a project and snapshotted onto a
 * document. Unlike clients.address / vendors.address (free text), this is
 * structured, because it has to print as a formatted block.
 *
 * NOT deletable-guarded: a change order snapshots these values onto itself (see
 * the gc_* columns added in the next migration), so retiring a GC can never
 * alter or break a document already issued. Soft-delete is the retire mechanism
 * — the row stays for any historical FK but drops out of the dropdown.
 *
 * No address_fingerprint dedupe column, unlike delivery addresses: sites get
 * re-entered often, a project has one or two GCs. Add one if duplicates appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_general_contractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            $table->string('name', 200);                        // the GC company
            $table->string('contact_name', 200)->nullable();    // who the CO is addressed to

            $table->string('street_1', 200);
            $table->string('street_2', 200)->nullable();        // unit / suite
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 120)->default('United States');

            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_primary')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
        });

        // Case-insensitive, matching clients.email — the same address typed with
        // different capitalisation is the same address.
        DB::statement('ALTER TABLE project_general_contractors
                         ALTER COLUMN email TYPE CITEXT USING email::citext');

        // At most ONE primary GC per project while alive. Same partial-unique
        // guarantee project_delivery_addresses_one_primary provides;
        // ProjectGeneralContractorService demotes the incumbent inside the same
        // transaction before promoting, so this is a backstop against concurrent
        // writes rather than the routine path.
        DB::statement('CREATE UNIQUE INDEX project_general_contractors_one_primary
                         ON project_general_contractors (project_id)
                         WHERE is_primary = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_general_contractors');
    }
};
