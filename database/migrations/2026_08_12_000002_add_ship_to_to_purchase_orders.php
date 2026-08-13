<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ship To / Deliver To on a purchase order.
 *
 * A PO previously said what was being bought and from whom, but never where it
 * should land — so nothing sent to a vendor could state a destination.
 *
 * FK **and** snapshot, the same shape purchase_order_items already uses for
 * pricing (vendor_rate_id for traceability alongside the unit_price/line_total
 * snapshot, because "vendor rates change after a PO is cut; the PO must
 * preserve what was quoted"). The identical argument applies here: an address
 * may be corrected or retired long after the order went out, and an issued PO
 * must keep printing what it printed on the day it was issued.
 *
 *  - ship_to_address_id  the reference. Which address row was chosen — lets a
 *                        UI re-select it, and links a PO back to the site.
 *  - ship_to_*           the snapshot. What actually gets printed. Frozen in
 *                        practice by PurchaseOrderService::assertDraft(), which
 *                        already blocks every edit once a PO leaves `draft`, so
 *                        no separate freeze step is needed.
 *
 * ship_to_project_name / _code are snapshotted for the same reason, not derived
 * live from the project: projects.name and projects.code are both editable via
 * PATCH /projects/{project}, and a rename must not retroactively rewrite the
 * header of an order already in a vendor's hands.
 *
 * No "deliver by" column is added — purchase_orders.expected_delivery_date
 * already carries that and is already settable at create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('ship_to_address_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('project_delivery_addresses')
                ->restrictOnDelete();

            // Snapshot block. All nullable: a draft PO may legitimately have no
            // destination yet — issue() is where it becomes mandatory.
            $table->string('ship_to_label', 200)->nullable()->after('ship_to_address_id');
            $table->string('ship_to_attention', 200)->nullable()->after('ship_to_label');
            $table->string('ship_to_street_1', 200)->nullable()->after('ship_to_attention');
            $table->string('ship_to_street_2', 200)->nullable()->after('ship_to_street_1');
            $table->string('ship_to_city', 120)->nullable()->after('ship_to_street_2');
            $table->string('ship_to_state', 120)->nullable()->after('ship_to_city');
            $table->string('ship_to_postal_code', 20)->nullable()->after('ship_to_state');
            $table->string('ship_to_country', 120)->nullable()->after('ship_to_postal_code');
            $table->string('ship_to_contact_phone', 40)->nullable()->after('ship_to_country');
            $table->text('ship_to_delivery_notes')->nullable()->after('ship_to_contact_phone');

            // Project identity as printed on the document.
            $table->string('ship_to_project_name', 200)->nullable()->after('ship_to_delivery_notes');
            $table->string('ship_to_project_code', 40)->nullable()->after('ship_to_project_name');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['ship_to_address_id']);
            $table->dropColumn([
                'ship_to_address_id',
                'ship_to_label',
                'ship_to_attention',
                'ship_to_street_1',
                'ship_to_street_2',
                'ship_to_city',
                'ship_to_state',
                'ship_to_postal_code',
                'ship_to_country',
                'ship_to_contact_phone',
                'ship_to_delivery_notes',
                'ship_to_project_name',
                'ship_to_project_code',
            ]);
        });
    }
};
