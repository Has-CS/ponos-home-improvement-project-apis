<?php

namespace App\Jobs;

use App\Mail\ChangeOrder\ChangeOrderIssuedMail;
use App\Models\ChangeOrder;
use App\Models\EmailLog;
use App\Services\Attachment\AttachmentService;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use App\Services\ChangeOrder\ChangeOrderProofPackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Sends a priced change order to the General Contractor, with its proof package.
 *
 * Deliberately the same shape as SendPurchaseOrderEmailJob, down to the retry
 * schedule, because the failure modes are identical: an external recipient, an SMTP
 * hop, and bytes read off a disk that may not be the one the web request used.
 *
 * IDs only in the constructor, models re-loaded in handle(): a worker has no request
 * context, and a serialized model would carry a stale snapshot of a row the send
 * step has just written.
 */
class SendChangeOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> seconds */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $emailLogId,
        public int $changeOrderId,
    ) {}

    /**
     * Intentionally does NOT catch Throwable. Letting it propagate is what lets the
     * queue's retry and backoff apply; a terminal failure is recorded once in
     * failed(), after the retries are exhausted. Catching here would turn a
     * recoverable SMTP blip into a permanently unsent change order.
     */
    public function handle(
        ChangeOrderPdfService $pdf,
        ChangeOrderProofPackageService $proof,
        AttachmentService $attachments,
    ): void {
        $co = ChangeOrder::with([
            'project',
            'generalContractor',
            'sentToGcBy.credential',
            'signatures.signatureAttachment',
        ])->findOrFail($this->changeOrderId);

        // The copy filed by sendToGc(), never a fresh render. What reaches the
        // General Contractor has to be the document the send step produced, or the
        // filed record and the sent mail disagree about what was billed.
        $document = $pdf->storedDocument($co);

        if (! $document || ! Storage::disk($document->disk)->exists($document->file_path)) {
            throw new RuntimeException("No filed document found for change order #{$this->changeOrderId}.");
        }

        $bytes = Storage::disk($document->disk)->get($document->file_path);

        $recipient = $co->gcBlock()['email'] ?? null;

        if (blank($recipient)) {
            throw new RuntimeException("Change order #{$this->changeOrderId} has no general-contractor email.");
        }

        // The change order plus the signed authorization, as one file. Returns the
        // base bytes untouched when there is nothing to append.
        $merged = $proof->merge($co, $bytes);

        Mail::to($recipient)->send(new ChangeOrderIssuedMail(
            changeOrder: $co,
            pdfBytes: $merged,
            pdfFileName: $pdf->fileName($co),
        ));

        // File what ACTUALLY left, but only when it differs from the filed document —
        // otherwise every send would duplicate a copy already on disk. This is the
        // audit trail for a change order that bills for completed work: it records the
        // exact bundle, signature included, that the General Contractor received.
        if ($merged !== $bytes) {
            $attachments->storePdf($merged, $pdf->fileName($co), [
                'attachable_type' => ChangeOrder::class,
                'attachable_id' => $co->id,
                'project_id' => $co->project_id,
                'attachment_type' => 'sent_document',
                'directory' => 'change-order-documents',
                'uploaded_by' => $co->sent_to_gc_by,
                'metadata' => ['emailed_at' => now()->toIso8601String()],
            ]);
        }

        EmailLog::whereKey($this->emailLogId)->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        EmailLog::whereKey($this->emailLogId)->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
        ]);
    }
}
