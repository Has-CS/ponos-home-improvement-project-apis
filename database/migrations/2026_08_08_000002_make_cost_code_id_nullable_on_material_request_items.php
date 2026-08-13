<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes material_request_items.cost_code_id nullable.
 *
 * The original R-3 design put the cost code on the LINE (rather than the header)
 * so one request could mix codes — that stays true. What changes is that it is
 * no longer MANDATORY at request time: cost coding belongs to the estimation /
 * job-costing phase, which is not built yet, and it is not something field staff
 * can reasonably assign. Requiring it blocked the whole field flow for a value
 * nothing currently consumes.
 *
 * This also brings the column into line with the rest of the schema —
 * purchase_order_items, estimate_line_items and change_orders all already
 * declare cost_code_id nullable. material_request_items was the lone outlier.
 *
 * The FK to cost_codes and the existing index('cost_code_id') are deliberately
 * left in place; both tolerate NULLs, and keeping them means a PM/Admin can fill
 * the code in later (while the request is still editable) with no further
 * schema work. Raw SQL rather than ->change(), matching how migration 000108
 * handles Postgres column alterations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE material_request_items ALTER COLUMN cost_code_id DROP NOT NULL');
    }

    /**
     * Re-tightens the column. This will FAIL LOUDLY if any live row has a NULL
     * cost_code_id by then — which is correct: the alternative would be silently
     * inventing a cost code for lines that were never coded. Backfill first if
     * this ever needs to roll back.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE material_request_items ALTER COLUMN cost_code_id SET NOT NULL');
    }
};
