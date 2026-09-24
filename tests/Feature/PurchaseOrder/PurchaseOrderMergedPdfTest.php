<?php

namespace Tests\Feature\PurchaseOrder;

use App\Jobs\SendPurchaseOrderEmailJob;
use App\Mail\PurchaseOrder\PurchaseOrderIssuedMail;
use App\Models\Attachment;
use App\Models\CatalogItem;
use App\Models\EmailLog;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One PDF for the vendor: the order, then its supporting files.
 *
 * Asserted on the real bytes throughout. A merged PDF can be structurally valid
 * and still carry the wrong pages in the wrong order, so page COUNT and page
 * SIZE are both checked — attachment PDFs here are deliberately A5 so the size
 * sequence proves the order pages come first.
 */
class PurchaseOrderMergedPdfTest extends TestCase
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
        $this->procurement->credential()->update(['email' => 'buyer@ponoshome.com']);

        $this->project = Project::factory()->create(['short_code' => 'MRG']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create(['name' => 'Merge Test Supply', 'email' => 'orders@mergetest.com']);
    }

    /** An issued PO, so a document is filed and the number is stable. */
    private function issuedPo(): PurchaseOrder
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
                'quantity_ordered' => 2,
                'unit_price' => 25,
            ]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$id}/issue")->assertOk();

        return PurchaseOrder::findOrFail($id);
    }

    /** A multi-page PDF on A5 pages, so its pages are identifiable by size. */
    private function a5Pdf(int $pages): string
    {
        $pdf = new Fpdi('P', 'mm', 'A5');

        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 14);
            $pdf->Cell(0, 10, "Vendor quote page {$page}", 0, 1);
        }

        return (string) $pdf->Output('S');
    }

    private function upload(PurchaseOrder $po, array $files): void
    {
        $this->actingAs($this->procurement, 'api')
            ->post("/api/v1/purchase-orders/{$po->id}/attachments", ['files' => $files])
            ->assertStatus(201);
    }

    private function fetchPdf(PurchaseOrder $po, string $query = ''): string
    {
        // A plain response, not a streamed one — the endpoint returns the bytes
        // directly so it can merge them first.
        return $this->actingAs($this->procurement, 'api')
            ->get("/api/v1/purchase-orders/{$po->id}/pdf{$query}")
            ->assertOk()
            ->content();
    }

    /** @return array<int,array{width: float, height: float}> */
    private function pages(string $bytes): array
    {
        $pdf = new Fpdi();
        $count = $pdf->setSourceFile(StreamReader::createByString($bytes));

        $pages = [];
        for ($page = 1; $page <= $count; $page++) {
            $size = $pdf->getTemplateSize($pdf->importPage($page));
            $pages[] = ['width' => round($size['width']), 'height' => round($size['height'])];
        }

        return $pages;
    }

    private function filedDocument(PurchaseOrder $po, string $type = 'document'): Attachment
    {
        return Attachment::where('attachable_type', PurchaseOrder::class)
            ->where('attachable_id', $po->id)
            ->where('attachment_type', $type)
            ->latest('id')
            ->firstOrFail();
    }

    private function bytesOf(Attachment $attachment): string
    {
        return Storage::disk($attachment->disk)->get($attachment->file_path);
    }

    /* ---------------- nothing attached: unchanged ---------------- */

    /** The contract that keeps this feature from touching existing orders. */
    public function test_with_no_attachments_the_served_bytes_are_the_filed_document(): void
    {
        $po = $this->issuedPo();

        $this->assertSame($this->bytesOf($this->filedDocument($po)), $this->fetchPdf($po));
    }

    /* ---------------- merged output ---------------- */

    public function test_an_image_is_appended_after_a_divider_page(): void
    {
        $po = $this->issuedPo();
        $basePages = count($this->pages($this->bytesOf($this->filedDocument($po))));

        $this->upload($po, [UploadedFile::fake()->image('rate.png', 600, 400)]);

        $pages = $this->pages($this->fetchPdf($po));

        // order pages + 1 divider + 1 image page
        $this->assertCount($basePages + 2, $pages);
    }

    public function test_a_multi_page_pdf_contributes_all_of_its_pages(): void
    {
        $po = $this->issuedPo();
        $basePages = count($this->pages($this->bytesOf($this->filedDocument($po))));

        $this->upload($po, [UploadedFile::fake()->createWithContent('quote.pdf', $this->a5Pdf(3))]);

        $pages = $this->pages($this->fetchPdf($po));

        $this->assertCount($basePages + 1 + 3, $pages);

        // The A5 pages are the last three: the order comes first, attachments last.
        foreach (array_slice($pages, -3) as $a5) {
            $this->assertSame(148.0, (float) $a5['width'], 'The trailing pages must be the A5 attachment.');
        }

        // And everything before the attachment is A4 — the order and the divider.
        foreach (array_slice($pages, 0, $basePages + 1) as $a4) {
            $this->assertSame(210.0, (float) $a4['width']);
        }
    }

    public function test_mixed_attachments_appear_in_upload_order(): void
    {
        $po = $this->issuedPo();
        $basePages = count($this->pages($this->bytesOf($this->filedDocument($po))));

        // Image first, then a 2-page A5 PDF.
        $this->upload($po, [
            UploadedFile::fake()->image('rate.png', 500, 500),
            UploadedFile::fake()->createWithContent('quote.pdf', $this->a5Pdf(2)),
        ]);

        $pages = $this->pages($this->fetchPdf($po));
        $this->assertCount($basePages + 1 + 1 + 2, $pages);

        // A4 image page sits between the A4 divider and the A5 quote pages.
        $this->assertSame(210.0, (float) $pages[$basePages + 1]['width']);
        $this->assertSame(148.0, (float) $pages[$basePages + 2]['width']);
        $this->assertSame(148.0, (float) $pages[$basePages + 3]['width']);
    }

    public function test_the_merged_output_is_a_valid_readable_pdf(): void
    {
        $po = $this->issuedPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')]);

        $bytes = $this->fetchPdf($po);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', substr($bytes, -2048));
        $this->assertGreaterThan(0, count($this->pages($bytes)));   // re-opens through FPDI
    }

    public function test_base_query_returns_the_order_alone(): void
    {
        $po = $this->issuedPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')]);

        $this->assertSame($this->bytesOf($this->filedDocument($po)), $this->fetchPdf($po, '?base=1'));
    }

    /** A file that cannot be read must degrade to a page, never a 500. */
    public function test_an_unreadable_attachment_becomes_a_placeholder_page(): void
    {
        $po = $this->issuedPo();
        $basePages = count($this->pages($this->bytesOf($this->filedDocument($po))));

        $this->upload($po, [UploadedFile::fake()->createWithContent('quote.pdf', $this->a5Pdf(1))]);

        // Corrupt it on disk, past the upload-time check.
        $stored = $po->supportingAttachments()->firstOrFail();
        Storage::disk($stored->disk)->put($stored->file_path, 'no longer a pdf');

        $pages = $this->pages($this->fetchPdf($po));

        // divider + placeholder, in place of the attachment's own page
        $this->assertCount($basePages + 2, $pages);
    }

    /* ---------------- the vendor email ---------------- */

    public function test_the_vendor_is_emailed_the_merged_pdf(): void
    {
        Mail::fake();

        $po = $this->issuedPo();
        $this->upload($po, [UploadedFile::fake()->createWithContent('quote.pdf', $this->a5Pdf(2))]);

        $basePages = count($this->pages($this->bytesOf($this->filedDocument($po))));

        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $log = EmailLog::where('mailable_id', $po->id)->firstOrFail();
        app()->call([new SendPurchaseOrderEmailJob($log->id, $po->id), "handle"]);

        Mail::assertSent(PurchaseOrderIssuedMail::class, function (PurchaseOrderIssuedMail $mail) use ($basePages) {
            $pages = $this->pages($mail->pdfBytes);

            // The order, a divider, and the two quote pages — not the plain order.
            return count($pages) === $basePages + 3;
        });
    }

    public function test_the_emailed_copy_is_filed_for_the_record(): void
    {
        Mail::fake();

        $po = $this->issuedPo();
        $this->upload($po, [UploadedFile::fake()->image('rate.png')]);

        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $log = EmailLog::where('mailable_id', $po->id)->firstOrFail();
        app()->call([new SendPurchaseOrderEmailJob($log->id, $po->id), "handle"]);

        $sent = $this->filedDocument($po, 'sent_document');

        Mail::assertSent(
            PurchaseOrderIssuedMail::class,
            fn (PurchaseOrderIssuedMail $mail) => $mail->pdfBytes === $this->bytesOf($sent),
        );

        // The original order document is still there, untouched.
        $this->assertDatabaseHas('attachments', [
            'id' => $this->filedDocument($po)->id,
            'attachment_type' => 'document',
            'deleted_at' => null,
        ]);
    }

    /** With nothing attached, nothing extra is stored and the order is sent as filed. */
    public function test_without_attachments_no_sent_copy_is_filed(): void
    {
        Mail::fake();

        $po = $this->issuedPo();
        $filed = $this->bytesOf($this->filedDocument($po));

        $this->actingAs($this->procurement, 'api')->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $log = EmailLog::where('mailable_id', $po->id)->firstOrFail();
        app()->call([new SendPurchaseOrderEmailJob($log->id, $po->id), "handle"]);

        Mail::assertSent(
            PurchaseOrderIssuedMail::class,
            fn (PurchaseOrderIssuedMail $mail) => $mail->pdfBytes === $filed,
        );

        $this->assertDatabaseMissing('attachments', [
            'attachable_id' => $po->id,
            'attachment_type' => 'sent_document',
        ]);
    }
}
