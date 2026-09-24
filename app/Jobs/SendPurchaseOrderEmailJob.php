<?php

namespace App\Jobs;

use App\Mail\PurchaseOrder\PurchaseOrderIssuedMail;
use App\Models\EmailLog;
use App\Models\PurchaseOrder;
use App\Services\Attachment\AttachmentService;
use App\Services\PurchaseOrder\PurchaseOrderPdfMergeService;
use App\Services\PurchaseOrder\PurchaseOrderPdfService;
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
 * Deliver an issued purchase order to its vendor, with the filed PDF attached.
 * Mirrors SendRfqEmailJob — same retry/backoff, same EmailLog bookkeeping.
 */
class SendPurchaseOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $emailLogId,
        public int $purchaseOrderId,
    ) {}

    /**
     * Intentionally does not catch Throwable — letting it propagate is what
     * lets Laravel's queue retry/backoff apply. Only failed() below records a
     * terminal failure once retries are exhausted.
     */
    public function handle(
        PurchaseOrderPdfService $pdf,
        PurchaseOrderPdfMergeService $merge,
        AttachmentService $attachments,
    ): void {
        // issuedBy.credential is required by the mail's Reply-To and sign-off.
        // Loaded HERE because the job runs in a worker process with no request
        // context — a missing relation would come back null and silently drop
        // the reply-to.
        $po = PurchaseOrder::with(['vendor', 'issuedBy.credential'])->findOrFail($this->purchaseOrderId);

        // The copy filed at issue, never a fresh render: re-rendering here could
        // pick up a later terms revision or a renamed project and send the
        // vendor something other than the order that was issued.
        $document = $pdf->storedDocument($po);

        if (! $document || ! Storage::disk($document->disk)->exists($document->file_path)) {
            throw new RuntimeException("No filed document found for purchase order #{$this->purchaseOrderId}.");
        }

        $bytes = Storage::disk($document->disk)->get($document->file_path);

        // ONE file for the vendor: the order, then its supporting paperwork.
        // With nothing attached this returns the filed bytes untouched, so an
        // order without attachments is emailed exactly as it was before.
        $merged = $merge->merge($po, $bytes);

        Mail::to($po->vendor->email)->send(new PurchaseOrderIssuedMail(
            purchaseOrder: $po,
            pdfBytes: $merged,
            pdfFileName: $pdf->fileName($po),
        ));

        // File what actually left, but only when it differs from the document
        // already on record — otherwise every send would store a duplicate of
        // the order. Attachments can change afterwards; this copy cannot.
        if ($merged !== $bytes) {
            $attachments->storePdf($merged, $pdf->fileName($po), [
                'attachable_type' => PurchaseOrder::class,
                'attachable_id' => $po->id,
                'project_id' => $po->project_id,
                'attachment_type' => 'sent_document',
                'directory' => 'purchase-order-documents',
                'uploaded_by' => $po->issued_by,
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
