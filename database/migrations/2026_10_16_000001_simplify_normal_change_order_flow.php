<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normal change-order flow simplified, per client: the prepare-document and
 * counter-sign steps are removed.
 *
 *   OLD  pending_admin → pending_document → pending_counter_sign → pending_gc
 *   NEW  pending_admin → approved → (send-to-gc) → pending_gc
 *
 * 1. Adds `approved`.
 * 2. Moves Normal change orders parked at either retired status to `approved`,
 *    from where they are sent to the GC. None of them had been counter-signed
 *    (counter-sign moved a CO to pending_gc). Any PDF filed at prepare-document
 *    is superseded when the CO is sent. No approval row is written for the move:
 *    there is no actor to attribute it to.
 * 3. Soft-deletes the two retired statuses, so they drop out of the status
 *    lookup. Not hard-deleted: historical change_order_approvals rows reference
 *    them, and ChangeOrderApproval::fromStatus()/toStatus() read withTrashed().
 *
 * The Emergency flow never used either retired status, so it is unaffected.
 * The approvals action CHECK is left alone: `prepare_document`/`counter_sign`
 * stay valid for historical rows.
 */
return new class extends Migration
{
    private const RETIRED = ['pending_document', 'pending_counter_sign'];

    public function up(): void
    {
        $approvedId = DB::table('change_order_statuses')
            ->where('code', 'approved')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $approvedId) {
            $approvedId = DB::table('change_order_statuses')->insertGetId([
                'code' => 'approved',
                'label' => 'Approved – Ready to Send',
                'sort_order' => 7,
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $retiredIds = DB::table('change_order_statuses')
            ->whereIn('code', self::RETIRED)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($retiredIds->isEmpty()) {
            return;
        }

        $normalTypeId = DB::table('change_order_types')->where('code', 'normal')->value('id');

        DB::table('change_orders')
            ->where('change_order_type_id', $normalTypeId)
            ->whereIn('change_order_status_id', $retiredIds)
            ->update(['change_order_status_id' => $approvedId, 'updated_at' => now()]);

        DB::table('change_order_statuses')
            ->whereIn('id', $retiredIds)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Restores the retired statuses and parks `approved` change orders at
     * pending_document (the step that followed Admin approval). Change orders
     * already sent to the GC stay at pending_gc.
     */
    public function down(): void
    {
        DB::table('change_order_statuses')
            ->whereIn('code', self::RETIRED)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);

        $approvedId = DB::table('change_order_statuses')->where('code', 'approved')->whereNull('deleted_at')->value('id');
        $pendingDocumentId = DB::table('change_order_statuses')->where('code', 'pending_document')->value('id');

        if ($approvedId && $pendingDocumentId) {
            DB::table('change_orders')
                ->where('change_order_status_id', $approvedId)
                ->update(['change_order_status_id' => $pendingDocumentId, 'updated_at' => now()]);
        }

        // Soft, not hard: approval rows written since up() reference it.
        DB::table('change_order_statuses')
            ->where('code', 'approved')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
