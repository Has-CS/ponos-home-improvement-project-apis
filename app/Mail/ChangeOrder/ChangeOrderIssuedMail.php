<?php

namespace App\Mail\ChangeOrder;

use App\Models\ChangeOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The change order as it goes to the General Contractor, with its proof.
 *
 * Deliberately the same shape as PurchaseOrderIssuedMail: not ShouldQueue itself,
 * because SendChangeOrderEmailJob is already a queued job and double-queueing a
 * Mailable inside one buys nothing and costs a second serialization of the PDF
 * bytes.
 *
 * ---- what is attached, and why it is ONE file ----
 *
 * A single merged PDF: the change order, then the Work Authorization sheet the GC's
 * own representative signed, then any photos. Not three attachments. The argument is
 * the one PurchaseOrderPdfMergeService makes for vendors — recipients ignore mail
 * carrying three separate documents — but it is stronger here. This change order
 * bills for work that is already built, on the strength of a signature given before
 * anybody priced it. The signed authorization is not supporting material, it is the
 * reason the invoice is collectible, and it must not be the attachment someone
 * forgets to open.
 *
 * ---- Reply-To ----
 *
 * The person who sent it, not MAIL_FROM_ADDRESS, so a General Contractor's reply
 * reaches someone who knows the change order. Their address lives on
 * user_credentials, not users. Omitted entirely when null rather than emitting a
 * malformed header.
 */
class ChangeOrderIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ChangeOrder $changeOrder,
        public string $pdfBytes,
        public string $pdfFileName,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->subjectLine(),
        );

        $email = $this->senderEmail();

        return $email ? $envelope->replyTo($email, $this->senderName() ?? '') : $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.change-order.issued',
            text: 'emails.change-order.issued-text',
            with: [
                'company' => config('company'),
                'gcContactName' => $this->changeOrder->gcBlock()['contact_name'] ?? null,
                'projectLine' => $this->projectLine(),
                'signerName' => $this->changeOrder->currentSignature()?->signer_name,
                'authorizedOn' => $this->changeOrder->currentSignature()?->signed_at,
                'senderName' => $this->senderName(),
                'senderEmail' => $this->senderEmail(),
                'senderMobile' => $this->changeOrder->sentToGcBy?->mobile_number,
            ],
        );
    }

    /** @return array<int,Attachment> */
    public function attachments(): array
    {
        return [
            // fromData with a closure, not fromPath: the merged bytes are already in
            // memory from the job, and the merged copy is not a file on disk until
            // the job files it for the record.
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfFileName)
                ->withMime('application/pdf'),
        ];
    }

    private function subjectLine(): string
    {
        $project = $this->projectLine();

        return trim(
            'Change Order '.$this->changeOrder->change_order_no
            .($project ? ' — '.$project : '')
            .' — '.$this->changeOrder->title
        );
    }

    private function projectLine(): ?string
    {
        $project = $this->changeOrder->project;

        if (! $project) {
            return null;
        }

        return trim(($project->code ? "{$project->code} — " : '').$project->name) ?: null;
    }

    private function senderName(): ?string
    {
        $user = $this->changeOrder->sentToGcBy;

        return $user ? (trim("{$user->first_name} {$user->last_name}") ?: null) : null;
    }

    /** Email lives on user_credentials, not users. */
    private function senderEmail(): ?string
    {
        return $this->changeOrder->sentToGcBy?->credential?->email;
    }
}
