<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\Attachment;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use setasign\Fpdi\Fpdi;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Supporting files on a purchase order: rate screenshots, emailed quotes,
 * contract scans. They are appended to the PDF the vendor receives.
 */
class PurchaseOrderAttachmentTest extends TestCase
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

        Storage::fake('local');

        $this->procurement = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->procurement,
            Role::where('name', 'Procurement')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        $this->project = Project::factory()->create(['short_code' => 'ATT']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create(['name' => 'Attachment Test Supply', 'email' => 'orders@attachtest.com']);
    }

    private function draftPo(): PurchaseOrder
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);

        $id = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 1,
                'unit_price' => 10,
            ]],
        ])->assertStatus(201)->json('data.id');

        return PurchaseOrder::findOrFail($id);
    }

    /** A genuinely valid single-page PDF, so the mergeability check passes. */
    public static function realPdfBytes(string $text = 'Vendor quote'): string
    {
        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 14);
        $pdf->Cell(0, 10, $text, 0, 1);

        return (string) $pdf->Output('S');
    }

    /** @param array<int,UploadedFile> $files */
    private function upload(PurchaseOrder $po, array $files): TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->post("/api/v1/purchase-orders/{$po->id}/attachments", ['files' => $files]);
    }

    /* ---------------- uploading ---------------- */

    public function test_an_image_can_be_attached(): void
    {
        $po = $this->draftPo();

        $this->upload($po, [UploadedFile::fake()->image('rate.png', 600, 400)])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.file_name', 'rate.png')
            ->assertJsonPath('data.attachments.0.mime_type', 'image/png');

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => PurchaseOrder::class,
            'attachable_id' => $po->id,
            'attachment_type' => 'supporting',
            'file_name' => 'rate.png',
        ]);
    }

    public function test_a_pdf_can_be_attached(): void
    {
        $po = $this->draftPo();

        $this->upload($po, [UploadedFile::fake()->createWithContent('quote.pdf', self::realPdfBytes())])
            ->assertStatus(201)
            ->assertJsonPath('data.attachments.0.file_name', 'quote.pdf')
            ->assertJsonPath('data.attachments.0.mime_type', 'application/pdf');
    }

    public function test_several_files_can_be_attached_at_once(): void
    {
        $po = $this->draftPo();

        $this->upload($po, [
            UploadedFile::fake()->image('one.png'),
            UploadedFile::fake()->image('two.jpg'),
            UploadedFile::fake()->createWithContent('three.pdf', self::realPdfBytes()),
        ])->assertStatus(201)->assertJsonCount(3, 'data.attachments');
    }

    public function test_the_files_are_stored_on_the_private_disk(): void
    {
        $po = $this->draftPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')])->assertStatus(201);

        $stored = Attachment::where('attachable_id', $po->id)->where('attachment_type', 'supporting')->firstOrFail();

        $this->assertSame('local', $stored->disk);
        Storage::disk('local')->assertExists($stored->file_path);
        $this->assertStringStartsWith('purchase-order-attachments/', $stored->file_path);
    }

    /* ---------------- rejected uploads ---------------- */

    public function test_an_unsupported_type_is_rejected(): void
    {
        $po = $this->draftPo();

        $this->upload($po, [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_an_oversize_file_is_rejected(): void
    {
        $po = $this->draftPo();

        // 11 MB, past the 10 MB AttachmentService limit the rule quotes.
        $this->upload($po, [UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    /** A PDF the free parser cannot read must be refused at upload, not at send. */
    public function test_an_unreadable_pdf_is_rejected_at_upload(): void
    {
        $po = $this->draftPo();

        $this->upload($po, [UploadedFile::fake()->createWithContent('broken.pdf', 'this is not a pdf at all')])
            ->assertStatus(422);

        // Nothing was written: the order is still a draft, so it has no filed
        // document either. The point is that the bad PDF never reached the disk.
        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('local')->assertDirectoryEmpty('purchase-order-attachments');
    }

    public function test_more_than_ten_files_are_rejected(): void
    {
        $po = $this->draftPo();

        $this->upload($po, array_map(
            fn (int $i) => UploadedFile::fake()->image("shot{$i}.png"),
            range(1, 11),
        ))->assertStatus(422);
    }

    /** The cap counts what is already stored, not just this request. */
    public function test_the_cap_cannot_be_walked_past_one_request_at_a_time(): void
    {
        $po = $this->draftPo();

        $this->upload($po, array_map(fn (int $i) => UploadedFile::fake()->image("a{$i}.png"), range(1, 6)))
            ->assertStatus(201);
        $this->upload($po, array_map(fn (int $i) => UploadedFile::fake()->image("b{$i}.png"), range(1, 5)))
            ->assertStatus(422);

        $this->assertCount(6, $po->supportingAttachments()->get());
    }

    /* ---------------- removing ---------------- */

    public function test_an_attachment_can_be_removed(): void
    {
        $po = $this->draftPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')])->assertStatus(201);
        $id = $po->supportingAttachments()->firstOrFail()->id;

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$po->id}/attachments/{$id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments');

        $this->assertSoftDeleted('attachments', ['id' => $id]);
    }

    public function test_an_attachment_from_another_order_is_not_found(): void
    {
        $first = $this->draftPo();
        $second = $this->draftPo();

        $this->upload($second, [UploadedFile::fake()->image('rate.png')])->assertStatus(201);
        $foreign = $second->supportingAttachments()->firstOrFail()->id;

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$first->id}/attachments/{$foreign}")
            ->assertStatus(404);
    }

    /** The generated PO document must not be deletable through this route. */
    public function test_the_generated_document_cannot_be_removed_as_an_attachment(): void
    {
        $po = $this->draftPo();
        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/issue")->assertOk();

        $document = Attachment::where('attachable_id', $po->id)->where('attachment_type', 'document')->firstOrFail();

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$po->id}/attachments/{$document->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('attachments', ['id' => $document->id, 'deleted_at' => null]);
    }

    /* ---------------- status rule ---------------- */

    public function test_attachments_are_allowed_after_the_order_is_issued(): void
    {
        $po = $this->draftPo();
        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/issue")->assertOk();

        // A rate screenshot routinely turns up after the order went out.
        $this->upload($po, [UploadedFile::fake()->image('late.png')])->assertStatus(201);
    }

    public function test_attachments_are_refused_on_a_cancelled_order(): void
    {
        $po = $this->draftPo();
        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/cancel")->assertOk();

        $this->upload($po, [UploadedFile::fake()->image('rate.png')])->assertStatus(409);
    }

    /* ---------------- reading the file back ---------------- */

    /**
     * The buyer who uploaded it has to be able to open it. Procurement is not
     * staffed onto projects, so AttachmentController has to allow `supporting`
     * files explicitly — this fails without that widening.
     */
    public function test_a_buyer_can_download_a_supporting_file(): void
    {
        $po = $this->draftPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')])->assertStatus(201);
        $id = $po->supportingAttachments()->firstOrFail()->id;

        $this->actingAs($this->procurement, 'api')
            ->get("/api/v1/attachments/{$id}")
            ->assertOk();
    }

    public function test_an_unrelated_user_cannot_download_a_supporting_file(): void
    {
        $po = $this->draftPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')])->assertStatus(201);
        $id = $po->supportingAttachments()->firstOrFail()->id;

        $foreman = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $foreman,
            Role::where('name', 'Foreman')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        $this->actingAs($foreman, 'api')->get("/api/v1/attachments/{$id}")->assertStatus(403);
    }
}
