<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\CostCode;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A purchase-order line that fulfils a request line takes WHAT is being bought
 * from that request line.
 *
 * Asking the client for the catalog item as well gives two values that can
 * disagree with nothing comparing them — which is how an order for "Electric
 * Breakers" was once accepted against a request for "PPR Cold & Hot Water Pipe
 * 32mm". The unit travels with it because the over-order rule compares requested
 * against ordered quantities, and those only mean the same thing in one unit.
 */
class PurchaseOrderLineDerivationTest extends TestCase
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

        $this->project = Project::factory()->create(['short_code' => 'DRV']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create(['name' => 'Derivation Test Supply', 'email' => 'orders@derivetest.com']);
    }

    private function approvedMr(?string $prose = null): MaterialRequest
    {
        return MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'request_text' => $prose,
            'created_by' => $this->procurement->id,
        ]);
    }

    /** A requested line for a specific catalog item, in a specific unit. */
    private function mrLine(MaterialRequest $mr, ?CatalogItem $item, string $unitCode = 'ls', $quantity = 50): MaterialRequestItem
    {
        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => $item?->id,
            'unit_id' => Unit::where('code', $unitCode)->value('id'),
            'quantity' => $quantity,
            'description' => $item === null ? '2x 8ft pressure-treated 4x4' : null,
            'cost_code_id' => CostCode::query()->orderBy('id')->value('id'),
        ]);
    }

    private function createPo(MaterialRequest $mr, array $items): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => $items,
        ]);
    }

    /* ---------------- derivation ---------------- */

    public function test_the_catalog_item_and_unit_come_from_the_requested_line(): void
    {
        $mr = $this->approvedMr();
        $pipe = CatalogItem::factory()->create(['name' => 'PPR Cold & Hot Water Pipe 32mm']);
        $requested = $this->mrLine($mr, $pipe);

        // Only the link, the quantity and a price — nothing about WHAT is bought.
        $poId = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 50,
            'unit_price' => 30,
        ]])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.catalog_item.id', $pipe->id)
            ->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'catalog_item_id' => $pipe->id,
            'unit_id' => $requested->unit_id,
        ]);
    }

    public function test_sending_the_same_catalog_item_is_accepted(): void
    {
        $mr = $this->approvedMr();
        $pipe = CatalogItem::factory()->create();
        $requested = $this->mrLine($mr, $pipe);

        $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'catalog_item_id' => $pipe->id,          // agrees — redundant, not wrong
            'unit_id' => $requested->unit_id,
            'quantity_ordered' => 50,
            'unit_price' => 30,
        ]])->assertStatus(201);
    }

    public function test_the_requests_cost_code_is_used_when_none_is_given(): void
    {
        $mr = $this->approvedMr();
        $requested = $this->mrLine($mr, CatalogItem::factory()->create());

        $poId = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 10,
            'unit_price' => 5,
        ]])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'cost_code_id' => $requested->cost_code_id,
        ]);
    }

    public function test_an_explicit_cost_code_still_wins(): void
    {
        $mr = $this->approvedMr();
        $requested = $this->mrLine($mr, CatalogItem::factory()->create());
        $other = CostCode::query()->orderByDesc('id')->value('id');

        $poId = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'cost_code_id' => $other,
            'quantity_ordered' => 10,
            'unit_price' => 5,
        ]])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'cost_code_id' => $other,
        ]);
    }

    /* ---------------- the bug this closes ---------------- */

    /** The exact payload that ordered breakers against a request for pipe. */
    public function test_a_different_catalog_item_is_refused(): void
    {
        $mr = $this->approvedMr();
        $pipe = CatalogItem::factory()->create(['name' => 'PPR Cold & Hot Water Pipe 32mm']);
        $breakers = CatalogItem::factory()->create(['name' => 'Electric Breakers']);
        $requested = $this->mrLine($mr, $pipe);

        $response = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'catalog_item_id' => $breakers->id,
            'quantity_ordered' => 50,
            'unit_price' => 30,
        ]])->assertStatus(422);

        // The message has to name both sides, or it cannot be acted on.
        $this->assertStringContainsString('PPR Cold & Hot Water Pipe 32mm', $response->json('message'));
        $this->assertStringContainsString('Electric Breakers', $response->json('message'));

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    public function test_a_different_unit_is_refused(): void
    {
        $mr = $this->approvedMr();
        $item = CatalogItem::factory()->create();
        $requested = $this->mrLine($mr, $item, 'ls');

        $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'quantity_ordered' => 50,
            'unit_price' => 30,
        ]])->assertStatus(422);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /** Editing a line must not become a back door to the same swap. */
    public function test_a_line_cannot_be_edited_onto_a_different_catalog_item(): void
    {
        $mr = $this->approvedMr();
        $pipe = CatalogItem::factory()->create();
        $requested = $this->mrLine($mr, $pipe);

        $poId = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 10,
            'unit_price' => 30,
        ]])->assertStatus(201)->json('data.id');

        $po = PurchaseOrder::findOrFail($poId);
        $line = $po->items()->firstOrFail();

        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}/items/{$line->id}", [
                'catalog_item_id' => CatalogItem::factory()->create()->id,
            ])
            ->assertStatus(422);

        $this->assertSame($pipe->id, $line->fresh()->catalog_item_id);
    }

    /* ---------------- where there is nothing to derive from ---------------- */

    /** A free-text request line carries no catalog item; the buyer chooses one. */
    public function test_a_free_text_request_line_still_takes_a_catalog_item(): void
    {
        $mr = $this->approvedMr();
        $requested = $this->mrLine($mr, null);          // description only
        $chosen = CatalogItem::factory()->create();

        $poId = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'catalog_item_id' => $chosen->id,
            'quantity_ordered' => 10,
            'unit_price' => 30,
        ]])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'catalog_item_id' => $chosen->id,
        ]);
    }

    public function test_a_free_text_line_without_a_catalog_item_is_refused_clearly(): void
    {
        $mr = $this->approvedMr();
        $requested = $this->mrLine($mr, null);

        $response = $this->createPo($mr, [[
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 10,
            'unit_price' => 30,
        ]])->assertStatus(422);

        // A clear 422, not the 404 a bare findOrFail would have produced.
        $this->assertStringContainsString('needs a catalog item', $response->json('message'));
    }

    public function test_a_prose_request_still_takes_a_freely_chosen_item(): void
    {
        $mr = $this->approvedMr('Need 20 steel nuts and a roll of 2.5mm wire');
        $chosen = CatalogItem::factory()->create();

        $this->createPo($mr, [[
            'catalog_item_id' => $chosen->id,
            'quantity_ordered' => 500,
            'unit_price' => 2,
        ]])->assertStatus(201);
    }
}
