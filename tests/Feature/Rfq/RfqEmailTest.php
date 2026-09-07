<?php

namespace Tests\Feature\Rfq;

use App\Jobs\SendRfqEmailJob;
use App\Mail\Rfq\RfqQuoteRequestMail;
use App\Models\Attachment;
use App\Models\EmailLog;
use App\Models\Rfq;
use App\Models\User;
use App\Services\Rfq\RfqPdfService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The vendor email: who it reaches, who it comes back to, and what it carries.
 *
 * The Reply-To matters more than it looks. The body tells the vendor to "reply
 * to this email with your pricing", but the message is sent from
 * MAIL_FROM_ADDRESS — a shared inbox. Without Reply-To pointing at the author,
 * that instruction quietly misroutes every quote.
 */
class RfqEmailTest extends RfqTestCase
{
    /** Run the queued job for a just-submitted RFQ and return its id. */
    private function submitAndDeliver(User $author): int
    {
        $id = $this->draftWithItem($author);
        $this->submitAs($author, $id)->assertOk();

        $log = EmailLog::where('mailable_type', Rfq::class)->where('mailable_id', $id)->firstOrFail();
        (new SendRfqEmailJob($log->id, $id))->handle(app(RfqPdfService::class));

        return $id;
    }

    private function author(string $email = 'dana@ponoshome.com', ?string $mobile = '(203) 491-4431'): User
    {
        $this->pm->credential()->update(['email' => $email]);
        $this->pm->forceFill(['mobile_number' => $mobile])->save();

        return $this->pm->refresh();
    }

    /* ---------------- addressing ---------------- */

    public function test_the_vendor_receives_it_and_replies_reach_the_author(): void
    {
        Bus::fake();
        Mail::fake();

        $author = $this->author();
        $this->submitAndDeliver($author);

        Mail::assertSent(RfqQuoteRequestMail::class, function (RfqQuoteRequestMail $mail) use ($author) {
            return $mail->hasTo($this->vendor->email)
                && $mail->hasReplyTo($author->credential->email);
        });
    }

    /** No email on file must not produce a malformed header or a crash. */
    public function test_an_author_without_an_email_sends_with_no_reply_to(): void
    {
        Bus::fake();
        Mail::fake();

        $this->pm->credential()->delete();

        $this->submitAndDeliver($this->pm->refresh());

        Mail::assertSent(RfqQuoteRequestMail::class, fn (RfqQuoteRequestMail $mail) => $mail->hasTo($this->vendor->email));
    }

    /* ---------------- the attachment ---------------- */

    public function test_the_attached_pdf_is_this_rfqs_filed_document(): void
    {
        Bus::fake();
        Mail::fake();

        $id = $this->submitAndDeliver($this->author());

        $filed = Attachment::where('attachable_type', Rfq::class)
            ->where('attachable_id', $id)
            ->where('attachment_type', 'document')
            ->firstOrFail();

        $expected = Storage::disk($filed->disk)->get($filed->file_path);

        Mail::assertSent(RfqQuoteRequestMail::class, function (RfqQuoteRequestMail $mail) use ($expected, $id) {
            // Exercises attachments() rather than the constructor property.
            $mail->assertHasAttachedData(
                $expected,
                Rfq::findOrFail($id)->rfq_no.'.pdf',
                ['mime' => 'application/pdf'],
            );

            return str_starts_with($mail->pdfBytes, '%PDF')
                && $mail->pdfBytes === $expected;   // the RIGHT rfq's document
        });
    }

    /* ---------------- rendered body ---------------- */

    /** The mail's rendered HTML body. */
    private function renderHtml(int $rfqId): string
    {
        $rfq = Rfq::with(['vendor', 'creator.credential'])->findOrFail($rfqId);

        return (new RfqQuoteRequestMail($rfq, '%PDF-fake', 'RFQ-TEST.pdf'))->render();
    }

