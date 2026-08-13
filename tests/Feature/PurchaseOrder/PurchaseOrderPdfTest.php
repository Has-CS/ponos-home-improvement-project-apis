<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\Attachment;
use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderTerm;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseOrder\PurchaseOrderPdfService;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Generation of the purchase-order document.
 *
 * Two levels of assertion, on purpose:
 *  - the rendered HTML, where content can be checked precisely (which fields
 *    appear, which are absent, how the snapshots print);
 *  - the real PDF bytes, which prove dompdf actually produces a document and
 *    that it is filed correctly at issue.
 */
class PurchaseOrderPdfTest extends TestCase
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

        $this->procurement = $this->userWithRole('Procurement');
        $this->project = Project::factory()->create(['code' => 'PNS-2026-014', 'name' => 'Harrington Residence']);
        $this->vendor = Vendor::create([
            'name' => 'Midwest Building Supply Co.',
            'contact_name' => 'Dana Whitfield',
            'email' => 'orders@midwestbuildingsupply.com',
            'phone' => '(630) 555-0199',
            'address' => "2255 Industrial Parkway\nAurora, IL 60502",
        ]);
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

    private function address(): ProjectDeliveryAddress
    {
        return ProjectDeliveryAddress::factory()->harrington()->primary()
            ->create(['project_id' => $this->project->id]);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function createPo(array $items = [], array $payload = []): PurchaseOrder
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);

        $response = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => $items ?: [[
                'catalog_item_id' => CatalogItem::factory()->create(['name' => 'Interior Door Slab'])->id,
                'quantity_ordered' => 14,
                'unit_price' => 138.50,
            ]],
            ...$payload,
        ]);

        $response->assertStatus(201);

        return PurchaseOrder::findOrFail($response->json('data.id'));
    }

    private function issue(PurchaseOrder $po): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$po->id}/issue");
    }

    private function html(PurchaseOrder $po): string
    {
        return view('pdf.purchase-order', [
            'po' => $po->fresh(['vendor', 'project', 'status', 'issuedBy', 'materialRequest',
                'items.unit', 'items.catalogItem', 'items.costCode']),
            'company' => config('company'),
        ])->render();
    }

    /* ---------------- rendering ---------------- */

    public function test_the_endpoint_returns_a_real_pdf(): void
    {
        $this->address();
        $po = $this->createPo();

        $response = $this->actingAs($this->procurement, 'api')
            ->get("/api/v1/purchase-orders/{$po->id}/pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_draft_is_watermarked(): void
    {
        $this->address();

        $this->assertStringContainsString('watermark', $this->html($this->createPo()));
    }

    public function test_an_issued_order_is_not_watermarked(): void
    {
        $this->address();
        $po = $this->createPo();
        $this->issue($po)->assertOk();

        $this->assertStringNotContainsString('class="watermark"', $this->html($po->refresh()));
    }

    /* ---------------- field binding ---------------- */

    public function test_the_document_carries_the_po_vendor_and_project_data(): void
    {
        $this->address();
        $po = $this->createPo();

        $html = $this->html($po);

        $this->assertStringContainsString($po->po_number, $html);
        $this->assertStringContainsString('Midwest Building Supply Co.', $html);
        $this->assertStringContainsString('Dana Whitfield', $html);
        $this->assertStringContainsString('PNS-2026-014', $html);
        $this->assertStringContainsString($po->materialRequest->request_no, $html);
        $this->assertStringContainsString('Interior Door Slab', $html);
        $this->assertStringContainsString('138.50', $html);
        $this->assertStringContainsString('1,939.00', $html); // 14 x 138.50
    }

    /** The ship-to block must come from the frozen snapshot, not the live row. */
    public function test_the_ship_to_block_prints_the_snapshot(): void
    {
        $address = $this->address();
        $po = $this->createPo();
        $this->issue($po)->assertOk();

        $address->update(['street_1' => 'RELOCATED ELSEWHERE']);
        $address->delete();

        $html = $this->html($po->refresh());

        $this->assertStringContainsString('88 Ridgeview Court', $html);
        $this->assertStringNotContainsString('RELOCATED ELSEWHERE', $html);
    }

    public function test_terms_print_from_the_pos_frozen_copy(): void
    {
        $this->address();
        $terms = PurchaseOrderTerm::create([
            'project_id' => null,
            'title' => 'Terms & Conditions',
            'body' => "First clause of the order.\nSecond clause of the order.",
        ]);

        $po = $this->createPo();
        $this->issue($po)->assertOk();

        $terms->update(['body' => 'COMPLETELY REWRITTEN']);

        $html = $this->html($po->refresh());

        $this->assertStringContainsString('First clause of the order.', $html);
        $this->assertStringContainsString('Second clause of the order.', $html);
        $this->assertStringNotContainsString('COMPLETELY REWRITTEN', $html);
    }

    /** Tax was explicitly excluded, and there is no column for it or its siblings. */
    public function test_no_tax_discount_or_freight_appears(): void
    {
        $this->address();

        $html = $this->html($this->createPo());

        foreach (['Sales tax', 'Discount', 'Freight'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Total', $html);
    }

    /* ---------------- edge cases ---------------- */

    public function test_optional_fields_collapse_cleanly(): void
    {
        // No delivery address, no terms, no notes, no cost codes, a bare vendor.
        $this->vendor->update(['contact_name' => null, 'email' => null, 'phone' => null, 'address' => null]);

        $po = $this->createPo();

        $html = $this->html($po);

        $this->assertStringContainsString($po->po_number, $html);
        $this->assertStringNotContainsString('Terms & conditions', $html);
        $this->assertStringNotContainsString('Notes to vendor', $html);

        // And it must still produce a document.
        $this->assertStringStartsWith('%PDF', app(PurchaseOrderPdfService::class)->render($po));
    }

    public function test_a_long_order_spans_multiple_pages(): void
    {
        $this->address();

        $items = [];
        foreach (range(1, 40) as $i) {
            $items[] = [
                'catalog_item_id' => CatalogItem::factory()->create([
                    'name' => "Line item {$i} with a deliberately long name for wrapping",
                ])->id,
                'quantity_ordered' => 3,
                'unit_price' => 25.00,
                'description' => str_repeat('Long description text that must wrap across the column. ', 3),
            ];
        }

        $pdf = app(PurchaseOrderPdfService::class)->render($this->createPo($items));

        // Count page objects. NOT /Count, which also appears on the outline
        // object as "/Count 0" and matches first.
        $pages = preg_match_all('~/Type\s*/Page(?![s])~', $pdf);

        $this->assertGreaterThan(1, $pages, "Expected the order to span more than one page, got {$pages}.");
    }

    public function test_a_po_with_no_line_items_still_renders(): void
    {
        $po = $this->createPo();
        $po->items()->delete();

        $this->assertStringContainsString('No line items on this order.', $this->html($po->refresh()));
    }

    /* ---------------- storage at issue ---------------- */

    public function test_issuing_files_the_document_as_an_attachment(): void
    {
        $this->address();
        $po = $this->createPo();

        $this->assertNull(app(PurchaseOrderPdfService::class)->storedDocument($po));

        $this->issue($po)->assertOk();

        $doc = app(PurchaseOrderPdfService::class)->storedDocument($po->refresh());

        $this->assertNotNull($doc);
        $this->assertSame('document', $doc->attachment_type);
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame(PurchaseOrder::class, $doc->attachable_type);
        $this->assertSame($this->project->id, (int) $doc->project_id);
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF', Storage::disk($doc->disk)->get($doc->file_path));
    }

    /** After issue the endpoint must serve the stored bytes, not re-render. */
    public function test_the_endpoint_serves_the_stored_copy_once_issued(): void
    {
        $this->address();
        $po = $this->createPo();
        $this->issue($po)->assertOk();

        $doc = app(PurchaseOrderPdfService::class)->storedDocument($po->refresh());
        $stored = Storage::disk($doc->disk)->get($doc->file_path);

        $response = $this->actingAs($this->procurement, 'api')
            ->get("/api/v1/purchase-orders/{$po->id}/pdf");

        $this->assertSame($stored, $response->getContent());
    }

    /**
     * The buyer who issued the order is not staffed onto its project, so the
     * attachment download must be widened for them or they cannot retrieve
     * their own PO document.
     */
    public function test_procurement_can_download_the_document_without_project_membership(): void
    {
        $this->address();
        $po = $this->createPo();
        $this->issue($po)->assertOk();

        $this->assertDatabaseMissing('project_user', [
            'user_id' => $this->procurement->id,
            'project_id' => $this->project->id,
        ]);

        $doc = Attachment::where('attachable_id', $po->id)->where('attachment_type', 'document')->firstOrFail();

        $this->actingAs($this->procurement, 'api')
            ->get("/api/v1/attachments/{$doc->id}")
            ->assertOk();
    }

    public function test_a_user_without_purchasing_rights_cannot_read_the_pdf(): void
    {
        $this->address();
        $po = $this->createPo();

        $this->actingAs($this->userWithRole('Foreman'), 'api')
            ->get("/api/v1/purchase-orders/{$po->id}/pdf")
            ->assertStatus(403);
    }
}
