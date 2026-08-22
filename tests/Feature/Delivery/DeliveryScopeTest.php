<?php

namespace Tests\Feature\Delivery;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GET /api/v1/deliveries must not leak deliveries across projects to a
 * project-staffed caller, while the procurement desk (Admin or literally the
 * Procurement role — identity-based, see ScopesProjectAccess::isProcurementDesk())
 * keeps the existing cross-project view, mirroring how the purchase-orders
 * index already behaves. Project Manager holds the same manage_purchase_orders
 * permission Procurement does but is NOT part of that exemption — a PM must
 * be staffed on a project to see its deliveries, same as any other role.
 */
class DeliveryScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $procurement;
    private User $foreman;
    private User $pm;

    private Project $projectA;
    private Project $projectB;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->procurement = $this->userWithRole('Procurement');
        $this->foreman = $this->userWithRole('Foreman');
        $this->pm = $this->userWithRole('Project Manager');

        $this->projectA = Project::factory()->create(['code' => 'PNS-2026-101']);
        $this->projectB = Project::factory()->create(['code' => 'PNS-2026-102']);

        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectA->id]);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectB->id]);

        $this->vendor = Vendor::create([
            'name' => 'Scope Test Supply Co.',
            'contact_name' => 'Jordan Hale',
            'email' => 'orders@scopetestsupply.com',
            'phone' => '(555) 555-0100',
            'address' => "100 Test Street\nChicago, IL 60601",
        ]);

        // Foreman and PM are staffed on Project A only.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", [
                'user_id' => $this->foreman->id,
            ])
            ->assertStatus(201);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", [
                'user_id' => $this->pm->id,
            ])
            ->assertStatus(201);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $user,
            Role::where('name', $roleName)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        return $user;
    }

    private function createDeliveryFor(Project $project): void
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);

        $poResponse = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 5,
                'unit_price' => 20,
            ]],
        ]);
        $poResponse->assertStatus(201);

        $po = PurchaseOrder::with('items')->findOrFail($poResponse->json('data.id'));

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/issue")
            ->assertOk();

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/deliveries", [
                'items' => [[
                    'purchase_order_item_id' => $po->items->first()->id,
                    'quantity_received' => 5,
                ]],
            ])
            ->assertStatus(201);
    }

    public function test_a_project_staffed_caller_only_sees_their_own_projects_deliveries(): void
    {
        $this->createDeliveryFor($this->projectA);
        $this->createDeliveryFor($this->projectB);

        $response = $this->actingAs($this->foreman, 'api')->getJson('/api/v1/deliveries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_a_project_manager_only_sees_their_own_projects_deliveries(): void
    {
        $this->createDeliveryFor($this->projectA);
        $this->createDeliveryFor($this->projectB);

        $response = $this->actingAs($this->pm, 'api')->getJson('/api/v1/deliveries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_procurement_sees_deliveries_across_all_projects(): void
    {
        $this->createDeliveryFor($this->projectA);
        $this->createDeliveryFor($this->projectB);

        $response = $this->actingAs($this->procurement, 'api')->getJson('/api/v1/deliveries');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_admin_sees_deliveries_across_all_projects(): void
    {
        $this->createDeliveryFor($this->projectA);
        $this->createDeliveryFor($this->projectB);

        $response = $this->actingAs($this->admin, 'api')->getJson('/api/v1/deliveries');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
    }
}
