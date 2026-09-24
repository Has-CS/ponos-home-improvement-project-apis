<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrder\PurchaseOrderService;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A retired vendor takes no new orders.
 *
 * "Retired" means here what it means for catalog items: never offered for NEW
 * selection, while everything already recorded against them stands. So creating
 * and issuing are blocked, and an order already issued keeps reading, printing
 * and moving on exactly as before.
 */
class PurchaseOrderVendorActiveTest extends TestCase
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

        $this->project = Project::factory()->create(['code' => 'PNS-2026-401']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create([
            'name' => 'Vendor Active Test Supply Co.',
            'email' => 'orders@vendoractivetest.com',
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

    private function createPo(?Vendor $vendor = null): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->approvedMr()->id,
            'vendor_id' => ($vendor ?? $this->vendor)->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 3,
                'unit_price' => 50,
            ]],
        ]);
    }

    private function issue(int $poId): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue");
    }

    private function retireVendor(): void
    {
        $this->vendor->update(['is_active' => false]);
    }

    /* ---------------- new orders ---------------- */

    public function test_inactive_vendor_cannot_receive_a_new_purchase_order(): void
    {
        $this->retireVendor();

        $this->createPo()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_active_vendor_still_receives_purchase_orders(): void
    {
        $this->createPo()->assertStatus(201);

        $this->assertDatabaseCount('purchase_orders', 1);
    }

    /* ---------------- issuing ---------------- */

    public function test_draft_cannot_be_issued_after_its_vendor_is_retired(): void
    {
        $poId = $this->createPo()->assertStatus(201)->json('data.id');

        $this->retireVendor();

        $this->issue($poId)->assertStatus(422);

        // Still a draft, and never filed a document.
        $po = PurchaseOrder::with('status')->findOrFail($poId);
        $this->assertSame('draft', $po->status->code);
        $this->assertNull($po->issued_at);
    }

    public function test_draft_issues_normally_while_the_vendor_is_active(): void
    {
        $poId = $this->createPo()->assertStatus(201)->json('data.id');

        $this->issue($poId)->assertOk();

        $this->assertSame('issued', PurchaseOrder::with('status')->findOrFail($poId)->status->code);
    }

    /* ---------------- history is untouched ---------------- */

    public function test_retiring_a_vendor_leaves_orders_already_issued_alone(): void
    {
        $poId = $this->createPo()->assertStatus(201)->json('data.id');
        $this->issue($poId)->assertOk();

        $this->retireVendor();

        // Reads, the document, and marking it sent all still work: the order
        // already left, and retirement only blocks NEW commitment.
        $this->actingAs($this->procurement, 'api')->getJson("/api/v1/purchase-orders/{$poId}")->assertOk();
        $this->actingAs($this->procurement, 'api')->get("/api/v1/purchase-orders/{$poId}/pdf")->assertOk();
        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$poId}/send")->assertOk();

        $this->assertSame('sent', PurchaseOrder::with('status')->findOrFail($poId)->status->code);
    }

    /* ---------------- layer 2: the service's own invariant ---------------- */

    public function test_service_rejects_an_inactive_vendor_on_its_own(): void
    {
        // What the team.global middleware does on a real request; without it the
        // call dies on a 403 and this would pass for the wrong reason.
        setPermissionsTeamId(0);

        $this->retireVendor();

        try {
            app(PurchaseOrderService::class)->create([
                'material_request_id' => $this->approvedMr()->id,
                'vendor_id' => $this->vendor->id,
                'items' => [[
                    'catalog_item_id' => CatalogItem::factory()->create()->id,
                    'quantity_ordered' => 3,
                    'unit_price' => 50,
                ]],
            ], $this->procurement);

            $this->fail('Expected the service to reject an inactive vendor.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
