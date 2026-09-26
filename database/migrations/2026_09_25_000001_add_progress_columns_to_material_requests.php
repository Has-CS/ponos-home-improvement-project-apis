<?php

use App\Models\MaterialRequest;
use App\Services\MaterialRequest\MaterialRequestService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split fulfilment out of `status`.
 *
 * `material_request_status_id` was carrying two unrelated things: where a request
 * sits in the approval chain, and how much of it has been bought and received.
 * The fulfilment half was written as one-way latches, so it misreported three
 * ways — the first purchase order marked the whole request ordered however little
 * it covered, cancelling that order never reversed it, and a request could reach
 * "delivered" with a line nobody had ever bought.
 *
 * Approval now stops at `approved` and stays there; these two columns carry
 * progress, recomputed from the requested lines by
 * MaterialRequestService::recomputeProgress().
 *
 * The `ordered`, `partially_delivered` and `delivered` rows stay in
 * material_request_statuses: material_request_approvals references them by id,
 * and dropping them would tear a hole in the approval history.
 */
return new class extends Migration
{
    private const ORDERING = ['not_ordered', 'partially_ordered', 'fully_ordered'];

    private const DELIVERY = ['not_delivered', 'partially_delivered', 'delivered'];

    /** The fulfilment values that used to live in the status column. */
    private const LEGACY_FULFILMENT = ['ordered', 'partially_delivered', 'delivered'];

    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('ordering_status', 20)->default('not_ordered')->after('material_request_status_id');
            $table->string('delivery_status', 20)->default('not_delivered')->after('ordering_status');
        });

        $this->addChecks();

        // Filtering the buyer's queue on "not yet fully ordered" is the whole
        // point of storing these.
        Schema::table('material_requests', function (Blueprint $table) {
            $table->index('ordering_status');
        });

        $this->backfill();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE material_requests DROP CONSTRAINT IF EXISTS material_requests_ordering_status_check');
        DB::statement('ALTER TABLE material_requests DROP CONSTRAINT IF EXISTS material_requests_delivery_status_check');

        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropIndex(['ordering_status']);
            $table->dropColumn(['ordering_status', 'delivery_status']);
        });

        // The statuses those requests used to carry cannot be recovered — the
        // progress they describe now lives only in the dropped columns. Rolling
        // back leaves every request at `approved`, which is true of all of them.
    }

    private function addChecks(): void
    {
        $ordering = implode(',', array_map(fn ($v) => "'{$v}'", self::ORDERING));
        $delivery = implode(',', array_map(fn ($v) => "'{$v}'", self::DELIVERY));

        DB::statement("ALTER TABLE material_requests
                         ADD CONSTRAINT material_requests_ordering_status_check
                         CHECK (ordering_status IN ({$ordering}))");
        DB::statement("ALTER TABLE material_requests
                         ADD CONSTRAINT material_requests_delivery_status_check
                         CHECK (delivery_status IN ({$delivery}))");
    }

    /**
     * Compute both columns for every request, then move anything still carrying a
     * fulfilment status back to `approved`.
     *
     * Order matters: recompute first, so the progress is recorded before the
     * status that used to imply it is overwritten.
     */
    private function backfill(): void
    {
        $service = app(MaterialRequestService::class);

        MaterialRequest::query()->chunkById(100, function ($requests) use ($service) {
            foreach ($requests as $request) {
                $service->recomputeProgress($request);
            }
        });

        $approvedId = DB::table('material_request_statuses')->where('code', 'approved')->value('id');

        $legacyIds = DB::table('material_request_statuses')
            ->whereIn('code', self::LEGACY_FULFILMENT)
            ->pluck('id');

        DB::table('material_requests')
            ->whereIn('material_request_status_id', $legacyIds)
            ->update(['material_request_status_id' => $approvedId]);
    }
};
