<?php

namespace App\Services\PurchaseOrder;

use App\Models\Attachment;
use App\Models\PurchaseOrder;
use App\Services\Document\DocumentMergeService;

/**
 * Composes ONE vendor PDF: the purchase order, then its supporting files.
 *
 * Vendors ignore emails carrying three separate documents, so the order and its
 * evidence travel as a single file.
 *
 * All of the FPDI/FPDF mechanics now live in DocumentMergeService — they were
 * never purchase-order-specific, and a second caller (the change order's proof
 * package) needed them. What remains here is the only three things that ARE
 * purchase-order-specific: the signature every call site already uses, which
 * attachments to collect, and what the divider page says.
 *
 * The public signature is deliberately unchanged. Both call sites
 * (PurchaseOrderController::pdf and SendPurchaseOrderEmailJob) still inject this
 * class and call merge($po, $bytes), and the composed bytes are identical to
 * what they were before the extraction — including returning $baseBytes
 * untouched when there is nothing to append, which SendPurchaseOrderEmailJob
 * relies on to decide whether a `sent_document` copy is worth filing.
 *
 * This class only ever READS the filed purchase-order document. The copy stored
 * at issue is never modified.
 */
class PurchaseOrderPdfMergeService
{
    public function __construct(private readonly DocumentMergeService $documents) {}

    /**
     * The order followed by every supporting file, as one PDF.
     *
     * Returns `$baseBytes` UNCHANGED when there is nothing to append — not a
     * re-imported copy. A purchase order without attachments must behave exactly
     * as it did before this feature existed, byte for byte.
     */
    public function merge(PurchaseOrder $po, string $baseBytes): string
    {
        return $this->documents->compose(
            $baseBytes,
            $this->supportingAttachments($po),
            $po->po_number.' with attachments',
            'Supporting documents for purchase order '.$po->po_number,
        );
    }

    /**
     * Reject a PDF the free parser cannot read, at UPLOAD time.
     *
     * Retained as a delegate because AttachmentService has always reached for it
     * here. New callers should use DocumentMergeService directly.
     */
    public function assertMergeable(string $pdfBytes): void
    {
        $this->documents->assertMergeable($pdfBytes);
    }

    /** The files a buyer attached, oldest first — the order they were added. */
    public function supportingAttachments(PurchaseOrder $po)
    {
        return Attachment::query()
            ->where('attachable_type', PurchaseOrder::class)
            ->where('attachable_id', $po->id)
            ->where('attachment_type', 'supporting')
            ->orderBy('id')
            ->get();
    }
}
