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

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Request for Quotation {$this->rfq->rfq_no} — {$this->rfq->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rfq.quote-request',
            text: 'emails.rfq.quote-request-text',
            with: [
                'company' => config('company'),
                'vendorContactName' => $this->rfq->vendor?->contact_name,
            ],
        );
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
