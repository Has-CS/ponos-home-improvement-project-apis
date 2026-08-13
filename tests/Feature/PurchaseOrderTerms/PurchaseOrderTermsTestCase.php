<?php

namespace Tests\Feature\PurchaseOrderTerms;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrderTerm;
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
 * Shared setup for purchase-order Terms & Conditions tests.
 *
 *   $admin       holds manage_lookups (via the '*' wildcard) — the T&C CRUD.
 *   $procurement holds manage_purchase_orders — cuts and issues POs, and may
 *                read the effective terms, but may NOT edit them.
 */
abstract class PurchaseOrderTermsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $procurement;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->project = Project::factory()->create();

        $this->admin = User::factory()->create();
        $this->procurement = User::factory()->create();

        $rbac = app(RoleAssignmentService::class);
        $rbac->assignGlobalRole($this->admin, $this->role('Admin'));
        $rbac->assignGlobalRole($this->procurement, $this->role('Procurement'));
    }

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /* ---------------- terms helpers ---------------- */

    /** The company-wide default set. */
    protected function makeDefaultTerms(string $body, ?string $title = null): PurchaseOrderTerm
    {
        return PurchaseOrderTerm::create(['project_id' => null, 'title' => $title, 'body' => $body]);
    }

    /** A project's override. */
    protected function makeProjectTerms(string $body, ?Project $for = null, ?string $title = null): PurchaseOrderTerm
    {
        return PurchaseOrderTerm::create([
            'project_id' => ($for ?? $this->project)->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    /** @param array<string,mixed> $payload */
    protected function postTerms(array $payload = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'api')
            ->postJson('/api/v1/purchase-order-terms', ['body' => 'A clause.', ...$payload]);
    }

    /* ---------------- purchase order helpers ---------------- */

    protected function approvedRequest(?Project $for = null): MaterialRequest
    {
        $project = $for ?? $this->project;

        return MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->admin->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Create a PO and return its id.
     *
     * Also gives the project a primary delivery address, because issue() now
     * refuses a PO with no ship-to — several of these tests issue, and without
     * it they would fail on an unrelated guard.
     */
    protected function createPurchaseOrder(?Project $for = null): int
    {
        $project = $for ?? $this->project;

        if (! $project->deliveryAddresses()->exists()) {
            ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $project->id]);
        }

        $response = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->approvedRequest($project)->id,
            'vendor_id' => Vendor::create(['name' => fake()->company()])->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 5,
                'unit_price' => 12.50,
            ]],
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    protected function issuePurchaseOrder(int $poId): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue");
    }

    protected function showPurchaseOrder(int $poId): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')->getJson("/api/v1/purchase-orders/{$poId}");
    }
}
