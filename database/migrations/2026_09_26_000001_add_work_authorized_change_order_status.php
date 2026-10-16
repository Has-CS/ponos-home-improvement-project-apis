<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `work_authorized` change-order status.
 *
 * The Emergency flow stops being a single atomic act. Today createEmergency()
 * drops the change order straight into `active` — a terminal status — so an
 * emergency CO captured without a value can never be priced, and the on-site
 * signature (which authorises THE WORK) is recorded as though it also settled
 * THE MONEY. Those are two moments, and this status is the first one:
 *
 *   work_authorized   the GC's representative signed on site, work may proceed,
 *                     nothing is priced yet.
 *
 * It doubles as the PM's pricing desk and as the state an Admin sends pricing
 * back to — the actor and the permitted actions are identical in both cases, and
 * change_order_approvals distinguishes them by verb. A second status for
 * "awaiting re-pricing" would cost a watermark arm, a status-pill CSS rule, a
 * seeder row and a client-side label to say something the audit chain already
 * says.
 *
 * NOT terminal, unlike the `active` it replaces at capture time.
 *
 * Done as a migration rather than a LookupSeeder edit alone for the reason
 * 2026_08_16_000001 gives for `pending_document`: LookupSeeder::seedCoded() uses
 * firstOrCreate(), so on an existing database it would insert the new row and
 * silently leave every other row's sort_order untouched, rendering the status
 * list out of flow order. The seeder is updated too, for fresh installs.
 *
 * sort_order is renumbered by inserting at 2 — immediately after `draft`, since
 * it is the emergency path's entry point, the counterpart to draft on the normal
 * one — and shifting the tail down by one. Every existing arrangement is
 * otherwise preserved exactly.
 */
return new class extends Migration
{
    private const CODE = 'work_authorized';

    /** Final sort_order for every status, in flow order. */
    private const NEW_ORDER = [
        'draft' => 1,
        'work_authorized' => 2,
        'pending_pm' => 3,
        'pending_admin' => 4,
        'sent_back' => 5,
        'rejected_internal' => 6,
        'pending_document' => 7,
        'pending_counter_sign' => 8,
        'pending_gc' => 9,
        'active' => 10,
        'gc_rejected' => 11,
        'cancelled' => 12,
    ];

    /** sort_order as it stood before this migration. */
    private const OLD_ORDER = [
        'draft' => 1,
        'pending_pm' => 2,
        'pending_admin' => 3,
        'sent_back' => 4,
        'rejected_internal' => 5,
        'pending_document' => 6,
        'pending_counter_sign' => 7,
        'pending_gc' => 8,
        'active' => 9,
        'gc_rejected' => 10,
        'cancelled' => 11,
    ];

    public function up(): void
    {
        $exists = DB::table('change_order_statuses')
            ->where('code', self::CODE)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            DB::table('change_order_statuses')->insert([
                'code' => self::CODE,
                'label' => 'Work Authorized',
                'sort_order' => self::NEW_ORDER[self::CODE],
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->applyOrder(self::NEW_ORDER);
    }

    /**
     * Reversible only while no change order sits in `work_authorized` and no
     * approval row points at it — the restrictOnDelete() FKs will refuse
     * otherwise. That is the correct behaviour: it fails loudly rather than
     * stranding rows against a deleted status.
     */
    public function down(): void
    {
        DB::table('change_order_statuses')->where('code', self::CODE)->delete();

        $this->applyOrder(self::OLD_ORDER);
    }

    /** @param array<string,int> $order */
    private function applyOrder(array $order): void
    {
        foreach ($order as $code => $sort) {
            DB::table('change_order_statuses')
                ->where('code', $code)
                ->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }
};
