<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\Unit;
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
 * A PO line may declare which requested line it fulfils — but only a line of the
 * request the PO is cut from. Before this, any material_request_item_id that
 * merely existed was accepted and stored, so an id from another request (another
 * project, even) silently attributed ordered quantities to the wrong request.
 *
 * Two layers are covered here: the per-line 422 from StorePurchaseOrderRequest,
 * and the invariant in PurchaseOrderService::create() behind it.
 */
class PurchaseOrderLineLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $procurement;

    private Project $projectA;

    private Project $projectB;

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

        $this->projectA = Project::factory()->create(['code' => 'PNS-2026-301']);
        $this->projectB = Project::factory()->create(['code' => 'PNS-2026-302']);

        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectA->id]);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->projectB->id]);

        $this->vendor = Vendor::create([
            'name' => 'Line Link Test Supply Co.',
            'email' => 'orders@linelinktest.com',
        ]);
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

    private function mrLine(MaterialRequest $mr): MaterialRequestItem
    {
        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'quantity' => 5,
        ]);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function createPo(MaterialRequest $mr, array $items): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            // Explicit unit_price throughout, so no vendor rate is needed and a
            // pricing 422 can never be mistaken for a link 422.
            'items' => $items,
        ]);
    }

    /** @param array<string,mixed> $extra */
    /**
     * A line with its own fresh catalog item — the shape used for UNLINKED lines
     * (a prose request). A linked line must not name its own catalog item: the
     * service derives that from the request line it fulfils, so `linkedLine()`
     * below omits it.
     */
    private function line(array $extra = []): array
    {
        return [
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'quantity_ordered' => 3,
            'unit_price' => 50,
            ...$extra,
        ];
    }

    /** A line fulfilling a request line: only the link and the quantity. */
    private function linkedLine(MaterialRequestItem $requested, array $extra = []): array
    {
        return [
            'material_request_item_id' => $requested->id,
            'quantity_ordered' => 3,
            'unit_price' => 50,
            ...$extra,
        ];
    }

    /* ---------------- the bug ---------------- */

    public function test_line_cannot_reference_another_requests_line(): void
    {
        $ownMr = $this->approvedMr($this->projectA);
        $foreignLine = $this->mrLine($this->approvedMr($this->projectB));

        $this->createPo($ownMr, [$this->line(['material_request_item_id' => $foreignLine->id])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.material_request_item_id']);

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    public function test_line_may_reference_its_own_requests_line(): void
    {
        $mr = $this->approvedMr($this->projectA);
        $ownLine = $this->mrLine($mr);

        // Only the link and the quantity: the catalog item and unit are derived
        // from the request line, which is the point of the link.
        $poId = $this->createPo($mr, [$this->linkedLine($ownLine)])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'material_request_item_id' => $ownLine->id,
            'catalog_item_id' => $ownLine->catalog_item_id,
            'unit_id' => $ownLine->unit_id,
        ]);
    }

    public function test_lines_without_a_link_are_unaffected(): void
    {
        $mr = $this->approvedMr($this->projectA);

        // Omitted entirely, and explicitly null: both are the prose-mapped path,
        // where no requested line exists to point at.
        $this->createPo($mr, [$this->line(), $this->line(['material_request_item_id' => null])])
            ->assertStatus(201);

        $this->assertDatabaseCount('purchase_order_items', 2);
    }

    public function test_soft_deleted_line_of_the_same_request_is_rejected(): void
    {
        $mr = $this->approvedMr($this->projectA);
        $line = $this->mrLine($mr);
        $line->delete();

        $this->createPo($mr, [$this->line(['material_request_item_id' => $line->id])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.material_request_item_id']);
    }

    public function test_only_the_offending_line_is_reported_and_nothing_is_written(): void
    {
        $mr = $this->approvedMr($this->projectA);
        $ownLine = $this->mrLine($mr);
        $foreignLine = $this->mrLine($this->approvedMr($this->projectB));

        // The third line splits the same requested line as the first — legitimate,
        // and quantities stay inside the requested 5 so the over-order rule is not
        // what fires here. Every line is linked, as the request has line items.
        $this->createPo($mr, [
            $this->linkedLine($ownLine, ['quantity_ordered' => 2]),
            $this->line(['material_request_item_id' => $foreignLine->id]),
            $this->linkedLine($ownLine, ['quantity_ordered' => 2]),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.material_request_item_id'])
            ->assertJsonMissingValidationErrors([
                'items.0.material_request_item_id',
                'items.2.material_request_item_id',
                'items.0.quantity_ordered',
                'items.2.quantity_ordered',
            ]);

        // The whole submission is refused — no half-written order.
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    /* ---------------- layer 2: the service's own invariant ---------------- */

    public function test_service_rejects_a_cross_request_link_on_its_own(): void
    {
        // What the team.global middleware does on a real request. Without it the
        // role lookup resolves under no team and the call dies on a 403 instead,
        // which would make this test pass for the wrong reason.
        setPermissionsTeamId(0);

        $mr = $this->approvedMr($this->projectA);
        $foreignLine = $this->mrLine($this->approvedMr($this->projectB));

        // Straight past the form request, the way a future non-HTTP caller would.
        try {
            app(PurchaseOrderService::class)->create([
                'material_request_id' => $mr->id,
                'vendor_id' => $this->vendor->id,
                'items' => [[
                    'catalog_item_id' => CatalogItem::factory()->create()->id,
                    'quantity_ordered' => 3,
                    'unit_price' => 50,
                    'material_request_item_id' => $foreignLine->id,
                ]],
            ], $this->procurement);

            $this->fail('Expected the service to reject a cross-request line link.');
        } catch (HttpException $e) {
            // Asserting the STATUS, not just the class: a 403 from an unrelated
            // access check is also an HttpException.
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('does not belong to this request', $e->getMessage());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
