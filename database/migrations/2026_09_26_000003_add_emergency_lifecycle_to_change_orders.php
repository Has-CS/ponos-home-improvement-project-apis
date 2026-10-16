<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Emergency change order's two-stage lifecycle: what was signed on site, and
 * what happened to it commercially afterwards.
 *
 * ---- signed_*: the frozen authorization ----
 *
 * FK-less snapshot columns, the fourth block on this table to take that shape
 * after gc_* (2026_08_17_000002), the terms bodies (2026_08_18_000002) and
 * purchase_orders.ship_to_* before them. Same argument every time, and it is the
 * decisive one here: the unit of immutability in this codebase is already a set
 * of snapshot columns on the parent row, absent from $fillable, written by
 * forceFill() at one named moment. Putting the signed narrative anywhere else
 * would invent a second mechanism for a job this one already does.
 *
 * WHY a snapshot at all. The GC's representative signs a description of work.
 * The PM then prices that change order and may edit it — that is the entire point
 * of splitting capture from pricing. Without a frozen copy the record that was
 * signed silently becomes the record that is billed, and the evidence is gone.
 * With one, the two can be printed side by side and a General Contractor can see
 * exactly what they authorised against what they are being asked to pay.
 *
 * Mirrors material_requests.request_text, which holds a foreman's original prose
 * on the parent row while the office user's structured version lives in the child
 * table — the same division of labour, reached the same way.
 *
 * All nullable, and null is the normal case: a Normal change order never writes
 * one. Grandfathered emergency rows keep them null too, which the document reads
 * as "no separate as-signed record; the change order narrative IS the signed
 * narrative" — true of those rows, because assertEditable() blocked every edit
 * for their whole lives.
 *
 * ---- the lifecycle stamps ----
 *
 * work_authorized_at is NOT a duplicate of change_order_signatures.signed_at, and
 * the difference matters in a dispute. signed_at is client-supplied
 * ($data['signed_at'] ?? now(), validated only as a date) so a device can backdate
 * it; work_authorized_at is server time. One is when the pen touched glass
 * according to the device, the other is when this system recorded it.
 *
 * became_active_at keeps its meaning and is no longer set at capture — only when
 * the GC actually approves. Conflating the two is the bug this whole change
 * exists to fix.
 *
 * sent_to_gc_by / _at mirror counter_signed_by / _at. They are needed beyond the
 * audit chain: the document's "Authorised by, for and on behalf of" line has no
 * counter-signer to name on this path, and the outbound email's Reply-To has no
 * issued_by analogue to resolve from.
 *
 * gc_sent_via records WHICH route reached the GC. Stored rather than derived from
 * the approvals chain because the Admin oversight queue filters on it in SQL, and
 * a join over change_order_approvals to answer "which change orders skipped me"
 * is the wrong shape for a list endpoint. The chain corroborates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            // The narrative as signed. Widths match the live columns they freeze:
            // title/location are varchar on change_orders, the rest are text.
            $table->string('signed_title', 200)->nullable()->after('exclusions');
            $table->text('signed_description')->nullable()->after('signed_title');
            $table->text('signed_scope')->nullable()->after('signed_description');
            $table->string('signed_location', 200)->nullable()->after('signed_scope');
            $table->text('signed_inclusions')->nullable()->after('signed_location');
            $table->text('signed_exclusions')->nullable()->after('signed_inclusions');

            $table->timestampTz('work_authorized_at')->nullable()->after('became_active_at');

            $table->foreignId('priced_by')
                ->nullable()
                ->after('work_authorized_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('priced_at')->nullable()->after('priced_by');

            $table->foreignId('sent_to_gc_by')
                ->nullable()
                ->after('priced_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('sent_to_gc_at')->nullable()->after('sent_to_gc_by');

            $table->string('gc_sent_via', 20)->nullable()->after('sent_to_gc_at');
        });

        // varchar + CHECK rather than a lookup table, matching
        // change_order_approvals.action and change_order_scope_items.row_type: a
        // stable internal discriminator that drives an oversight filter, not
        // user-editable reference data.
        DB::statement("ALTER TABLE change_orders
                         ADD CONSTRAINT change_orders_gc_sent_via_check
                         CHECK (gc_sent_via IS NULL OR gc_sent_via IN ('pm_direct','admin_reviewed'))");

        // The oversight queue's primary filter: every change order a PM sent to
        // the GC without Admin review. Partial, because the overwhelming majority
        // of rows have never been sent and should not be in the index.
        DB::statement('CREATE INDEX change_orders_gc_sent_via_index
                         ON change_orders (gc_sent_via)
                         WHERE gc_sent_via IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS change_orders_gc_sent_via_index');
        DB::statement('ALTER TABLE change_orders DROP CONSTRAINT IF EXISTS change_orders_gc_sent_via_check');

        Schema::table('change_orders', function (Blueprint $table) {
            $table->dropForeign(['priced_by']);
            $table->dropForeign(['sent_to_gc_by']);
            $table->dropColumn([
                'signed_title',
                'signed_description',
                'signed_scope',
                'signed_location',
                'signed_inclusions',
                'signed_exclusions',
                'work_authorized_at',
                'priced_by',
                'priced_at',
                'sent_to_gc_by',
                'sent_to_gc_at',
                'gc_sent_via',
            ]);
        });
    }
};
