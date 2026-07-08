<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * material_request_approvals — one row per action in the routing chain.
 * Records the actor, what they did, why, and the status transition the
 * action caused.
 *
 * action uses varchar + CHECK rather than a lookup table — stable internal
 * discriminator, not user-managed workflow (§5).
 *
 * approver_role is a snapshot of the actor's role label so the chain stays
 * readable even if the user's role changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('material_request_id')
                ->constrained('material_requests')
                ->restrictOnDelete();

            $table->integer('step_no');

            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('approver_role', 40)->nullable();

            $table->string('action', 20);
            $table->text('comments')->nullable();

            $table->foreignId('from_status_id')
                ->nullable()
                ->constrained('material_request_statuses')
                ->restrictOnDelete();

            $table->foreignId('to_status_id')
                ->nullable()
                ->constrained('material_request_statuses')
                ->restrictOnDelete();

            $table->timestampTz('acted_at')->useCurrent();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['material_request_id', 'step_no']);
            $table->index('approver_id');
        });

        DB::statement("ALTER TABLE material_request_approvals
                         ADD CONSTRAINT material_request_approvals_action_check
                         CHECK (action IN ('submit','approve','edit','send_back','reject'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_approvals');
    }
};
