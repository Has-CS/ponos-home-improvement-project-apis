<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * change_order_scope_items — the Scope of Work quantity table printed on a
 * change-order document.
 *
 * change_orders.scope is a single free-text column, HTML-escaped and rendered
 * with nl2br. It cannot express the structure the client's paperwork uses:
 * a division heading, group headings, emphasis rows carrying their own quantity,
 * indented material sub-lines, and a subtotal. Runs of whitespace collapse in
 * HTML and stored markup is deliberately escaped, so no amount of formatting in
 * that string produces columns. Hence a real child table.
 *
 * Follows purchase_order_items / material_request_items / estimate_line_items —
 * three existing line-item precedents — but carries NO pricing: a change order's
 * money is the single change_orders.value decimal by design, with no breakdown.
 * These rows describe WHAT the work is and HOW MUCH of it, never what it costs.
 *
 * row_type is a CHECK-constrained varchar rather than a lookup table, matching
 * change_order_approvals.action: a stable internal discriminator that drives
 * styling, not user-editable reference data.
 *
 * NO snapshot columns, unlike the GC (gc_*) and the terms. These are children of
 * the change order itself and are frozen by the same
 * ChangeOrderService::assertEditable() rule that blocks every edit from
 * pending_counter_sign onward — the same argument that covers inclusions and
 * exclusions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_scope_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('change_order_id')
                ->constrained('change_orders')
                ->restrictOnDelete();

            // section   a division band          e.g. "DIVISION 09- FINISHES"
            // group     a heading within it      e.g. "OPENINGS", "DRYWALL"
            // emphasis  a called-out category, which MAY carry a quantity ("WALL 44 LF")
            // line      an ordinary work item
            // subline   an indented material breakdown under a line
            // spacer    a deliberate blank row; the only type with no label
            // subtotal  a labelled closing row. NOT computed — summing a column
            //           that mixes SF, LF, EA and GAL is meaningless.
            $table->string('row_type', 20);

            $table->string('label', 255)->nullable();

            // Nullable: heading rows carry no quantity. Same precision as
            // purchase_order_items.quantity_ordered.
            $table->decimal('quantity', 14, 3)->nullable();

            $table->foreignId('unit_id')
                ->nullable()
                ->constrained('units')
                ->restrictOnDelete();

            // Nesting depth WITHIN a row type, driving left padding on the
            // description cell. Kept separate from row_type so the frontend can
            // indent without inventing new types.
            $table->smallInteger('indent')->default(0);

            // Explicit print order. Assigned from the payload array's index —
            // array order is print order, and the client never sends this.
            $table->integer('sort_order');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['change_order_id', 'sort_order']);
        });

        DB::statement("ALTER TABLE change_order_scope_items
                         ADD CONSTRAINT change_order_scope_items_row_type_check
                         CHECK (row_type IN ('section','group','emphasis','line','subline','spacer','subtotal'))");

        // A quantity without a unit would print a bare number in a table whose
        // whole purpose is quantity + unit. Enforced here as well as in the
        // FormRequest so a direct write cannot produce an unprintable row.
        DB::statement('ALTER TABLE change_order_scope_items
                         ADD CONSTRAINT change_order_scope_items_qty_needs_unit
                         CHECK (quantity IS NULL OR unit_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_scope_items');
    }
};
