<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_delivery_addresses — the structured ship-to destinations a project's
 * purchase orders can be delivered to.
 *
 * `projects.site_address` already exists but is a single unstructured text blob,
 * and one project can run several sites at once (a client with two sites under
 * one project). A PO has to name exactly one destination and print it in a
 * formatted block, so both the multiplicity and the structure are needed.
 *
 * This is the FIRST structured address in the schema — clients.address,
 * vendors.address and projects.site_address are all free text. Those are
 * deliberately left alone; nothing reads them for print.
 *
 * NOT deletable-guarded: a purchase order snapshots this row's values onto
 * itself (see the ship_to_* columns added in 000002), so removing an address
 * can never alter or break an already-issued PO. Soft-delete is the retire
 * mechanism — the row stays put for any historical FK, but drops out of the
 * dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            // The recipient line on the printed PO, e.g. "Harrington Residence
            // — North Site". Distinct from projects.name: with several sites on
            // one project, the site is what the driver needs to read.
            $table->string('label', 200);
            $table->string('attention', 200)->nullable(); // contact person / "c/o"

            $table->string('street_1', 200);
            $table->string('street_2', 200)->nullable(); // unit / suite
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 120)->default('United States');

            $table->string('contact_phone', 40)->nullable(); // site contact for the driver
            $table->text('delivery_notes')->nullable();      // gate codes, access instructions

            $table->boolean('is_primary')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
        });

        // At most ONE primary address per project while alive — the same
        // partial-unique-index guarantee vendor_rates uses for its single open
        // rate. ProjectDeliveryAddressService demotes the incumbent inside the
        // same transaction before promoting a new one, so this index is a
        // backstop against concurrent writes rather than the routine path.
        DB::statement("CREATE UNIQUE INDEX project_delivery_addresses_one_primary
                         ON project_delivery_addresses (project_id)
                         WHERE is_primary = true AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_delivery_addresses');
    }
};
