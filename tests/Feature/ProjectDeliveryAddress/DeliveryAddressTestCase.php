<?php

namespace Tests\Feature\ProjectDeliveryAddress;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
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
 * Shared setup for project delivery address + PO ship-to feature tests.
 *
 * Three actors, because the gating differs per route:
 *   $pm          holds edit_project — the address CRUD writes.
 *   $procurement holds manage_purchase_orders and is deliberately NOT staffed
 *                onto the project, which is the case the read widening exists
 *                for. Also the actor that cuts POs.
 *   $foreman     holds neither, for the negative write case.
 */
abstract class DeliveryAddressTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $pm;

    protected User $procurement;

    protected User $foreman;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->project = Project::factory()->create();

        $this->pm = User::factory()->create();
        $this->procurement = User::factory()->create();
        $this->foreman = User::factory()->create();

        $rbac = app(RoleAssignmentService::class);
        $rbac->assignGlobalRole($this->pm, $this->role('Project Manager'));
        $rbac->assignGlobalRole($this->procurement, $this->role('Procurement'));
        $rbac->assignGlobalRole($this->foreman, $this->role('Foreman'));

        // The PM is staffed onto the project; Procurement deliberately is not.
        $rbac->assignProjectRole($this->project, $this->pm, $this->role('Project Manager'), null);
    }

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /* ---------------- address helpers ---------------- */

    /** @param array<string,mixed> $payload */
    protected function postAddress(array $payload = [], ?User $as = null, ?Project $to = null): TestResponse
    {
        $project = $to ?? $this->project;

        return $this->actingAs($as ?? $this->pm, 'api')->postJson(
            "/api/v1/projects/{$project->id}/delivery-addresses",
            [
                'label' => 'North Site',
                'street_1' => '88 Ridgeview Court',
                'city' => 'Wheaton',
                'state' => 'IL',
                'postal_code' => '60187',
                ...$payload,
            ],
        );
    }

    /** Create an address straight through the factory, bypassing the API. */
    protected function makeAddress(array $attributes = [], ?Project $for = null): ProjectDeliveryAddress
    {
        return ProjectDeliveryAddress::factory()->create([
            'project_id' => ($for ?? $this->project)->id,
            ...$attributes,
        ]);
    }

    /* ---------------- purchase order helpers ---------------- */

    /**
     * An approved material request on the given project — the precondition for
     * cutting a PO (StorePurchaseOrderRequest rejects anything else).
     */
    protected function approvedRequest(?Project $for = null): MaterialRequest
    {
        $project = $for ?? $this->project;

        return MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->foreman->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->foreman->id,
        ]);
    }

    /**
     * POST a purchase order. unit_price is passed explicitly so the test needs
     * no vendor rate on file — the manual-override branch of persistLine().
     *
     * @param  array<string,mixed>  $payload
     */
    protected function postPurchaseOrder(array $payload = [], ?Project $for = null): TestResponse
    {
        $project = $for ?? $this->project;

        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->approvedRequest($project)->id,
            'vendor_id' => Vendor::create(['name' => fake()->company()])->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 5,
                'unit_price' => 12.50,
            ]],
            ...$payload,
        ]);
    }

    /** Create a PO and return its id. */
    protected function createPurchaseOrder(array $payload = [], ?Project $for = null): int
    {
        $response = $this->postPurchaseOrder($payload, $for);
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
