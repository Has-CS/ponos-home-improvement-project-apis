<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the change-order approval action vocabulary.
 *
 * Three additions, each closing a real gap in the chain:
 *
 *  - 'send_back' and 'cancel' — until now sendBack(), reject() and cancel() ALL
 *    logged 'reject', so a request returned for revision was indistinguishable
 *    from one killed outright, and from one the originator withdrew. On a
 *    contractual document that is not an acceptable audit trail; a reader had
 *    to infer the difference from to_status_id.
 *
 *  - 'prepare_document' — the new PM step that generates the formal change-order
 *    document. It is a distinct act from 'approve' (which records a review
 *    decision) and from 'counter_sign' (which records a signature), so it gets
 *    its own verb rather than being folded into either.
 *
 * Same shape as 2026_08_10_000001 on the material-request side.
 */
return new class extends Migration
{
    private const OLD = "'submit','validate','forward','approve','reject','counter_sign','set_gc_status'";

    private const NEW = "'submit','validate','forward','approve','reject','send_back','counter_sign','prepare_document','set_gc_status','cancel'";

    public function up(): void
    {
        $this->replaceCheck(self::NEW);
    }

    /**
     * Reversible, but only while no row uses one of the new verbs — dropping
     * back to the old list would fail the constraint against any existing
     * 'send_back' / 'prepare_document' / 'cancel' row. It refuses rather than
     * silently discarding audit history.
     */
    public function down(): void
    {
        $this->replaceCheck(self::OLD);
    }

    private function replaceCheck(string $allowed): void
    {
        DB::statement('ALTER TABLE change_order_approvals
                         DROP CONSTRAINT IF EXISTS change_order_approvals_action_check');

        DB::statement("ALTER TABLE change_order_approvals
                         ADD CONSTRAINT change_order_approvals_action_check
                         CHECK (action IN ({$allowed}))");
    }
};
