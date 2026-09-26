<?php

namespace Tests\Feature\PurchaseOrder;

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
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A purchase order raised directly, with no material request behind it.
 *
 * The same entity and the same machinery as an MR-bound order — numbering,
 * pricing, ship-to, terms, the PDF, attachments, issue and send. What differs is
 * that nothing dictates the project or caps the quantities, so the buyer states
 * the project and the catalog lines themselves, and the right to skip the
 * request-and-approve chain is granted separately.
 */
class StandalonePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Holds create_standalone_purchase_order. */
    private User $pm;

    /** Holds manage_purchase_orders but NOT the standalone right. */
    private User $procurement;

    private Project $project;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->project = Project::factory()->create(['short_code' => 'STA']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->pm = $this->userWithRole('Project Manager');
        $this->procurement = $this->userWithRole('Procurement');

        // A PM reaches a project by being staffed on it.
        app(RoleAssignmentService::class)->assignProjectRole(
            $this->project,
            $this->pm,
            Role::where('name', 'Project Manager')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
            null,
        );

        $this->vendor = Vendor::create(['name' => 'Direct Supply Co.', 'email' => 'orders@directsupply.com']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $user,
            Role::where('name', $role)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        return $user;
    }

    /** @param array<string,mixed> $payload */
    private function createStandalone(array $payload = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->pm, 'api')->postJson('/api/v1/purchase-orders', [
            'project_id' => $this->project->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 12,
                'unit_price' => 25,
            ]],
            ...$payload,
        ]);
    }

    /** An approved request with one line, for the comparison cases. */
    private function approvedMrWithLine($quantity = 10): MaterialRequestItem
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->pm->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->pm->id,
        ]);

        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'quantity' => $quantity,
        ]);
    }

    /* ---------------- creating one ---------------- */

    public function test_an_order_can_be_raised_with_no_material_request(): void
    {
        $response = $this->createStandalone()
            ->assertStatus(201)
            ->assertJsonPath('data.material_request_id', null)
            ->assertJsonPath('data.source', 'standalone')
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonPath('data.total_amount', '300.00');   // 12 x 25

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $response->json('data.id'),
            'material_request_id' => null,
            'project_id' => $this->project->id,
        ]);
    }

    public function test_it_is_numbered_from_the_named_project(): void
    {
        Carbon::setTestNow('2026-09-25 09:00:00');

        $this->createStandalone()
            ->assertStatus(201)
            ->assertJsonPath('data.po_number', 'STA-2026-09-00001');

        Carbon::setTestNow();
    }

    public function test_the_project_is_required_when_no_request_is_named(): void
    {
        $this->actingAs($this->pm, 'api')->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 1,
                'unit_price' => 10,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_a_line_cannot_claim_to_fulfil_a_requested_line(): void
    {
        $requested = $this->approvedMrWithLine();

        $this->createStandalone(['items' => [[
            'catalog_item_id' => $requested->catalog_item_id,
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 1,
            'unit_price' => 10,
        ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.material_request_item_id']);
    }

    public function test_a_line_without_a_catalog_item_is_rejected(): void
    {
        // Nothing to derive from on this route, so the catalog item is the only
        // way to say what is being bought — and to price it.
        $response = $this->createStandalone(['items' => [[
            'quantity_ordered' => 1,
            'unit_price' => 10,
        ]]])->assertStatus(422);

        $this->assertStringContainsString('catalog item', $response->json('message') ?? '');
    }

    public function test_a_retired_vendor_is_refused_here_too(): void
    {
        $this->vendor->update(['is_active' => false]);

        $this->createStandalone()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);
    }

    /* ---------------- no ceiling on quantities ---------------- */

    /**
     * The over-order rule measures against a requested quantity. With no request
     * there is nothing to measure against, and the rule is unreachable — it takes
     * a MaterialRequest to be called at all.
     */
    public function test_there_is_no_quantity_ceiling(): void
    {
        $item = CatalogItem::factory()->create();

        foreach ([500, 500] as $quantity) {
            $this->createStandalone(['items' => [[
                'catalog_item_id' => $item->id,
                'quantity_ordered' => $quantity,
                'unit_price' => 1,
            ]]])->assertStatus(201);
        }

        $this->assertSame(2, PurchaseOrder::whereNull('material_request_id')->count());
    }

    /* ---------------- the permission ---------------- */

    public function test_a_buyer_without_the_right_cannot_raise_one(): void
    {
        $this->createStandalone(as: $this->procurement)->assertStatus(403);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /** The same buyer can still cut orders the ordinary way. */
    public function test_that_buyer_can_still_create_from_a_material_request(): void
    {
        $requested = $this->approvedMrWithLine();

        $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $requested->material_request_id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'material_request_item_id' => $requested->id,
                'quantity_ordered' => 10,
                'unit_price' => 10,
            ]],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.source', 'material_request');
    }

    public function test_a_project_the_user_cannot_reach_is_refused(): void
    {
        $other = Project::factory()->create(['short_code' => 'OTH']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $other->id]);

        // The PM is staffed on $this->project only.
        $this->createStandalone(['project_id' => $other->id])->assertStatus(403);
    }

    /* ---------------- the shared machinery still runs ---------------- */

    public function test_lines_attachments_issue_and_send_all_work_unchanged(): void
    {
        Storage::fake('local');

        $poId = $this->createStandalone()->assertStatus(201)->json('data.id');
        $po = PurchaseOrder::findOrFail($poId);

        // Add, change and remove lines.
        $this->actingAs($this->pm, 'api')->postJson("/api/v1/purchase-orders/{$poId}/items", [
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'quantity_ordered' => 3,
            'unit_price' => 10,
        ])->assertStatus(201)->assertJsonPath('data.total_amount', '330.00');

        $first = $po->items()->orderBy('id')->firstOrFail();
        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}/items/{$first->id}", ['quantity_ordered' => 2])
            ->assertOk()
            ->assertJsonPath('data.total_amount', '80.00');     // 2x25 + 3x10

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$poId}/items/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.total_amount', '30.00');

        // Supporting files.
        $this->actingAs($this->pm, 'api')
            ->post("/api/v1/purchase-orders/{$poId}/attachments", ['files' => [UploadedFile::fake()->image('quote.png')]])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.attachments');

        // Issue and send.
        $this->actingAs($this->pm, 'api')->postJson("/api/v1/purchase-orders/{$poId}/issue")->assertOk();
        $this->actingAs($this->pm, 'api')->postJson("/api/v1/purchase-orders/{$poId}/send")
            ->assertOk()
            ->assertJsonPath('data.delivery.to', $this->vendor->email);

        $this->assertSame('sent', PurchaseOrder::with('status')->findOrFail($poId)->status->code);
    }

    /** The PDF renders with no Request no. row and stays a valid document. */
    public function test_the_pdf_renders_without_a_material_request(): void
    {
        $poId = $this->createStandalone()->assertStatus(201)->json('data.id');

        $bytes = $this->actingAs($this->pm, 'api')
            ->get("/api/v1/purchase-orders/{$poId}/pdf")
            ->assertOk()
            ->content();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', substr($bytes, -2048));
    }

    /** Receiving against a standalone order must not look for a request. */
    public function test_a_delivery_can_be_recorded_against_it(): void
    {
        $poId = $this->createStandalone()->assertStatus(201)->json('data.id');
        $po = PurchaseOrder::findOrFail($poId);

        $this->actingAs($this->pm, 'api')->postJson("/api/v1/purchase-orders/{$poId}/issue")->assertOk();

        $this->actingAs($this->pm, 'api')->postJson("/api/v1/purchase-orders/{$poId}/deliveries", [
            'items' => [[
                'purchase_order_item_id' => $po->items()->firstOrFail()->id,
                'quantity_received' => 12,
            ]],
        ])->assertStatus(201);

        $this->assertSame('received', PurchaseOrder::with('status')->findOrFail($poId)->status->code);
    }

    /* ---------------- telling the two apart ---------------- */

    public function test_the_list_can_be_filtered_by_source(): void
    {
        $this->createStandalone()->assertStatus(201);

        $requested = $this->approvedMrWithLine();
        $this->actingAs($this->pm, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $requested->material_request_id,
            'vendor_id' => $this->vendor->id,
            'items' => [['material_request_item_id' => $requested->id, 'quantity_ordered' => 10, 'unit_price' => 5]],
        ])->assertStatus(201);

        $this->actingAs($this->pm, 'api')->getJson('/api/v1/purchase-orders?source=standalone')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.source', 'standalone');

        $this->actingAs($this->pm, 'api')->getJson('/api/v1/purchase-orders?source=material_request')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.source', 'material_request');

        $this->actingAs($this->pm, 'api')->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }
}
