<?php

use App\Models\ProjectDeliveryAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop the same delivery address being added to a project twice.
 *
 * The table constrained nothing about address content — only the primary flag
 * — so one destination could be entered any number of times, including under
 * different labels for the same street. This adds the identity column and the
 * index that makes duplicates impossible.
 *
 * Four steps, in order: add the column, backfill it, resolve the duplicates
 * that already exist, then add the index. The index cannot come first — it
 * would fail against exactly the data this migration exists to clean up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_delivery_addresses', function (Blueprint $table) {
            $table->string('address_fingerprint', 500)->nullable()->after('is_primary');
            $table->index(['project_id', 'address_fingerprint']);
        });

        $this->backfill();
        $this->dedupe();

        // Soft-deleted rows are excluded, so retiring an address still leaves it
        // possible to re-create the same one later.
        DB::statement("CREATE UNIQUE INDEX project_delivery_addresses_dedupe
                         ON project_delivery_addresses (project_id, address_fingerprint)
                         WHERE deleted_at IS NULL");
    }

    /**
     * Compute the fingerprint for every existing row.
     *
     * Uses the model rather than an equivalent SQL expression deliberately: the
     * backfill and the runtime MUST normalize identically, and duplicating the
     * rules in SQL is the obvious way for them to drift apart. The usual caveat
     * about models changing under old migrations applies, but by then this will
     * already have run everywhere.
     */
    private function backfill(): void
    {
        ProjectDeliveryAddress::withTrashed()
            ->select(['id', 'label', 'attention', 'street_1', 'street_2', 'city', 'state', 'postal_code', 'country'])
            ->chunkById(200, function ($addresses) {
                foreach ($addresses as $address) {
                    DB::table('project_delivery_addresses')
                        ->where('id', $address->id)
                        ->update(['address_fingerprint' => ProjectDeliveryAddress::fingerprintFor($address)]);
                }
            });
    }

    /**
     * Retire pre-existing duplicates so the unique index can be created.
     *
     * Keeps the project's primary address where the group contains one — losing
     * it would leave the project with no default and no obvious sign why —
     * otherwise the lowest id, i.e. the original entry.
     *
     * Soft-delete only. Nothing is destroyed, and no purchase order is affected
     * either way: every PO snapshots its ship-to address onto itself, so the
     * document is unaffected by anything that happens to the row here.
     */
    private function dedupe(): void
    {
        $groups = DB::table('project_delivery_addresses')
            ->select('project_id', 'address_fingerprint')
            ->whereNull('deleted_at')
            ->groupBy('project_id', 'address_fingerprint')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $keepId = DB::table('project_delivery_addresses')
                ->where('project_id', $group->project_id)
                ->where('address_fingerprint', $group->address_fingerprint)
                ->whereNull('deleted_at')
                ->orderByDesc('is_primary') // the primary survives if there is one
                ->orderBy('id')
                ->value('id');

            DB::table('project_delivery_addresses')
                ->where('project_id', $group->project_id)
                ->where('address_fingerprint', $group->address_fingerprint)
                ->whereNull('deleted_at')
                ->where('id', '!=', $keepId)
                ->update([
                    // Clear is_primary as well: the partial primary index
                    // ignores soft-deleted rows, so a restored duplicate would
                    // otherwise resurrect a second primary.
                    'is_primary' => false,
                    'deleted_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS project_delivery_addresses_dedupe');

        Schema::table('project_delivery_addresses', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'address_fingerprint']);
            $table->dropColumn('address_fingerprint');
        });
    }
};
