<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow 'finalize' in the material-request approval chain.
 *
 * A PM holding `finalize_material_request` can end a request at `pending_pm`
 * instead of routing it to Admin. That is a bypass of a control, so the chain
 * has to say so in its own right rather than leaving a reader to infer it from
 * the pending_pm -> approved status pair.
 *
 * The verb is deliberately distinct from 'approve': an approval moves a request
 * one step along the chain, a finalisation ends it early.
 */
return new class extends Migration
{
    private const OLD = "'submit','approve','edit','send_back','reject'";

    private const NEW = "'submit','approve','edit','send_back','reject','finalize'";

    public function up(): void
    {
        $this->replaceCheck(self::NEW);
    }

    /**
     * Reversible, but only while no row uses the new verb — dropping back to the
     * old list would fail the constraint against any existing 'finalize' row.
     * That is the correct behaviour: it refuses rather than silently discarding
     * audit history.
     */
    public function down(): void
    {
        $this->replaceCheck(self::OLD);
    }

    private function replaceCheck(string $allowed): void
    {
        DB::statement('ALTER TABLE material_request_approvals
                         DROP CONSTRAINT IF EXISTS material_request_approvals_action_check');

        DB::statement("ALTER TABLE material_request_approvals
                         ADD CONSTRAINT material_request_approvals_action_check
                         CHECK (action IN ({$allowed}))");
    }
};
