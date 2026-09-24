<?php

namespace App\Mail\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The purchase order sent to a vendor.
 *
 * Deliberately the same shape as RfqQuoteRequestMail: not itself queued —
 * SendPurchaseOrderEmailJob is the queued unit of work and calls Mail::send()
 * synchronously inside handle(). The attached PDF is the copy filed by
 * PurchaseOrderPdfService::storeFor() at ISSUE, passed in as bytes rather than
 * re-rendered here, so the vendor receives the exact document the order was
 * issued as — terms, prices and issuer frozen at that moment.
 */
class PurchaseOrderIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public string $pdfBytes,
        public string $pdfFileName,
    ) {}

    /**
     * Reply-To is the person who ISSUED the order, not MAIL_FROM_ADDRESS — a
     * shared inbox nobody watches. A vendor replying about substitutions, stock
     * or dates has to reach the buyer who placed it. Same reasoning as the RFQ
     * mail, and the same person the PDF's signature block names.
     *
     * Omitted entirely when the issuer has no email on file, rather than
     * emitting a malformed header.
     */
    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: "Purchase Order {$this->purchaseOrder->po_number} from ".config('company.name'),
        );

        $email = $this->issuerEmail();

        return $email === null
            ? $envelope
            : $envelope->replyTo($email, $this->issuerName() ?? '');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-order.order',
            text: 'emails.purchase-order.order-text',
            with: [
                'company' => config('company'),
                'vendorContactName' => $this->purchaseOrder->vendor?->contact_name,
                // The sign-off names the same person the PDF's signature block
                // does, so the vendor sees one consistent contact across both.
                // Each may be null; the templates omit the line.
                'issuerName' => $this->issuerName(),
                'issuerEmail' => $this->issuerEmail(),
                'issuerMobile' => $this->purchaseOrder->issuedBy?->mobile_number,
            ],
        );
    }

    /** The issuing buyer's display name, or null when there is no issuer. */
    private function issuerName(): ?string
    {
        $issuer = $this->purchaseOrder->issuedBy;

        if (! $issuer) {
            return null;
        }

        return trim("{$issuer->first_name} {$issuer->last_name}") ?: null;
    }

    /** Email lives on user_credentials, not users. */
    private function issuerEmail(): ?string
    {
        return $this->purchaseOrder->issuedBy?->credential?->email;
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
