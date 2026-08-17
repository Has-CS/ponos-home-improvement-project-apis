<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `pending_document` change-order status.
 *
 * The Normal CO flow gains an explicit step between Admin review and the
 * counter-signature: the PM prepares the formal change-order document. Before
 * this, `approve` landed straight in `pending_counter_sign`, which conflated
 * "the Admin approved the request" with "there is a document ready to sign" —
 * and in fact no document was ever generated at all.
 *
 * Done as a migration rather than a LookupSeeder edit alone because
 * LookupSeeder::seedCoded() uses firstOrCreate(): on an existing database it
 * would insert the new row but silently leave every other row's sort_order
 * untouched, so the status list would render out of flow order. The seeder is
 * updated too, for fresh installs.
 *
 * sort_order is renumbered by inserting at 6 and shifting the tail down one.
 * The existing arrangement is otherwise preserved exactly — `sent_back` and
 * `rejected_internal` stay where they are rather than being regrouped, so this
 * is the smallest change that puts the new status in the right place.
 */
return new class extends Migration
{
    private const CODE = 'pending_document';

    /** Final sort_order for every status, in flow order. */
    private const NEW_ORDER = [
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

    /** sort_order as it stood before this migration. */
    private const OLD_ORDER = [
        'draft' => 1,
        'pending_pm' => 2,
        'pending_admin' => 3,
        'sent_back' => 4,
        'rejected_internal' => 5,
        'pending_counter_sign' => 6,
        'pending_gc' => 7,
        'active' => 8,
        'gc_rejected' => 9,
        'cancelled' => 10,
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
                'label' => 'Pending Document',
                'sort_order' => self::NEW_ORDER[self::CODE],
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->applyOrder(self::NEW_ORDER);
    }

    /**
     * Reversible only while no change order sits in `pending_document` and no
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
