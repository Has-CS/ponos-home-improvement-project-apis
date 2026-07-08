<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * change_order_approvals — the authorization chain (validate → forward →
 * approve/reject → counter-sign → set GC status), with comments and the
 * status transition each action caused.
 *
 * THE single source the project "change order log authorization chain" reads
 * from. Pairs with change_orders the same way material_request_approvals
 * pairs with material_requests.
 *
 * action uses varchar + CHECK (stable internal discriminator, §5).
 *
 * actor_role is a snapshot so the chain stays readable even after a user's
 * role changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('change_order_id')
                ->constrained('change_orders')
                ->restrictOnDelete();

            $table->integer('step_no');

            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 40)->nullable();

            $table->string('action', 30);
            $table->text('comments')->nullable();

            $table->foreignId('from_status_id')
                ->nullable()
                ->constrained('change_order_statuses')
                ->restrictOnDelete();

            $table->foreignId('to_status_id')
                ->nullable()
                ->constrained('change_order_statuses')
                ->restrictOnDelete();

            $table->timestampTz('acted_at')->useCurrent();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['change_order_id', 'step_no']);
            $table->index('actor_id');
        });

        DB::statement("ALTER TABLE change_order_approvals
                         ADD CONSTRAINT change_order_approvals_action_check
                         CHECK (action IN ('submit','validate','forward','approve','reject','counter_sign','set_gc_status'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_approvals');
    }
};
