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
use App\Services\PurchaseOrder\PurchaseOrderPdfService;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sending a purchase order actually emails the vendor, with the PDF filed at
 * issue attached — the same shape RfqEmailTest covers for the RFQ.
 *
 * The attachment assertions matter most: a mail can "send" perfectly while
 * carrying nothing, or worse, carrying another order's document.
 */
class PurchaseOrderEmailTest extends TestCase
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
        $this->procurement->forceFill(['mobile_number' => '(203) 491-4431'])->save();
        $this->procurement->refresh();

        $this->project = Project::factory()->create(['code' => 'PNS-2026-501']);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $this->project->id]);

        $this->vendor = Vendor::create([
            'name' => 'Northgate Building Supply',
            'contact_name' => 'Marcus Webb',
            'email' => 'orders@northgatesupply.com',
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

    /** A PO taken all the way to `issued`, so its PDF is filed. */
    private function issuedPo(): int
    {
        $id = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->approvedMr()->id,
            'vendor_id' => $this->vendor->id,
            'expected_delivery_date' => '2026-10-15',
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 12,
                'unit_price' => 41.25,
            ]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$id}/issue")
            ->assertOk();

        return $id;
    }

    private function send(int $poId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/send");
    }

    /** Send, then run the queued job the way a worker would. */
    private function sendAndDeliver(int $poId): void
    {
        $this->send($poId)->assertOk();

        $log = EmailLog::where('mailable_type', PurchaseOrder::class)->where('mailable_id', $poId)->firstOrFail();

        // Resolved through the container, the way the queue worker calls it — so
        // adding a dependency to handle() doesn't break every call site.
        app()->call([new SendPurchaseOrderEmailJob($log->id, $poId), 'handle']);
    }

    private function filedPdf(int $poId): string
    {
        $filed = Attachment::where('attachable_type', PurchaseOrder::class)
            ->where('attachable_id', $poId)
            ->where('attachment_type', 'document')
            ->firstOrFail();

        return Storage::disk($filed->disk)->get($filed->file_path);
    }

    /* ---------------- addressing ---------------- */

    public function test_the_vendor_receives_it_and_replies_reach_the_issuer(): void
    {
        Bus::fake();
        Mail::fake();

        $this->sendAndDeliver($this->issuedPo());

        Mail::assertSent(PurchaseOrderIssuedMail::class, function (PurchaseOrderIssuedMail $mail) {
            return $mail->hasTo($this->vendor->email)
                && $mail->hasReplyTo('buyer@ponoshome.com');
        });
    }

    /* ---------------- the attachment ---------------- */

    public function test_the_attached_pdf_is_this_orders_filed_document(): void
    {
        Bus::fake();
        Mail::fake();

        $poId = $this->issuedPo();
        $this->sendAndDeliver($poId);

        $expected = $this->filedPdf($poId);
        $poNumber = PurchaseOrder::findOrFail($poId)->po_number;

        Mail::assertSent(PurchaseOrderIssuedMail::class, function (PurchaseOrderIssuedMail $mail) use ($expected, $poNumber) {
            // Exercises attachments() rather than the constructor property, so a
            // mail that carries nothing cannot pass.
            $mail->assertHasAttachedData($expected, "{$poNumber}.pdf", ['mime' => 'application/pdf']);

            return str_starts_with($mail->pdfBytes, '%PDF')
                && $mail->pdfBytes === $expected;
        });
    }

    /**
     * With two orders in flight, each vendor must get its OWN document — the
     * failure mode a single-PO test cannot see.
     */
    public function test_each_order_carries_its_own_document(): void
    {
        Bus::fake();
        Mail::fake();

        $first = $this->issuedPo();
        $second = $this->issuedPo();

        $this->sendAndDeliver($first);
        $this->sendAndDeliver($second);

        $this->assertNotSame($this->filedPdf($first), $this->filedPdf($second));

        foreach ([$first, $second] as $poId) {
            $expected = $this->filedPdf($poId);
            $poNumber = PurchaseOrder::findOrFail($poId)->po_number;

            Mail::assertSent(
                PurchaseOrderIssuedMail::class,
                fn (PurchaseOrderIssuedMail $mail) => $mail->purchaseOrder->id === $poId
                    && $mail->pdfBytes === $expected
                    && $mail->pdfFileName === "{$poNumber}.pdf",
            );
        }
    }

    /* ---------------- edge cases ---------------- */

    public function test_a_vendor_without_an_email_fails_without_marking_it_sent(): void
    {
        Bus::fake();
        Mail::fake();

        $poId = $this->issuedPo();
        $this->vendor->update(['email' => null]);

        $this->send($poId)->assertStatus(422);

        // Still issued, never stamped, and nothing queued or logged.
        $po = PurchaseOrder::with('status')->findOrFail($poId);
        $this->assertSame('issued', $po->status->code);
        $this->assertNull($po->sent_at);
        $this->assertDatabaseCount('email_logs', 0);
        Bus::assertNotDispatched(SendPurchaseOrderEmailJob::class);
    }

    public function test_a_missing_filed_document_fails_without_marking_it_sent(): void
    {
        Bus::fake();
        Mail::fake();

        $poId = $this->issuedPo();

        // The filed document record is gone — the order cannot be sent.
        Attachment::where('attachable_type', PurchaseOrder::class)
            ->where('attachable_id', $poId)
            ->where('attachment_type', 'document')
            ->delete();

        $this->send($poId)->assertStatus(422);

        $po = PurchaseOrder::with('status')->findOrFail($poId);
        $this->assertSame('issued', $po->status->code);
        $this->assertNull($po->sent_at);
        Bus::assertNotDispatched(SendPurchaseOrderEmailJob::class);
    }

    public function test_a_draft_still_cannot_be_sent(): void
    {
        Bus::fake();

        $poId = $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $this->approvedMr()->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 2,
                'unit_price' => 10,
            ]],
        ])->assertStatus(201)->json('data.id');

        $this->send($poId)->assertStatus(409);

        Bus::assertNotDispatched(SendPurchaseOrderEmailJob::class);
    }

    /* ---------------- existing behaviour is preserved ---------------- */

    public function test_send_still_marks_the_order_sent_and_stamps_sent_at(): void
    {
        Bus::fake();

        $poId = $this->issuedPo();

        $this->send($poId)->assertOk()->assertJsonPath('data.status.code', 'sent');

        $po = PurchaseOrder::with('status')->findOrFail($poId);
        $this->assertSame('sent', $po->status->code);
        $this->assertNotNull($po->sent_at);

        Bus::assertDispatched(SendPurchaseOrderEmailJob::class);
    }

    public function test_the_response_reports_the_vendor_document_and_delivery_status(): void
    {
        Bus::fake();

        $poId = $this->issuedPo();
        $poNumber = PurchaseOrder::findOrFail($poId)->po_number;

        $response = $this->send($poId)->assertOk();

        $response
            ->assertJsonPath('message', "{$poNumber} has been sent to {$this->vendor->name} with the purchase order document attached.")
            ->assertJsonPath('data.delivery.to', $this->vendor->email)
            ->assertJsonPath('data.delivery.vendor_name', $this->vendor->name)
            ->assertJsonPath('data.delivery.document', "{$poNumber}.pdf")
            // The existing detail payload must survive alongside the new block.
            ->assertJsonPath('data.id', $poId);

        // Read from the EmailLog, never hardcoded.
        $this->assertSame(
            EmailLog::where('mailable_id', $poId)->latest('id')->value('status'),
            $response->json('data.delivery.status'),
        );
    }

    /* ---------------- the real delivered message ---------------- */

    /**
     * End to end through the real mailer — no Mail::fake anywhere.
     *
     * Everything above asserts against the Mailable object; this asserts against
     * the MIME message that actually leaves, because an attachment can be
     * declared correctly and still arrive empty or corrupt once encoded. The
     * queue is sync under phpunit.xml, so POST /send runs the job inline.
     */
    public function test_the_delivered_message_carries_an_openable_pdf(): void
    {
        $poId = $this->issuedPo();
        $poNumber = PurchaseOrder::findOrFail($poId)->po_number;

        $this->send($poId)->assertOk();

        $messages = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->filter(fn ($email) => str_contains((string) $email->getSubject(), $poNumber));

        $this->assertCount(1, $messages, 'Exactly one message should have left for this order.');

        /** @var \Symfony\Component\Mime\Email $email */
        $email = $messages->first();

        $this->assertSame($this->vendor->email, $email->getTo()[0]->getAddress());
        $this->assertSame('buyer@ponoshome.com', $email->getReplyTo()[0]->getAddress());

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments, 'The order must travel with exactly one attachment.');

        $pdf = $attachments[0];
        $this->assertSame("{$poNumber}.pdf", $pdf->getFilename());
        $this->assertSame('application/pdf', $pdf->getContentType());

        // The decoded bytes, as the vendor's mail client would reconstruct them.
        $bytes = $pdf->getBody();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);       // not truncated
        $this->assertGreaterThan(5000, strlen($bytes));           // a real document, not a stub
        $this->assertSame($this->filedPdf($poId), $bytes);        // THIS order's filed copy

        // And the body the vendor reads came through the shared layout.
        $this->assertStringContainsString('email-container', $email->getHtmlBody());
        $this->assertStringContainsString($poNumber, $email->getHtmlBody());
        $this->assertNotEmpty($email->getTextBody());

        // The log reflects real delivery, not just the queueing.
        $this->assertSame('sent', EmailLog::where('mailable_id', $poId)->latest('id')->value('status'));
    }

    /* ---------------- rendered body ---------------- */

    private function renderHtml(int $poId): string
    {
        $po = PurchaseOrder::with(['vendor', 'issuedBy.credential'])->findOrFail($poId);

        return (new PurchaseOrderIssuedMail($po, '%PDF-fake', 'PO-TEST.pdf'))->render();
    }

    public function test_the_body_names_the_order_and_is_signed_by_the_issuer(): void
    {
        $html = $this->renderHtml($this->issuedPo());

        $this->assertStringContainsString('buyer@ponoshome.com', $html);
        $this->assertStringContainsString('(203) 491-4431', $html);
        $this->assertStringContainsString('Marcus Webb', $html);   // vendor contact
        $this->assertStringContainsString('495.00', $html);        // 12 x 41.25
        $this->assertStringNotContainsString('null', strtolower($html));
    }

    /** The shared layout every other mail in the system uses. */
    public function test_it_renders_inside_the_shared_mail_layout(): void
    {
        $html = $this->renderHtml($this->issuedPo());

        // Markers that come from components/mail/layout.blade.php alone.
        $this->assertStringContainsString('#172A21', $html);
        $this->assertStringContainsString('#FAF8F5', $html);
        $this->assertStringContainsString('email-container', $html);
    }

    /**
     * The text template is a separate Blade file only the mailer touches, so a
     * parse error in it stays invisible until a real send fails.
     */
    public function test_the_plain_text_body_renders_and_is_signed_too(): void
    {
        $po = PurchaseOrder::with(['vendor', 'issuedBy.credential'])->findOrFail($this->issuedPo());

        $text = view('emails.purchase-order.order-text', [
            'purchaseOrder' => $po,
            'pdfFileName' => 'PO-TEST.pdf',
            'company' => config('company'),
            'vendorContactName' => $po->vendor?->contact_name,
            'issuerName' => trim("{$po->issuedBy?->first_name} {$po->issuedBy?->last_name}") ?: null,
            'issuerEmail' => $po->issuedBy?->credential?->email,
            'issuerMobile' => $po->issuedBy?->mobile_number,
        ])->render();

        $this->assertStringContainsString($po->po_number, $text);
        $this->assertStringContainsString('buyer@ponoshome.com', $text);
        $this->assertStringNotContainsString('&middot;', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }
}
