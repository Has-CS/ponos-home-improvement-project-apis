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
 * You cannot order more than was requested.
 *
 * The crux is that one request legitimately produces MANY purchase orders (one
 * per vendor), so the limit is the CUMULATIVE quantity across all of them, not
 * any single PO's own. Requested 20 + PO#1 for 15 leaves 5, and a PO#2 for 10
 * must fail.
 */
class PurchaseOrderOverOrderTest extends TestCase
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

        $this->project = Project::factory()->create(['code' => 'PNS-2026-601']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create([
            'name' => 'Over Order Test Supply Co.',
            'email' => 'orders@overordertest.com',
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

    private function mrLine(MaterialRequest $mr, float|string $quantity, ?string $description = null): MaterialRequestItem
    {
        return MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'quantity' => $quantity,
            'description' => $description,
        ]);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function createPo(MaterialRequest $mr, array $items): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => $items,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function line(MaterialRequestItem $mrLine, float|string $quantity, array $extra = []): array
    {
        return [
            'catalog_item_id' => $mrLine->catalog_item_id,
            'material_request_item_id' => $mrLine->id,
            'quantity_ordered' => $quantity,
            'unit_price' => 10,
            ...$extra,
        ];
    }

    /* ---------------- the boundary ---------------- */

    public function test_ordering_exactly_the_requested_quantity_is_allowed(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        $this->createPo($mr, [$this->line($line, 20)])->assertStatus(201);
    }

    public function test_ordering_more_than_requested_is_rejected(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20, '2x4x8 pressure-treated');

        $response = $this->createPo($mr, [$this->line($line, 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity_ordered']);

        // The error has to be actionable on its own: what was asked for, what is
        // already on order, and what is left.
        $message = $response->json('errors.items\\.0\\.quantity_ordered.0')
            ?? $response->json('errors')['items.0.quantity_ordered'][0];

        // Named by the catalog item, which is what the buyer picked and what the
        // vendor will recognise — not the row id, and not the requester's own
        // note, which is only used when the line names no catalog item.
        $this->assertStringContainsString($line->catalogItem->name, $message);
        $this->assertStringNotContainsString('#'.$line->id, $message);

        // Quantities read as quantities: "20", never "20.000".
        $this->assertStringContainsString('requested 20,', $message);
        $this->assertStringContainsString('already ordered 0', $message);
        $this->assertStringContainsString('remaining 20', $message);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /* ---------------- cumulative across POs: the user's example ---------------- */

    public function test_the_limit_is_cumulative_across_every_po_for_the_request(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        // PO#1 takes 15 of 20.
        $this->createPo($mr, [$this->line($line, 15)])->assertStatus(201);

        // PO#2 for 10 must fail — only 5 are left.
        $response = $this->createPo($mr, [$this->line($line, 10)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity_ordered']);

        $message = $response->json('errors')['items.0.quantity_ordered'][0];
        $this->assertStringContainsString('already ordered 15', $message);
        $this->assertStringContainsString('remaining 5', $message);

        // PO#2 for exactly the remaining 5 goes through.
        $this->createPo($mr, [$this->line($line, 5)])->assertStatus(201);

        // And nothing more after that.
        $this->createPo($mr, [$this->line($line, 1)])->assertStatus(422);
    }

    public function test_several_lines_in_one_payload_are_summed_together(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        // 15 + 15 = 30 against a request for 20. Each line passes alone; the
        // total must not. Both contributing lines are flagged.
        $this->createPo($mr, [$this->line($line, 15), $this->line($line, 15)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity_ordered', 'items.1.quantity_ordered']);

        $this->assertDatabaseCount('purchase_orders', 0);

        // Split within the limit is fine — one requested line, two PO lines.
        $this->createPo($mr, [$this->line($line, 12), $this->line($line, 8)])->assertStatus(201);
    }

    public function test_each_requested_line_has_its_own_allowance(): void
    {
        $mr = $this->approvedMr();
        $cement = $this->mrLine($mr, 20, 'Cement');
        $rebar = $this->mrLine($mr, 4, 'Rebar');

        // Cement is fine, rebar is over: only the rebar line is reported.
        $this->createPo($mr, [$this->line($cement, 20), $this->line($rebar, 5)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.quantity_ordered'])
            ->assertJsonMissingValidationErrors(['items.0.quantity_ordered']);
    }

    /* ---------------- what releases the allowance ---------------- */

    public function test_a_draft_po_consumes_the_allowance(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        // Left as a draft, never issued.
        $this->createPo($mr, [$this->line($line, 15)])->assertStatus(201);

        $this->createPo($mr, [$this->line($line, 10)])->assertStatus(422);
    }

    public function test_cancelling_a_po_releases_its_quantity(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        $poId = $this->createPo($mr, [$this->line($line, 15)])->assertStatus(201)->json('data.id');

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/cancel")
            ->assertOk();

        // The full 20 is available again.
        $this->createPo($mr, [$this->line($line, 20)])->assertStatus(201);
    }

    public function test_deleting_a_draft_po_releases_its_quantity(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        $poId = $this->createPo($mr, [$this->line($line, 15)])->assertStatus(201)->json('data.id');

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$poId}")
            ->assertOk();

        $this->createPo($mr, [$this->line($line, 20)])->assertStatus(201);
    }

    /* ---------------- the link that makes the check possible ---------------- */

    public function test_an_unlinked_line_is_rejected_when_the_request_has_lines(): void
    {
        $mr = $this->approvedMr();
        $this->mrLine($mr, 20);

        $this->createPo($mr, [[
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'quantity_ordered' => 500,
            'unit_price' => 10,
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.material_request_item_id']);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /** A prose-only request has no requested quantity to measure against. */
    public function test_a_prose_only_request_still_takes_unlinked_lines(): void
    {
        $mr = $this->approvedMr();
        $mr->update(['request_text' => 'Need 20 steel nuts and whatever sealant the plumber asked for']);

        $this->createPo($mr, [[
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'quantity_ordered' => 500,
            'unit_price' => 10,
        ]])->assertStatus(201);
    }

    /* ---------------- fractional quantities ---------------- */

    public function test_fractional_quantities_are_compared_exactly(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, '7.25');

        $this->createPo($mr, [$this->line($line, '2.5')])->assertStatus(201);

        // 2.5 + 4.75 = 7.25 exactly.
        $this->createPo($mr, [$this->line($line, '4.75')])->assertStatus(201);

        // A thousandth over is over — the column's own precision, no epsilon.
        $this->createPo($mr, [$this->line($line, '0.001')])->assertStatus(422);
    }

    public function test_a_thousandth_over_the_request_is_rejected(): void
    {
        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, '7.25');

        $this->createPo($mr, [$this->line($line, '7.251')])->assertStatus(422);
    }

    /* ---------------- layer 2: the service's own invariant ---------------- */

    public function test_the_service_rejects_over_ordering_on_its_own(): void
    {
        // What the team.global middleware does on a real request; without it the
        // call dies on a 403 and this would pass for the wrong reason.
        setPermissionsTeamId(0);

        $mr = $this->approvedMr();
        $line = $this->mrLine($mr, 20);

        try {
            app(PurchaseOrderService::class)->create([
                'material_request_id' => $mr->id,
                'vendor_id' => $this->vendor->id,
                'items' => [[
                    'catalog_item_id' => $line->catalog_item_id,
                    'material_request_item_id' => $line->id,
                    'quantity_ordered' => 21,
                    'unit_price' => 10,
                ]],
            ], $this->procurement);

            $this->fail('Expected the service to reject an over-order.');
        } catch (HttpException $e) {
            // The STATUS, not just the class: a 403 is also an HttpException.
            $this->assertSame(422, $e->getStatusCode());
            // The same sentence a buyer sees from the create path, naming the
            // item rather than a row id.
            $this->assertStringContainsString('exceeds what was requested', $e->getMessage());
            $this->assertStringContainsString($line->catalogItem->name, $e->getMessage());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_the_service_requires_the_link_when_the_request_has_lines(): void
    {
        setPermissionsTeamId(0);

        $mr = $this->approvedMr();
        $this->mrLine($mr, 20);

        try {
            app(PurchaseOrderService::class)->create([
                'material_request_id' => $mr->id,
                'vendor_id' => $this->vendor->id,
                'items' => [[
                    'catalog_item_id' => CatalogItem::factory()->create()->id,
                    'quantity_ordered' => 500,
                    'unit_price' => 10,
                ]],
            ], $this->procurement);

            $this->fail('Expected the service to require a material request line link.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('must name the requested line', $e->getMessage());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
