<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * change_orders — both Normal and Emergency. Value, status, and the
 * authorization chain reconcile with the project's CO log (which is THIS
 * table filtered by project_id plus change_order_approvals — no separate
 * log table is needed).
 *
 * gc_decision_id is modeled explicitly because the GC is a third party who
 * does NOT use the system. An internal user sets the GC status manually
 * based on out-of-band communication; the workflow status (internal) and the
 * GC decision (external) are deliberately distinct.
 *
 * change_order_no minted via document_sequences (R-5).
 *
 * Emergency COs jump straight to active and rely on change_order_signatures
 * for on-device GC-rep authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->string('change_order_no', 40);

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            $table->foreignId('cost_code_id')
                ->nullable()
                ->constrained('cost_codes')
                ->restrictOnDelete();

            $table->foreignId('change_order_type_id')
                ->constrained('change_order_types')
                ->restrictOnDelete();

            $table->foreignId('change_order_status_id')
                ->constrained('change_order_statuses')
                ->restrictOnDelete();

            $table->foreignId('gc_decision_id')
                ->constrained('gc_decisions')
                ->restrictOnDelete();

            $table->foreignId('originator_id')->constrained('users')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('scope')->nullable();
            $table->string('location', 200)->nullable(); // emergency: where
            $table->foreignId('urgency_id')
                ->nullable()
                ->constrained('urgencies')
                ->restrictOnDelete();

            $table->decimal('value', 16, 2)->nullable();

            $table->foreignId('document_attachment_id')
                ->nullable()
                ->constrained('attachments')
                ->restrictOnDelete();

            $table->foreignId('counter_signed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('counter_signed_at')->nullable();

            $table->foreignId('gc_decision_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('gc_decision_at')->nullable();
            $table->text('gc_decision_notes')->nullable();

            $table->timestampTz('became_active_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['project_id', 'change_order_status_id']);
            $table->index('change_order_type_id');
            $table->index('originator_id');
        });

        DB::statement("CREATE UNIQUE INDEX change_orders_change_order_no_unique
                         ON change_orders (change_order_no)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('change_orders');
    }
};
