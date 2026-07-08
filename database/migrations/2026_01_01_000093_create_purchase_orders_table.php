<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_orders — generated when Admin finalizes a request. Sent to the
 * vendor manually (sent_at), modeled to allow future integration with a
 * vendor portal / EDI without schema change.
 *
 * material_request_id is NULLABLE because (open question Q6) one finalized
 * request may produce multiple POs (one per vendor). The schema supports it.
 *
 * po_number minted via document_sequences (R-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 40);

            $table->foreignId('material_request_id')
                ->nullable()
                ->constrained('material_requests')
                ->restrictOnDelete();

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();

            $table->foreignId('purchase_order_status_id')
                ->constrained('purchase_order_statuses')
                ->restrictOnDelete();

            $table->decimal('total_amount', 16, 2)->default(0);

            $table->foreignId('issued_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_id');
            $table->index('vendor_id');
            $table->index('material_request_id');
            $table->index('purchase_order_status_id');
        });

        DB::statement("CREATE UNIQUE INDEX purchase_orders_po_number_unique
                         ON purchase_orders (po_number)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
