<?php

namespace Tests\Feature\Rfq;

use App\Jobs\SendRfqEmailJob;
use App\Mail\Rfq\RfqQuoteRequestMail;
use App\Models\Attachment;
use App\Models\EmailLog;
use App\Models\Rfq;
use App\Models\Vendor;
use App\Services\Rfq\RfqPdfService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class RfqSubmitTest extends RfqTestCase
{
    public function test_submit_requires_at_least_one_item(): void
    {
        $id = $this->createDraftAs($this->pm);

        $this->submitAs($this->pm, $id)->assertStatus(422);
    }

    public function test_submit_requires_the_vendor_to_have_an_email(): void
    {
        $emailless = Vendor::create(['name' => 'No Email Supply Co.', 'is_active' => true]);
        $id = $this->createDraftAs($this->pm, ['vendor_id' => $emailless->id]);
        $this->addItemAs($this->pm, $id, ['catalog_item_id' => $this->catalogItem->id, 'quantity' => 1])
            ->assertStatus(201);

        $this->submitAs($this->pm, $id)->assertStatus(422);
    }

    public function test_submit_files_the_pdf_and_flips_status_to_sent(): void
    {
        Bus::fake();

        $id = $this->draftWithItem();

        $response = $this->submitAs($this->pm, $id);
        $response->assertOk();
        $response->assertJsonPath('data.status.code', 'sent');

        $rfq = Rfq::findOrFail($id);
        $this->assertSame('sent', $rfq->status->code);
        $this->assertNotNull($rfq->sent_at);

        $doc = Attachment::where('attachable_type', Rfq::class)
            ->where('attachable_id', $id)
            ->where('attachment_type', 'document')
            ->first();

        $this->assertNotNull($doc);
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF', Storage::disk($doc->disk)->get($doc->file_path));
    }

    public function test_submit_creates_a_queued_email_log_and_dispatches_the_job(): void
    {
        Bus::fake();

        $id = $this->draftWithItem();
        $this->submitAs($this->pm, $id)->assertOk();

        $log = EmailLog::where('mailable_type', Rfq::class)->where('mailable_id', $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('queued', $log->status);
        $this->assertSame($this->vendor->email, $log->to_email);

        Bus::assertDispatched(SendRfqEmailJob::class, function (SendRfqEmailJob $job) use ($id, $log) {
            return $job->rfqId === $id && $job->emailLogId === $log->id;
        });
    }

    public function test_a_sent_rfq_cannot_be_resubmitted(): void
    {
        Bus::fake();

        $id = $this->draftWithItem();
        $this->submitAs($this->pm, $id)->assertOk();

        $this->submitAs($this->pm, $id)->assertStatus(409);
    }

    public function test_editing_is_blocked_once_sent(): void
    {
        Bus::fake();

        $id = $this->draftWithItem();
        $itemId = Rfq::findOrFail($id)->items->first()->id;
        $this->submitAs($this->pm, $id)->assertOk();

        $this->updateRfqAs($this->pm, $id, ['title' => 'Too late'])->assertStatus(409);
        $this->addItemAs($this->pm, $id, ['catalog_item_id' => $this->catalogItem->id, 'quantity' => 1])->assertStatus(409);
        $this->updateItemAs($this->pm, $id, $itemId, ['quantity' => 99])->assertStatus(409);
        $this->removeItemAs($this->pm, $id, $itemId)->assertStatus(409);
        $this->deleteRfqAs($this->pm, $id)->assertStatus(409);
    }

    public function test_pdf_renders_live_while_draft_and_serves_the_filed_copy_once_sent(): void
    {
        Bus::fake();

        $id = $this->draftWithItem();

        $draftPdf = $this->actingAs($this->pm, 'api')->get("/api/v1/rfqs/{$id}/pdf");
        $draftPdf->assertOk();
        $this->assertSame('application/pdf', $draftPdf->headers->get('Content-Type'));

        $this->submitAs($this->pm, $id)->assertOk();

        $sentPdf = $this->actingAs($this->pm, 'api')->get("/api/v1/rfqs/{$id}/pdf");
        $sentPdf->assertOk();
    }

    /**
     * Runs SendRfqEmailJob::handle() directly rather than through the queue —
     * dispatch()->afterCommit() never actually fires inside a RefreshDatabase-
     * wrapped test (the outer test transaction is rolled back, never truly
     * committed, so DB-transaction-manager "after commit" callbacks never run).
     * This still exercises the real behaviour: reading the filed PDF back off
     * disk, attaching it, and sending to the vendor's address.
     */
    public function test_the_queued_job_emails_the_vendor_with_the_filed_pdf_attached(): void
    {
        Bus::fake();
        Mail::fake();

        $id = $this->draftWithItem();
        $this->submitAs($this->pm, $id)->assertOk();

        $log = EmailLog::where('mailable_type', Rfq::class)->where('mailable_id', $id)->firstOrFail();

        (new SendRfqEmailJob($log->id, $id))->handle(app(RfqPdfService::class));

        Mail::assertSent(RfqQuoteRequestMail::class, function (RfqQuoteRequestMail $mail) use ($id) {
            return $mail->rfq->id === $id
                && $mail->hasTo($this->vendor->email)
                && str_starts_with($mail->pdfBytes, '%PDF');
        });

        $this->assertSame('sent', EmailLog::findOrFail($log->id)->status);
    }
}
