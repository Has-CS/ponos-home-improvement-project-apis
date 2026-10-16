<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four more verbs for the change-order authorization chain, all of them acts the
 * redesigned Emergency flow performs and the Normal flow never does.
 *
 *  - `capture`            the on-site authorization: a GC representative signed,
 *                         work may proceed, nothing is priced. Deliberately NOT
 *                         reused `submit`, which it replaces on this path.
 *                         Submitting enters a request into a review chain;
 *                         capturing records that work was authorised outside one.
 *                         A reader of the chain must be able to tell which
 *                         happened without inferring it from the status pair.
 *  - `price`              a PM or Admin put the money on it, or revised it. The
 *                         one act the field user is structurally barred from.
 *  - `send_to_gc`         the priced change order went to the General Contractor.
 *                         Distinct from `forward` — which already exists in this
 *                         CHECK, unused, and is the right verb for PM -> Admin
 *                         escalation. "Forward" plainly reads as passed onward to
 *                         the next reviewer; this is the opposite, the reviewer
 *                         skipped. Reusing it would defeat the whole point of
 *                         recording the bypass.
 *  - `void_authorization` the signed authorization was defective and the change
 *                         order was abandoned so the work could be re-captured on
 *                         site. Lands in `cancelled` like an ordinary cancel, so
 *                         the verb is the only thing that distinguishes "we
 *                         dropped this change" from "the paperwork was wrong and
 *                         was done again" — materially different in a dispute.
 *
 * Same shape as 2026_08_16_000002 before it and 2026_08_10_000001 on the
 * material-request side: replace the list, keep OLD/NEW as constants.
 */
return new class extends Migration
{
    private const OLD = "'submit','validate','forward','approve','reject','send_back','counter_sign','prepare_document','set_gc_status','cancel'";

    private const NEW = "'submit','validate','forward','approve','reject','send_back','counter_sign','prepare_document','set_gc_status','cancel','capture','price','send_to_gc','void_authorization'";

    public function up(): void
    {
        $this->replaceCheck(self::NEW);
    }

    /**
     * Reversible, but only while no row uses one of the new verbs — dropping back
     * to the old list would fail the constraint against any existing
     * 'capture' / 'price' / 'send_to_gc' / 'void_authorization' row. It refuses
     * rather than silently discarding audit history.
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
