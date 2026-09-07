<?php

namespace App\Mail\Rfq;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Request for Quotation sent to a vendor.
 *
 * Not itself queued (unlike a ShouldQueue job) — SendRfqEmailJob is the queued
 * unit of work and calls Mail::send() synchronously inside its handle(), the
 * same split WelcomeMail/SendWelcomeEmailJob use. This is the first Mailable
 * in the app that attaches a file: the RFQ's filed PDF, already rendered and
 * stored by RfqPdfService::storeFor() before this mail is built, so the bytes
 * are simply attached rather than re-rendered here.
 */
class RfqQuoteRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Rfq $rfq,
        public string $pdfBytes,
        public string $pdfFileName,
    ) {}

    /**
     * The body asks the vendor to "reply with your pricing", so Reply-To has to
     * reach the person who raised the RFQ — not MAIL_FROM_ADDRESS, which is a
     * shared inbox nobody is watching for quotes. Without this the instruction
     * in the email is untrue.
     *
     * Omitted entirely when the author has no email on file, rather than
     * emitting a malformed header.
     */
    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: "Request for Quotation {$this->rfq->rfq_no} — {$this->rfq->title}",
        );

        $email = $this->authorEmail();

        return $email === null
            ? $envelope
            : $envelope->replyTo($email, $this->authorName() ?? '');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rfq.quote-request',
            text: 'emails.rfq.quote-request-text',
            with: [
                'company' => config('company'),
                'vendorContactName' => $this->rfq->vendor?->contact_name,
                // The sign-off names the same person the PDF's "Prepared by"
                // panel does, so the vendor sees one consistent contact across
                // both. Each may be null; the templates omit the line.
                'authorName' => $this->authorName(),
                'authorEmail' => $this->authorEmail(),
                'authorMobile' => $this->rfq->creator?->mobile_number,
            ],
        );
    }

    /** The RFQ author's display name, or null when there is no author. */
    private function authorName(): ?string
    {
        $creator = $this->rfq->creator;

        if (! $creator) {
            return null;
        }

        return trim("{$creator->first_name} {$creator->last_name}") ?: null;
    }

    /** Email lives on user_credentials, not users. */
    private function authorEmail(): ?string
    {
        return $this->rfq->creator?->credential?->email;
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfFileName)
                ->withMime('application/pdf'),
        ];
    }
}
