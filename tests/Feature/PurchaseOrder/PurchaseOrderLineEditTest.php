<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRate;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Line items of a DRAFT purchase order can be added, changed and removed, the
 * same way material-request lines can — so fixing one quantity no longer means
 * deleting the order and burning its PO number.
 *
 * The three rules worth guarding closely: the header total is recomputed from
 * the rows every time, a quantity fix never silently reprices the line, and the
 * over-order check must not count a line against itself.
 */
class PurchaseOrderLineEditTest extends TestCase
{
    use RefreshDatabase;

    private User $procurement;

    private Project $project;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->procurement = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->procurement,
            Role::where('name', 'Procurement')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        $this->project = Project::factory()->create(['code' => 'PNS-2026-701']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create([
            'name' => 'Line Edit Test Supply Co.',
            'email' => 'orders@lineedittest.com',
        ]);
    }

    private function approvedMr(): MaterialRequest
    {
        return MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);
    }

    private function mrLine(MaterialRequest $mr, float|string $quantity): MaterialRequestItem
    {
        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'quantity' => $quantity,
        ]);
    }

    /** The request and requested line behind the most recent draftPo(). */
    private MaterialRequest $mr;

    private MaterialRequestItem $mrLine;

    /** A draft PO with one line: 4 of a request for 20, at $25.00. */
    private function draftPo(): PurchaseOrder
    {
        $this->mr = $this->approvedMr();
        $this->mrLine = $this->mrLine($this->mr, 20);

        $id = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => $this->mrLine->catalog_item_id,
                'material_request_item_id' => $this->mrLine->id,
                'quantity_ordered' => 4,
                'unit_price' => 25,
            ]],
        ])->assertStatus(201)->json('data.id');

        return PurchaseOrder::findOrFail($id);
    }

    /**
     * A draft PO cut from a PROSE-ONLY request: its lines carry no link, so the
     * buyer chooses the catalog item and may change it later.
     */
    private function prosePo(): PurchaseOrder
    {
        $mr = $this->approvedMr();
        $mr->update(['request_text' => 'Need a roll of 2.5mm wire and whatever fittings go with it']);

        $id = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 4,
                'unit_price' => 25,
            ]],
        ])->assertStatus(201)->json('data.id');

        return PurchaseOrder::findOrFail($id);
    }

    private function addItem(PurchaseOrder $po, array $payload): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/items", $payload);
    }

    private function patchItem(PurchaseOrder $po, PurchaseOrderItem $item, array $payload): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$po->id}/items/{$item->id}", $payload);
    }

    private function deleteItem(PurchaseOrder $po, PurchaseOrderItem $item): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$po->id}/items/{$item->id}");
    }

    private function firstItem(PurchaseOrder $po): PurchaseOrderItem
    {
        return $po->items()->orderBy('id')->firstOrFail();
    }

    private function total(PurchaseOrder $po): string
    {
        return (string) $po->fresh()->total_amount;
    }

    /* ---------------- add ---------------- */

    public function test_a_line_can_be_added_to_a_draft_and_the_total_follows(): void
    {
        $po = $this->draftPo();
        $this->assertSame('100.00', $this->total($po));   // 4 x 25

        $this->addItem($po, [
            'catalog_item_id' => $this->mrLine->catalog_item_id,
            'material_request_item_id' => $this->mrLine->id,
            'quantity_ordered' => 6,
            'unit_price' => 10,
        ])
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.total_amount', '160.00');   // 100 + 60

        $this->assertSame('160.00', $this->total($po));
    }

    public function test_an_added_line_must_stay_within_the_requested_quantity(): void
    {
        $po = $this->draftPo();

        // 4 already ordered of 20 requested; 17 more would make 21.
        $this->addItem($po, [
            'catalog_item_id' => $this->mrLine->catalog_item_id,
            'material_request_item_id' => $this->mrLine->id,
            'quantity_ordered' => 17,
            'unit_price' => 10,
        ])->assertStatus(422);

        $this->assertCount(1, $po->fresh()->items);
        $this->assertSame('100.00', $this->total($po));
    }

    public function test_an_added_line_must_name_a_requested_line(): void
    {
        $po = $this->draftPo();

        $this->addItem($po, [
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'quantity_ordered' => 1,
            'unit_price' => 10,
        ])->assertStatus(422);
    }

    /* ---------------- update ---------------- */

    public function test_updating_a_quantity_recomputes_the_line_and_header_totals(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->patchItem($po, $item, ['quantity_ordered' => 7])
            ->assertOk()
            ->assertJsonPath('data.total_amount', '175.00');   // 7 x 25

        $this->assertSame('175.00', (string) $item->fresh()->line_total);
        $this->assertSame('175.00', $this->total($po));
    }

    /**
     * The snapshot is the point of storing unit_price: a quantity fix must not
     * quietly reprice the line against a rate that moved after drafting.
     */
    public function test_updating_a_quantity_never_reprices_the_line(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        // A rate now exists that the line deliberately did not use.
        VendorRate::create([
            'vendor_id' => $this->vendor->id,
            'catalog_item_id' => $item->catalog_item_id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'rate' => 999,
            'effective_from' => now()->subDay(),
            'entered_by' => $this->procurement->id,
        ]);

        $this->patchItem($po, $item, ['quantity_ordered' => 5])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame('25.00', (string) $fresh->unit_price);
        $this->assertNull($fresh->vendor_rate_id);
        $this->assertSame('125.00', (string) $fresh->line_total);
    }

    public function test_supplying_a_unit_price_reprices_the_line_as_a_manual_override(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->patchItem($po, $item, ['unit_price' => 30])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame('30.00', (string) $fresh->unit_price);
        $this->assertNull($fresh->vendor_rate_id);            // not sourced from the rate card
        $this->assertSame('120.00', (string) $fresh->line_total);   // 4 x 30
    }

    /**
     * Swapping is only open on an UNLINKED line — one mapped from a prose
     * request. A line that fulfils a request line takes its item from there and
     * refuses to contradict it, so this uses a prose-only request.
     */
    public function test_swapping_the_catalog_item_resolves_the_vendors_current_rate(): void
    {
        $po = $this->prosePo();
        $item = $this->firstItem($po);

        $replacement = CatalogItem::factory()->create();
        $rate = VendorRate::create([
            'vendor_id' => $this->vendor->id,
            'catalog_item_id' => $replacement->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'rate' => 12.5,
            'effective_from' => now()->subDay(),
            'entered_by' => $this->procurement->id,
        ]);

        $this->patchItem($po, $item, ['catalog_item_id' => $replacement->id])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame($replacement->id, $fresh->catalog_item_id);
        $this->assertSame('12.50', (string) $fresh->unit_price);
        $this->assertSame($rate->id, $fresh->vendor_rate_id);      // traceable to the rate row
        $this->assertSame('50.00', (string) $fresh->line_total);    // 4 x 12.50
    }

    public function test_swapping_to_an_item_with_no_rate_and_no_price_is_rejected(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->patchItem($po, $item, ['catalog_item_id' => CatalogItem::factory()->create()->id])
            ->assertStatus(422);

        $this->assertSame('25.00', (string) $item->fresh()->unit_price);
    }

    /* ---------------- the over-order exclusion ---------------- */

    public function test_a_line_is_not_counted_against_itself_when_edited(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        // 15 of 20 requested.
        $this->patchItem($po, $item, ['quantity_ordered' => 15])->assertOk();

        // Growing to 16 is still inside 20 — it must NOT see its own 15 as
        // already ordered. This is the case a missing exclusion would break.
        $this->patchItem($po, $item, ['quantity_ordered' => 16])->assertOk();
        $this->assertSame('16.000', (string) $item->fresh()->quantity_ordered);

        // Growing past the request still fails.
        $this->patchItem($po, $item, ['quantity_ordered' => 21])->assertStatus(422);
        $this->assertSame('16.000', (string) $item->fresh()->quantity_ordered);

        // Shrinking is always allowed.
        $this->patchItem($po, $item, ['quantity_ordered' => 10])->assertOk();
        $this->assertSame('10.000', (string) $item->fresh()->quantity_ordered);
    }

    public function test_the_exclusion_still_counts_other_purchase_orders(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        // A second PO takes 15 of the 20, leaving 5 for the first PO's line.
        $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => $this->mrLine->catalog_item_id,
                'material_request_item_id' => $this->mrLine->id,
                'quantity_ordered' => 15,
                'unit_price' => 10,
            ]],
        ])->assertStatus(201);

        $this->patchItem($po, $item, ['quantity_ordered' => 6])->assertStatus(422);
        $this->patchItem($po, $item, ['quantity_ordered' => 5])->assertOk();
    }

    /* ---------------- remove ---------------- */

    public function test_a_line_can_be_removed_and_the_total_follows(): void
    {
        $po = $this->draftPo();

        $this->addItem($po, [
            'catalog_item_id' => $this->mrLine->catalog_item_id,
            'material_request_item_id' => $this->mrLine->id,
            'quantity_ordered' => 6,
            'unit_price' => 10,
        ])->assertStatus(201);

        $this->deleteItem($po, $this->firstItem($po))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.total_amount', '60.00');

        $this->assertSame('60.00', $this->total($po));
    }

    public function test_the_last_line_cannot_be_removed(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->deleteItem($po, $item)->assertStatus(409);

        $this->assertCount(1, $po->fresh()->items);
        $this->assertSame('100.00', $this->total($po));
    }

    /* ---------------- status and ownership ---------------- */

    public function test_lines_cannot_be_touched_once_the_order_is_issued(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/issue")
            ->assertOk();

        $this->addItem($po, [
            'catalog_item_id' => $this->mrLine->catalog_item_id,
            'material_request_item_id' => $this->mrLine->id,
            'quantity_ordered' => 1,
            'unit_price' => 10,
        ])->assertStatus(409);

        $this->patchItem($po, $item, ['quantity_ordered' => 5])->assertStatus(409);
        $this->deleteItem($po, $item)->assertStatus(409);

        $this->assertSame('4.000', (string) $item->fresh()->quantity_ordered);
    }

    public function test_lines_cannot_be_touched_once_the_order_is_cancelled(): void
    {
        $po = $this->draftPo();
        $item = $this->firstItem($po);

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/cancel")
            ->assertOk();

        $this->patchItem($po, $item, ['quantity_ordered' => 5])->assertStatus(409);
        $this->deleteItem($po, $item)->assertStatus(409);
    }

    public function test_a_line_from_another_order_is_not_found(): void
    {
        $first = $this->draftPo();
        $second = $this->draftPo();

        $this->patchItem($first, $this->firstItem($second), ['quantity_ordered' => 2])
            ->assertStatus(404);

        $this->deleteItem($first, $this->firstItem($second))->assertStatus(404);
    }

    /* ---------------- arithmetic ---------------- */

    public function test_fractional_quantities_total_correctly_at_two_decimals(): void
    {
        $mr = $this->approvedMr();
        $mrLine = $this->mrLine($mr, '10.5');

        $poId = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => $mrLine->catalog_item_id,
                'material_request_item_id' => $mrLine->id,
                'quantity_ordered' => '2.5',
                'unit_price' => '41.25',
            ]],
        ])->assertStatus(201)->json('data.id');

        $po = PurchaseOrder::findOrFail($poId);
        // bcmul TRUNCATES at 2dp — pre-existing create() arithmetic, unchanged here.
        $this->assertSame('103.12', $this->total($po));   // 2.5 x 41.25 = 103.125

        $this->patchItem($po, $this->firstItem($po), ['quantity_ordered' => '4.75'])
            ->assertOk()
            ->assertJsonPath('data.total_amount', '195.93');   // 4.75 x 41.25 = 195.9375, truncated
    }
}
