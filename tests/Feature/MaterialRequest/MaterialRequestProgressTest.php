<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * How much of a request has been bought, and how much has arrived.
 *
 * These were once one column, written as one-way latches, and it misreported
 * three ways: the first purchase order marked the whole request ordered however
 * little it covered, cancelling that order never reversed it, and "delivered"
 * was decided against what had been ORDERED — so a request could call itself
 * complete with a line nobody had ever bought.
 *
 * `status` now records the approval decision and stops at `approved`.
 */
class MaterialRequestProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $procurement;

    private Project $project;

    private \App\Models\Vendor $vendor;

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

        $this->project = Project::factory()->create(['short_code' => 'PRG']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = \App\Models\Vendor::create([
            'name' => 'Progress Test Supply',
            'email' => 'orders@progresstest.com',
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

    private function line(MaterialRequest $mr, $quantity = 10): MaterialRequestItem
    {
        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'quantity' => $quantity,
        ]);
    }

    /** @param array<int,array{0:MaterialRequestItem,1:mixed}> $lines */
    private function orderFor(MaterialRequest $mr, array $lines): PurchaseOrder
    {
        $items = array_map(fn ($pair) => [
            'material_request_item_id' => $pair[0]->id,
            'quantity_ordered' => $pair[1],
            'unit_price' => 10,
        ], $lines);

        $id = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => $items,
        ])->assertStatus(201)->json('data.id');

        return PurchaseOrder::findOrFail($id);
    }

    private function issueAndReceive(PurchaseOrder $po, array $received): TestResponse
    {
        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/issue")->assertOk();

        $items = [];
        foreach ($po->items()->orderBy('id')->get() as $index => $line) {
            if (isset($received[$index])) {
                $items[] = ['purchase_order_item_id' => $line->id, 'quantity_received' => $received[$index]];
            }
        }

        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/deliveries", ['items' => $items]);
    }

    /** @return array{0:string,1:string,2:string} status, ordering, delivery */
    private function progress(MaterialRequest $mr): array
    {
        $fresh = MaterialRequest::with('status')->findOrFail($mr->id);

        return [$fresh->status->code, $fresh->ordering_status, $fresh->delivery_status];
    }

    /* ---------------- ordering ---------------- */

    public function test_a_request_with_nothing_ordered_reads_not_ordered(): void
    {
        $mr = $this->approvedMr();
        $this->line($mr);

        app(\App\Services\MaterialRequest\MaterialRequestService::class)->recomputeProgress($mr);

        $this->assertSame(['approved', 'not_ordered', 'not_delivered'], $this->progress($mr));
    }

    /** Defect 1: the first order used to latch the whole request. */
    public function test_ordering_one_of_two_lines_is_partially_ordered(): void
    {
        $mr = $this->approvedMr();
        $first = $this->line($mr, 10);
        $this->line($mr, 15);

        $this->orderFor($mr, [[$first, 10]]);

        $this->assertSame(['approved', 'partially_ordered', 'not_delivered'], $this->progress($mr));
    }

    public function test_covering_every_line_is_fully_ordered(): void
    {
        $mr = $this->approvedMr();
        $first = $this->line($mr, 10);
        $second = $this->line($mr, 15);

        $this->orderFor($mr, [[$first, 10], [$second, 15]]);

        $this->assertSame(['approved', 'fully_ordered', 'not_delivered'], $this->progress($mr));
    }

    public function test_part_of_a_line_is_still_partially_ordered(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);

        $this->orderFor($mr, [[$only, 4]]);

        $this->assertSame('partially_ordered', $this->progress($mr)[1]);
    }

    public function test_two_orders_together_cover_a_line(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);

        $this->orderFor($mr, [[$only, 6]]);
        $this->assertSame('partially_ordered', $this->progress($mr)[1]);

        // Split across a second vendor's order — together they cover it.
        $this->orderFor($mr, [[$only, 4]]);
        $this->assertSame('fully_ordered', $this->progress($mr)[1]);
    }

    /* ---------------- defect 2: it never reversed ---------------- */

    public function test_cancelling_the_order_releases_the_request(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);
        $po = $this->orderFor($mr, [[$only, 10]]);

        $this->assertSame('fully_ordered', $this->progress($mr)[1]);

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/cancel")->assertOk();

        $this->assertSame('not_ordered', $this->progress($mr)[1]);
    }

    public function test_deleting_a_draft_order_releases_the_request(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);
        $po = $this->orderFor($mr, [[$only, 10]]);

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertOk();

        $this->assertSame('not_ordered', $this->progress($mr)[1]);
    }

    public function test_reducing_a_line_drops_it_back_to_partially_ordered(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);
        $po = $this->orderFor($mr, [[$only, 10]]);

        $line = $po->items()->firstOrFail();
        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$po->id}/items/{$line->id}", ['quantity_ordered' => 3])
            ->assertOk();

        $this->assertSame('partially_ordered', $this->progress($mr)[1]);
    }

    /* ---------------- defect 3: delivered with a line nobody bought ---------------- */

    /**
     * The damaging one. Receipts used to be judged against what was ORDERED, so
     * fully receiving the only order marked the whole request delivered — while
     * the second line had never been bought at all.
     */
    public function test_receiving_everything_ordered_is_not_delivered_when_a_line_was_never_bought(): void
    {
        $mr = $this->approvedMr();
        $first = $this->line($mr, 10);
        $this->line($mr, 15);          // never ordered

        $po = $this->orderFor($mr, [[$first, 10]]);
        $this->issueAndReceive($po, [0 => 10])->assertStatus(201);

        [$status, $ordering, $delivery] = $this->progress($mr);

        $this->assertSame('approved', $status);
        $this->assertSame('partially_ordered', $ordering);
        $this->assertSame('partially_delivered', $delivery, 'A line nobody ordered must hold the request open.');
    }

    public function test_everything_ordered_and_received_is_delivered(): void
    {
        $mr = $this->approvedMr();
        $first = $this->line($mr, 10);
        $second = $this->line($mr, 15);

        $po = $this->orderFor($mr, [[$first, 10], [$second, 15]]);
        $this->issueAndReceive($po, [0 => 10, 1 => 15])->assertStatus(201);

        $this->assertSame(['approved', 'fully_ordered', 'delivered'], $this->progress($mr));
    }

    public function test_a_short_receipt_is_partially_delivered(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);

        $po = $this->orderFor($mr, [[$only, 10]]);
        $this->issueAndReceive($po, [0 => 4])->assertStatus(201);

        $this->assertSame(['approved', 'fully_ordered', 'partially_delivered'], $this->progress($mr));
    }

    /* ---------------- the buyer's queue ---------------- */

    public function test_the_queue_keeps_a_partly_ordered_request_and_drops_a_finished_one(): void
    {
        $partial = $this->approvedMr();
        $a = $this->line($partial, 10);
        $this->line($partial, 15);
        $this->orderFor($partial, [[$a, 10]]);

        $done = $this->approvedMr();
        $b = $this->line($done, 5);
        $this->orderFor($done, [[$b, 5]]);

        $ids = collect(
            $this->actingAs($this->procurement, 'api')
                ->getJson('/api/v1/purchase-orders/pending-requests')
                ->assertOk()
                ->json('data.items')
        )->pluck('id');

        $this->assertTrue($ids->contains($partial->id), 'A partly ordered request still needs buying.');
        $this->assertFalse($ids->contains($done->id), 'A fully ordered request is finished with.');
    }

    public function test_the_progress_fields_are_exposed_and_filterable(): void
    {
        $mr = $this->approvedMr();
        $only = $this->line($mr, 10);
        $this->orderFor($mr, [[$only, 4]]);

        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/purchase-orders/pending-requests")
            ->assertOk()
            ->assertJsonPath('data.items.0.ordering_status', 'partially_ordered')
            ->assertJsonPath('data.items.0.delivery_status', 'not_delivered');
    }
}