    /**
     * The plain-text alternative, rendered directly.
     *
     * Worth its own coverage: the text template is a separate Blade file that
     * only the mailer touches, so a parse error in it goes unnoticed until a
     * real send fails — which is exactly what happened while building this.
     */
    private function renderText(int $rfqId): string
    {
        $rfq = Rfq::with(['vendor', 'creator.credential'])->findOrFail($rfqId);

        return view('emails.rfq.quote-request-text', [
            'rfq' => $rfq,
            'pdfFileName' => 'RFQ-TEST.pdf',
            'company' => config('company'),
            'vendorContactName' => $rfq->vendor?->contact_name,
            'authorName' => trim("{$rfq->creator?->first_name} {$rfq->creator?->last_name}") ?: null,
            'authorEmail' => $rfq->creator?->credential?->email,
            'authorMobile' => $rfq->creator?->mobile_number,
        ])->render();
    }

    public function test_the_body_is_signed_by_the_author_and_shows_their_contacts(): void
    {
        $author = $this->author();
        $id = $this->draftWithItem($author);

        $html = $this->renderHtml($id);

        $this->assertStringContainsString(trim("{$author->first_name} {$author->last_name}"), $html);
        $this->assertStringContainsString('dana@ponoshome.com', $html);
        $this->assertStringContainsString('(203) 491-4431', $html);
    }

    public function test_an_author_without_a_mobile_renders_cleanly(): void
    {
        $author = $this->author(mobile: null);
        $id = $this->draftWithItem($author);

        $html = $this->renderHtml($id);

        $this->assertStringContainsString('dana@ponoshome.com', $html);
        $this->assertStringNotContainsString('null', strtolower($html));
        // The separator must not be left stranded with nothing after it.
        $this->assertStringNotContainsString('&middot; </span>', $html);
    }

    /**
     * The email must keep rendering through the shared layout every other mail
     * in the system uses — this fails if someone refactors away from it.
     */
    public function test_it_still_renders_inside_the_shared_mail_layout(): void
    {
        $html = $this->renderHtml($this->draftWithItem($this->author()));

        // Markers that come from components/mail/layout.blade.php alone.
        $this->assertStringContainsString('#172A21', $html);   // header band
        $this->assertStringContainsString('#FAF8F5', $html);   // page ground
        $this->assertStringContainsString('email-container', $html);
    }

    /* ---------------- the API response ---------------- */

    public function test_submit_reports_the_vendor_document_and_delivery_status(): void
    {
        $id = $this->draftWithItem($this->author());

        $response = $this->submitAs($this->pm, $id)->assertOk();

        $rfqNo = Rfq::findOrFail($id)->rfq_no;

        $response
            ->assertJsonPath('message', "{$rfqNo} has been sent to {$this->vendor->name} with the quotation document attached.")
            ->assertJsonPath('data.delivery.to', $this->vendor->email)
            ->assertJsonPath('data.delivery.vendor_name', $this->vendor->name)
            ->assertJsonPath('data.delivery.document', "{$rfqNo}.pdf");

        // Read from the EmailLog, never hardcoded.
        $this->assertSame(
            EmailLog::where('mailable_id', $id)->latest('id')->value('status'),
            $response->json('data.delivery.status'),
        );
    }

    /** The existing payload must survive alongside the new block. */
    public function test_the_response_still_carries_the_rfq_detail(): void
    {
        $id = $this->draftWithItem($this->author());

        $this->submitAs($this->pm, $id)->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status.code', 'sent')
            ->assertJsonCount(1, 'data.items');
    }
    public function test_the_plain_text_body_renders_and_is_signed_too(): void
    {
        $author = $this->author();
        $text = $this->renderText($this->draftWithItem($author));

        $this->assertStringContainsString(trim("{$author->first_name} {$author->last_name}"), $text);
        $this->assertStringContainsString('dana@ponoshome.com', $text);
        $this->assertStringContainsString('(203) 491-4431', $text);

        // A plain-text mail must not carry HTML entities.
        $this->assertStringNotContainsString('&middot;', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }
}