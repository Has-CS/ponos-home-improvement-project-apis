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
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Purchase orders are top-level, not project-nested, so there is no
 * project.access middleware to lean on. Procurement (and Admin) keep
 * cross-project access by design (they aren't project-staffed); a Project
 * Manager must be staffed on a PO's project for every read and write below.
 */
class PurchaseOrderScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $procurement;
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
        $this->pm = $this->userWithRole('Project Manager');

        $this->projectA = Project::factory()->create(['code' => 'PNS-2026-201']);
        $this->projectB = Project::factory()->create(['code' => 'PNS-2026-202']);

        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectA->id]);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectB->id]);

        $this->vendor = Vendor::create([
            'name' => 'PO Scope Test Supply Co.',
            'contact_name' => 'Sam Rivera',
            'email' => 'orders@poscopetest.com',
            'phone' => '(555) 555-0111',
            'address' => "200 Test Avenue\nChicago, IL 60601",
        ]);

        // PM is staffed on Project A only.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", ['user_id' => $this->pm->id])
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

    private function approvedMr(Project $project): MaterialRequest
    {
        return MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);
    }

    /** Always created as Procurement, who is unrestricted — a neutral fixture-builder. */
    private function createPo(Project $project): PurchaseOrder
    {
        $mr = $this->approvedMr($project);

        $response = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 3,
                'unit_price' => 50,
            ]],
        ]);
        $response->assertStatus(201);

        return PurchaseOrder::findOrFail($response->json('data.id'));
    }

    /* ---------------- reads: list / queue ---------------- */

    public function test_pm_sees_only_their_own_projects_pos_in_the_list(): void
    {
        $this->createPo($this->projectA);
        $this->createPo($this->projectB);

        $pmResponse = $this->actingAs($this->pm, 'api')->getJson('/api/v1/purchase-orders');
        $pmResponse->assertOk();
        $this->assertCount(1, $pmResponse->json('data.items'));

        $procurementResponse = $this->actingAs($this->procurement, 'api')->getJson('/api/v1/purchase-orders');
        $this->assertCount(2, $procurementResponse->json('data.items'));

        $adminResponse = $this->actingAs($this->admin, 'api')->getJson('/api/v1/purchase-orders');
        $this->assertCount(2, $adminResponse->json('data.items'));
    }

    public function test_pm_sees_only_their_own_projects_pending_requests(): void
    {
        $this->approvedMr($this->projectA);
        $this->approvedMr($this->projectB);

        $pmResponse = $this->actingAs($this->pm, 'api')->getJson('/api/v1/purchase-orders/pending-requests');
        $pmResponse->assertOk();
        $this->assertCount(1, $pmResponse->json('data.items'));

        $procurementResponse = $this->actingAs($this->procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests');
        $this->assertCount(2, $procurementResponse->json('data.items'));
    }

    /* ---------------- reads: single PO ---------------- */

    public function test_pm_cannot_view_a_po_from_another_project(): void
    {
        $po = $this->createPo($this->projectB);

        $this->actingAs($this->pm, 'api')->getJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(403);
        $this->actingAs($this->procurement, 'api')->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk();
        $this->actingAs($this->admin, 'api')->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk();
    }

    public function test_pm_cannot_download_a_pos_pdf_from_another_project(): void
    {
        $po = $this->createPo($this->projectB);

        $this->actingAs($this->pm, 'api')->get("/api/v1/purchase-orders/{$po->id}/pdf")->assertStatus(403);
        $this->actingAs($this->procurement, 'api')->get("/api/v1/purchase-orders/{$po->id}/pdf")->assertOk();
    }

    /* ---------------- writes: single PO ---------------- */

    public function test_pm_cannot_update_a_draft_po_from_another_project(): void
    {
        $foreignPo = $this->createPo($this->projectB);
        $ownPo = $this->createPo($this->projectA);

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/purchase-orders/{$foreignPo->id}", ['notes' => 'trying to edit'])
            ->assertStatus(403);

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/purchase-orders/{$ownPo->id}", ['notes' => 'own project note'])
            ->assertOk();
    }

    public function test_pm_cannot_delete_a_draft_po_from_another_project(): void
    {
        $po = $this->createPo($this->projectB);

        $this->actingAs($this->pm, 'api')->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(403);
    }

    public function test_pm_cannot_issue_a_po_from_another_project(): void
    {
        $foreignPo = $this->createPo($this->projectB);
        $ownPo = $this->createPo($this->projectA);

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/purchase-orders/{$foreignPo->id}/issue")
            ->assertStatus(403);

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/purchase-orders/{$ownPo->id}/issue")
            ->assertOk();
    }

    public function test_pm_cannot_send_or_cancel_a_po_from_another_project(): void
    {
        $issuedPo = $this->createPo($this->projectB);
        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$issuedPo->id}/issue")->assertOk();

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/purchase-orders/{$issuedPo->id}/send")
            ->assertStatus(403);

        $draftPo = $this->createPo($this->projectB);
        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/purchase-orders/{$draftPo->id}/cancel")
            ->assertStatus(403);
    }

    /* ---------------- creation / picker ---------------- */

    public function test_pm_cannot_create_a_po_against_another_projects_material_request(): void
    {
        $mr = $this->approvedMr($this->projectB);
        $catalogItemId = CatalogItem::factory()->create()->id;

        $this->actingAs($this->pm, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [['catalog_item_id' => $catalogItemId, 'quantity_ordered' => 2, 'unit_price' => 10]],
        ])->assertStatus(403);

        $ownMr = $this->approvedMr($this->projectA);
        $this->actingAs($this->pm, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $ownMr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [['catalog_item_id' => $catalogItemId, 'quantity_ordered' => 2, 'unit_price' => 10]],
        ])->assertStatus(201);
    }

    public function test_pm_cannot_search_catalog_items_for_another_project(): void
    {
        CatalogItem::factory()->create(['name' => 'Scope Test Widget']);

        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/purchase-orders/catalog-items/search?project_id={$this->projectB->id}&q=Widget")
            ->assertStatus(403);

        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/purchase-orders/catalog-items/search?project_id={$this->projectB->id}&q=Widget")
            ->assertOk();
    }

    /* ---------------- effective terms ---------------- */

    public function test_pm_cannot_view_effective_terms_for_another_project(): void
    {
        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->projectB->id}/purchase-order-terms")
            ->assertStatus(403);

        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->projectA->id}/purchase-order-terms")
            ->assertOk();

        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/projects/{$this->projectB->id}/purchase-order-terms")
            ->assertOk();
    }
}
