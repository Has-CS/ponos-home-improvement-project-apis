<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns existing material-request lines with the trade category of the catalog
 * item they point at.
 *
 * material_request_items.trade_category_id used to be captured independently of
 * the selected catalog item and was never cross-checked against it, so live rows
 * can hold NULL, or a category that disagrees with the item (a Doors item filed
 * under Plumbing). From now on the server DERIVES it whenever a catalog item is
 * present — see MaterialRequestService::resolveLineAttributes() — and this
 * migration brings the existing rows up to that same invariant.
 *
 * Two limits, deliberately:
 *
 *  - FREE-TEXT lines (catalog_item_id IS NULL) with a NULL category cannot be
 *    recovered — there is nothing to derive from — so they stay NULL. The
 *    "always populated" guarantee is forward-looking only; the FormRequests now
 *    make the category mandatory on any NEW free-text line.
 *
 *  - This creates new references into trade_categories, so a category that was
 *    previously deletable may now trip the in-use guard in
 *    TradeCategoryService::delete() (which already checks this table). That is
 *    the correct outcome, but it is a real behavioural side effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE material_request_items mri
               SET trade_category_id = ci.trade_category_id,
                   updated_at = now()
              FROM catalog_items ci
             WHERE mri.catalog_item_id = ci.id
               AND mri.deleted_at IS NULL
               AND (mri.trade_category_id IS NULL
                    OR mri.trade_category_id <> ci.trade_category_id)
        ");
    }

    /**
     * Irreversible: the superseded values are not preserved anywhere, and
     * restoring NULLs/mismatches would only reintroduce the inconsistency this
     * migration exists to remove.
     */
    public function down(): void
    {
        // no-op
    }
};
