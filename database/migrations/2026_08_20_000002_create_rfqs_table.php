<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rfqs — Request for Quotation header.
 *
 * A pre-project capability: the team assembles a list of items and sends it to
 * one vendor to get rates for a bid, before any project has been won. So,
 * unlike material_requests / purchase_orders / change_orders:
 *
 *  - project_id is NULLABLE. Most RFQs have no project yet; one MAY be linked
 *    when quoting against work on an already-won project.
 *  - No cost_code_id anywhere in this module (see rfq_items too) — cost coding
 *    is a job-costing concept that doesn't exist pre-project.
 *
 * vendor_id is NOT NULL: an RFQ always targets exactly one real vendor row,
 * because its email is what the document gets sent to. A vendor that doesn't
 * exist yet is created via the ordinary vendor CRUD first — deliberately no
 * ad-hoc vendor fields here, so there is only one place vendor identity lives.
 *
 * rfq_no is minted by document_sequences, same as request_no / po_number /
 * change_order_no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->string('rfq_no', 40);

            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('rfq_status_id')->constrained('rfq_statuses')->restrictOnDelete();

            // Human label / email subject anchor — there may be no project name
            // to anchor the document to.
            $table->string('title', 200);

            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('sent_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('vendor_id');
            $table->index('project_id');
            $table->index('rfq_status_id');
        });

        DB::statement("CREATE UNIQUE INDEX rfqs_rfq_no_unique
                         ON rfqs (rfq_no)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
