<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give pre-existing emergency change orders a work_authorized_at, and NOTHING
 * else.
 *
 * ---- what is deliberately NOT done ----
 *
 * Emergency change orders raised before this redesign sit at `active` with
 * gc_decision = approved, became_active_at set, and a filed PDF a General
 * Contractor may already be holding. They are left exactly where they are:
 *
 *  - Their STATUS is not moved. Under the old model they were authorised and
 *    commercially closed in one act; putting them at `work_authorized` would
 *    reopen settled records and invite someone to re-price and re-send a document
 *    that has already been received.
 *  - Their gc_decision is not corrected. It reads `approved` on the strength of an
 *    on-site signature, which is a claim this redesign stops making — but those
 *    rows genuinely operated under the old contract-of-record, and rewriting it
 *    would falsify history rather than fix it.
 *  - Their signed_* columns stay NULL. Copying the current narrative in would be
 *    factually accurate (assertEditable() blocked every edit for their entire
 *    lives, so it never changed) but it would assert a frozen-at-capture
 *    provenance that no mechanism ever provided. The document reads NULL as "no
 *    separate as-signed record; the change order narrative IS the signed
 *    narrative", which is true of exactly these rows.
 *  - Their filed PDFs are not re-rendered. They print no watermark because they
 *    are `active`, and any status move would silently change what a re-render
 *    shows — heal-on-read can trigger one on any download.
 *
 * ---- what IS done, and why ----
 *
 * work_authorized_at is exposed to clients and drives the new oversight queue's
 * ordering. A column meaning "capture time" for new rows and NULL for every
 * historical one is a reporting trap. For these rows became_active_at IS the
 * capture moment — createEmergency() set it to now() in the same statement that
 * recorded the signature — so the copy is truthful rather than invented.
 *
 * Idempotent: only fills rows where it is still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $emergencyId = DB::table('change_order_types')->where('code', 'emergency')->value('id');

        // A database that has not been seeded yet has nothing to backfill, and
        // this must not fail on a fresh install.
        if ($emergencyId === null) {
            return;
        }

        DB::table('change_orders')
            ->where('change_order_type_id', $emergencyId)
            ->whereNotNull('became_active_at')
            ->whereNull('work_authorized_at')
            ->update(['work_authorized_at' => DB::raw('became_active_at')]);
    }

    /**
     * Safe to reverse: it only clears a value this migration derived, and only on
     * the rows it could have derived it for. Nothing else read it.
     */
    public function down(): void
    {
        $emergencyId = DB::table('change_order_types')->where('code', 'emergency')->value('id');

        if ($emergencyId === null) {
            return;
        }

        DB::table('change_orders')
            ->where('change_order_type_id', $emergencyId)
            ->whereColumn('work_authorized_at', 'became_active_at')
            ->update(['work_authorized_at' => null]);
    }
};
