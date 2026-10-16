<?php

namespace App\Services\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Services\Document\DocumentMergeService;
use Illuminate\Support\Facades\Storage;

/**
 * Composes the proof package a General Contractor receives: the change order, then
 * the evidence that the work behind it was requested.
 *
 * ---- why this exists as its own class ----
 *
 * The mechanics are DocumentMergeService's, extracted from the purchase order's
 * merge for exactly this second caller. What is change-order-specific is the part
 * that cannot be shared, and it is not trivial:
 *
 *  - ORDER IS SEMANTIC, not chronological. The purchase order appends supporting
 *    files oldest-first, because for a vendor "the order they were added" is as good
 *    a sequence as any. Here the Work Authorization must come FIRST among the
 *    attachments, directly behind the change order it justifies. A photo appearing
 *    before the signed authorization would bury the one page that makes the change
 *    order collectible.
 *  - The divider caption names a change order, not a purchase order.
 *
 * ---- what goes in ----
 *
 * 1. the change-order document (the base — the sheet that states the price)
 * 2. the Work Authorization sheet, signed on site
 * 3. any supporting files attached to the change order
 *
 * Emergency change orders are the reason this class exists, but nothing here is
 * emergency-only: a Normal change order simply has no authorization to append, and
 * with no attachments at all DocumentMergeService returns the base bytes unchanged,
 * byte for byte.
 */
class ChangeOrderProofPackageService
{
    public function __construct(
        private readonly DocumentMergeService $documents,
        private readonly ChangeOrderAuthorizationPdfService $authorizations,
    ) {}

    /**
     * The change order followed by its proof, as one PDF.
     *
     * Returns `$baseBytes` UNCHANGED when there is nothing to append — which is what
     * lets the caller decide, by identity, whether a merged copy is even worth
     * filing. Same contract as PurchaseOrderPdfMergeService::merge().
     */
    public function merge(ChangeOrder $co, string $baseBytes): string
    {
        return $this->documents->compose(
            $baseBytes,
            $this->proofAttachments($co),
            $co->change_order_no.' with authorization',
            'Authorization and supporting documents for change order '.$co->change_order_no,
        );
    }

    /**
     * The attachments to append, in PRINT order: authorization first, then anything
     * else oldest-first.
     *
     * currentAuthorization() rather than the stored row directly, so a sheet filed
     * while the signature image could not be read is re-filed before it is merged.
     * A proof package whose one job is to carry a signature must not go out without
     * it because of a transient storage fault.
     *
     * @return \Illuminate\Support\Collection<int,Attachment>
     */
    public function proofAttachments(ChangeOrder $co): \Illuminate\Support\Collection
    {
        $authorization = $this->authorizations->currentAuthorization($co);

        $supporting = Attachment::query()
            ->where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $co->id)
            ->where('attachment_type', 'supporting')
            ->orderBy('id')
            ->get();

        return collect($authorization ? [$authorization] : [])
            ->concat($supporting)
            // A row whose bytes have gone from disk would only produce a
            // placeholder page. DocumentMergeService handles that gracefully, but
            // there is no reason to announce a document on the divider page and then
            // not deliver it.
            ->filter(fn (Attachment $a) => Storage::disk($a->disk)->exists($a->file_path))
            ->values();
    }
}
