<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\Attachment;
use App\Models\CatalogItem;
use App\Models\ChangeOrder;
use App\Models\MaterialRequest;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Free-text ("WhatsApp-style") material requests.
 *
 * A foreman who can't work the catalog pickers describes what they need in
 * prose and optionally photographs it. Nobody is forced to convert that into
 * line items: the PM MAY do so while reviewing, but the request can equally be
 * approved as prose and mapped by Procurement when they cut the PO.
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class FreeTextMaterialRequestTest extends MaterialRequestLineTestCase
{
    /** A valid 1x1 transparent PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Photos are written to the private `local` disk by AttachmentService.
        Storage::fake('local');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** @param array<string,mixed> $payload */
    private function createRaw(array $payload): TestResponse
    {
        return $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            ['urgency_id' => Urgency::where('code', 'normal')->value('id'), ...$payload],
        );
    }

    private function submit(int $mrId, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit");
    }

    private function approve(int $mrId, User $as): TestResponse
    {
        return $this->actingAs($as, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve");
    }

    /** Walk a request all the way to `approved`, structuring nothing. */
    private function approveThrough(int $mrId): void
    {
        $this->submit($mrId)->assertStatus(200);
        $this->approve($mrId, $this->userWithRole('Project Manager'))->assertStatus(200);
        $this->approve($mrId, $this->userWithRole('Admin'))->assertStatus(200);
    }

    /* ---------------- creation ---------------- */

    public function test_prose_only_request_is_created(): void
    {
        $response = $this->createRaw(['request_text' => 'Need 20 steel nuts and a roll of 2.5mm wire for the first floor'])
            ->assertStatus(201)
            ->assertJsonPath('data.request_text', 'Need 20 steel nuts and a roll of 2.5mm wire for the first floor')
            ->assertJsonPath('data.needs_structuring', true);

        $this->assertDatabaseHas('material_requests', [
            'id' => $response->json('data.id'),
            'request_text' => 'Need 20 steel nuts and a roll of 2.5mm wire for the first floor',
            'structured_by' => null,
            'structured_at' => null,
        ]);
    }

    public function test_photos_are_stored_as_material_request_attachments(): void
    {
        $mrId = $this->createRaw([
            'request_text' => 'This nut — need 20 of them',
            'photos' => [self::PNG, self::PNG],
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseCount('attachments', 2);
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => MaterialRequest::class,
            'attachable_id' => $mrId,
            'project_id' => $this->project->id,
            'attachment_type' => 'photo',
            'mime_type' => 'image/png',
        ]);

        $photos = Attachment::where('attachable_id', $mrId)->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo->file_path);
        }
    }

    public function test_request_may_carry_both_prose_and_structured_lines(): void
    {
        $item = CatalogItem::factory()->create();

        $mrId = $this->createRaw([
            'request_text' => 'Also grab whatever sealant the plumber asked for',
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 4]],
        ])->assertStatus(201)
            // Prose plus lines is not "awaiting structuring" — someone already did some.
            ->assertJsonPath('data.needs_structuring', false)
            ->json('data.id');

        $this->assertDatabaseHas('material_requests', ['id' => $mrId, 'request_text' => 'Also grab whatever sealant the plumber asked for']);
        $this->assertDatabaseCount('material_request_items', 1);
    }

    /* ---------------- photos: multipart file uploads ---------------- */

    public function test_photo_can_be_sent_as_an_uploaded_file(): void
    {
        // The same `photos[]` field also takes a real multipart upload, so a
        // browser file picker (or Postman) doesn't have to base64-encode first.
        $response = $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'request_text' => 'This nut — need 20 of them',
                'photos' => [UploadedFile::fake()->createWithContent('nut.jpg', 'binary-jpeg-bytes')],
            ],
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => MaterialRequest::class,
            'attachable_id' => $response->json('data.id'),
            'attachment_type' => 'photo',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('local')->assertExists(
            Attachment::where('attachable_id', $response->json('data.id'))->value('file_path')
        );
    }

    public function test_uploaded_files_and_base64_can_be_mixed_in_one_request(): void
    {
        $response = $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'request_text' => 'Two things',
                'photos' => [
                    UploadedFile::fake()->createWithContent('nut.jpg', 'binary-jpeg-bytes'),
                    self::PNG,
                ],
            ],
        );

        $response->assertStatus(201);

        $mimes = Attachment::where('attachable_id', $response->json('data.id'))
            ->pluck('mime_type')->sort()->values()->all();

        $this->assertSame(['image/jpeg', 'image/png'], $mimes);
    }

    public function test_uploaded_file_of_the_wrong_type_is_rejected(): void
    {
        $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'request_text' => 'A PDF, not a photo',
                'photos' => [UploadedFile::fake()->create('spec.pdf', 40, 'application/pdf')],
            ],
        )->assertStatus(422)->assertJsonValidationErrors(['photos.0']);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_oversized_uploaded_file_is_rejected(): void
    {
        $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'request_text' => 'Huge photo',
                'photos' => [UploadedFile::fake()->create('huge.jpg', 11 * 1024, 'image/jpeg')],
            ],
        )->assertStatus(422)->assertJsonValidationErrors(['photos.0']);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_more_than_ten_photos_is_rejected(): void
    {
        $this->createRaw([
            'request_text' => 'Lots of things',
            'photos' => array_fill(0, 11, self::PNG),
        ])->assertStatus(422)->assertJsonValidationErrors(['photos']);
    }

    public function test_non_image_photo_is_rejected(): void
    {
        $this->createRaw([
            'request_text' => 'Here is a PDF pretending to be a photo',
            'photos' => ['data:application/pdf;base64,JVBERi0xLjQK'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('attachments', 0);
    }

    /* ---------------- submit gate ---------------- */

    public function test_prose_only_request_can_be_submitted(): void
    {
        // Before this change submit() aborted with "Cannot submit a material
        // request with no line items" — the blocker this feature had to remove.
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');

        $this->submit($mrId)->assertStatus(200)->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_lines_only_request_still_submits(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createRaw(['items' => [['catalog_item_id' => $item->id, 'quantity' => 1]]])->json('data.id');

        $this->submit($mrId)->assertStatus(200);
    }

    public function test_request_with_neither_prose_nor_lines_cannot_be_submitted(): void
    {
        $mrId = $this->createRaw([])->json('data.id');

        $this->submit($mrId)->assertStatus(422);
    }

    /* ---------------- the core change: approve prose with zero lines ---------------- */

    public function test_prose_only_request_reaches_approved_with_no_line_items(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');

        $this->approveThrough($mrId);

        $mr = MaterialRequest::findOrFail($mrId);
        $this->assertSame('approved', $mr->status->code);
        $this->assertSame(0, $mr->items()->count());
    }

    public function test_purchase_order_can_be_cut_from_a_prose_only_request(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');
        $this->approveThrough($mrId);

        $vendor = Vendor::create(['name' => 'Acme Supply', 'is_active' => true]);
        $item = CatalogItem::factory()->create(['name' => 'Steel nut M8']);
        $procurement = $this->userWithRole('Procurement');

        // Procurement maps the prose to a catalog line here, at the PO.
        $this->actingAs($procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mrId,
            'vendor_id' => $vendor->id,
            'items' => [[
                'catalog_item_id' => $item->id,
                'quantity_ordered' => 20,
                'unit_price' => 1.25,
            ]],
        ])->assertStatus(201);

        $this->assertSame('ordered', MaterialRequest::findOrFail($mrId)->fresh()->status->code);
    }

    /* ---------------- optional structuring by the reviewer ---------------- */

    public function test_pm_may_add_lines_while_the_request_awaits_their_review(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');
        $this->submit($mrId)->assertStatus(200);

        $pm = $this->userWithRole('Project Manager');

        // 403 before this change — pending_pm was not an editable status.
        $this->actingAs($pm, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            ['catalog_item_id' => $item->id, 'quantity' => 20],
        )->assertStatus(201);

        $mr = MaterialRequest::findOrFail($mrId);
        $this->assertSame($pm->id, $mr->structured_by);
        $this->assertNotNull($mr->structured_at);

        $this->assertDatabaseHas('material_request_approvals', [
            'material_request_id' => $mrId,
            'approver_id' => $pm->id,
            'action' => 'edit',
        ]);
    }

    public function test_foreman_cannot_add_lines_once_the_request_is_under_review(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');
        $this->submit($mrId)->assertStatus(200);

        $this->addLine($mrId, ['catalog_item_id' => $item->id, 'quantity' => 20])->assertStatus(403);
    }

    public function test_admin_may_add_lines_while_the_request_awaits_admin_review(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');
        $this->submit($mrId)->assertStatus(200);
        $this->approve($mrId, $this->userWithRole('Project Manager'))->assertStatus(200);

        $admin = $this->userWithRole('Admin');
        $this->actingAs($admin, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            ['catalog_item_id' => $item->id, 'quantity' => 20],
        )->assertStatus(201);

        $this->assertSame($admin->id, MaterialRequest::findOrFail($mrId)->structured_by);
    }

    public function test_structuring_is_not_recorded_for_a_request_that_had_no_prose(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createRaw([])->json('data.id');

        $this->addLine($mrId, ['catalog_item_id' => $item->id, 'quantity' => 1])->assertStatus(201);

        $mr = MaterialRequest::findOrFail($mrId);
        $this->assertNull($mr->structured_by);
        $this->assertNull($mr->structured_at);
    }

    /* ---------------- the raw text is frozen on submit ---------------- */

    public function test_foreman_may_edit_the_text_while_the_request_is_a_draft(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need nuts'])->json('data.id');

        $this->actingAs($this->foreman, 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}",
            ['request_text' => 'Need 20 steel nuts, M8'],
        )->assertStatus(200)->assertJsonPath('data.request_text', 'Need 20 steel nuts, M8');
    }

    public function test_text_cannot_be_rewritten_once_the_request_is_under_review(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need nuts'])->json('data.id');
        $this->submit($mrId)->assertStatus(200);

        // At pending_pm the header is not editable at all, so this is the
        // existing 409 rather than the frozen-text 422 — either way the original
        // wording survives.
        $this->actingAs($this->userWithRole('Project Manager'), 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}",
            ['request_text' => 'PM rewording'],
        )->assertStatus(409);

        $this->assertSame('Need nuts', MaterialRequest::findOrFail($mrId)->request_text);
    }

    public function test_text_is_frozen_even_in_the_send_back_to_pm_window(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need nuts'])->json('data.id');
        $this->submit($mrId)->assertStatus(200);
        $this->approve($mrId, $this->userWithRole('Project Manager'))->assertStatus(200);

        // Admin sends it back: status becomes sent_back_to_pm, which IS header-
        // editable — so this is the case the dedicated 422 guard exists for.
        $this->actingAs($this->userWithRole('Admin'), 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/send-back",
            ['comments' => 'Clarify quantities'],
        )->assertStatus(200);

        $pm = $this->userWithRole('Project Manager');

        $this->actingAs($pm, 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}",
            ['request_text' => 'PM rewording'],
        )->assertStatus(422);

        // Other header fields are still editable in that window.
        $this->actingAs($pm, 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}",
            ['notes' => 'Chased the foreman by phone'],
        )->assertStatus(200);

        $this->assertSame('Need nuts', MaterialRequest::findOrFail($mrId)->request_text);
    }

    /* ---------------- procurement reaches the request without project membership ---------------- */

    public function test_procurement_sees_pending_requests_without_project_membership(): void
    {
        $mrId = $this->createRaw([
            'request_text' => 'Need 20 steel nuts',
            'photos' => [self::PNG],
        ])->json('data.id');
        $this->approveThrough($mrId);

        $procurement = $this->userWithRole('Procurement');

        // Deliberately NOT assigned to the project — MR reads are membership-
        // gated, which is exactly why this queue lives on the PO side.
        $this->actingAs($procurement, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertStatus(403);

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $mrId)
            ->assertJsonPath('data.items.0.request_text', 'Need 20 steel nuts')
            ->assertJsonPath('data.items.0.needs_structuring', true)
            ->assertJsonCount(1, 'data.items.0.photos');
    }

    public function test_procurement_can_download_a_material_request_photo(): void
    {
        $mrId = $this->createRaw(['request_text' => 'This nut', 'photos' => [self::PNG]])->json('data.id');
        $photo = Attachment::where('attachable_id', $mrId)->firstOrFail();

        $this->actingAs($this->userWithRole('Procurement'), 'api')
            ->get("/api/v1/attachments/{$photo->id}")
            ->assertStatus(200);
    }

    public function test_the_download_widening_does_not_expose_other_attachment_types(): void
    {
        // Same non-member Procurement user, an attachment of a different type on
        // a different parent — must stay membership-gated.
        $signature = Attachment::create([
            'attachable_type' => ChangeOrder::class,
            'attachable_id' => 1,
            'project_id' => $this->project->id,
            'attachment_type' => 'signature',
            'disk' => 'local',
            'file_path' => 'change-order-signatures/x.png',
            'file_name' => 'x.png',
            'mime_type' => 'image/png',
        ]);

        $this->actingAs($this->userWithRole('Procurement'), 'api')
            ->get("/api/v1/attachments/{$signature->id}")
            ->assertStatus(403);
    }

    public function test_pending_requests_can_be_filtered_to_those_needing_structuring(): void
    {
        $prose = $this->createRaw(['request_text' => 'Need 20 steel nuts'])->json('data.id');
        $this->approveThrough($prose);

        $item = CatalogItem::factory()->create();
        $structured = $this->createRaw(['items' => [['catalog_item_id' => $item->id, 'quantity' => 2]]])->json('data.id');
        $this->approveThrough($structured);

        $procurement = $this->userWithRole('Procurement');

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests')
            ->assertStatus(200)->assertJsonCount(2, 'data.items');

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests?needs_structuring=true')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $prose);

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests?needs_structuring=false')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $structured);
    }

    public function test_purchase_order_detail_carries_the_originating_request_text(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts', 'photos' => [self::PNG]])->json('data.id');
        $this->approveThrough($mrId);

        $vendor = Vendor::create(['name' => 'Acme Supply', 'is_active' => true]);
        $item = CatalogItem::factory()->create();
        $procurement = $this->userWithRole('Procurement');

        $poId = $this->actingAs($procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mrId,
            'vendor_id' => $vendor->id,
            'items' => [['catalog_item_id' => $item->id, 'quantity_ordered' => 20, 'unit_price' => 1.25]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($procurement, 'api')->getJson("/api/v1/purchase-orders/{$poId}")
            ->assertStatus(200)
            ->assertJsonPath('data.material_request.request_text', 'Need 20 steel nuts')
            ->assertJsonCount(1, 'data.material_request.photos');
    }
}
