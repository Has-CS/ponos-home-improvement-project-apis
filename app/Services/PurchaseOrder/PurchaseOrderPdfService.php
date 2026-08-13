<?php

namespace App\Services\PurchaseOrder;

use App\Models\Attachment;
use App\Models\PurchaseOrder;
use App\Services\Attachment\AttachmentService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the purchase-order document.
 *
 * Two modes, and the difference matters:
 *
 *  - render()  builds the PDF from the PO's CURRENT data, every time. Used for
 *              drafts, which are still being edited and print with a DRAFT
 *              watermark.
 *  - storeFor() renders once and files the result as an Attachment. Called from
 *              PurchaseOrderService::issue(), so the stored document is the
 *              order exactly as issued — matching the ship-to and terms
 *              snapshots frozen at that same moment.
 *
 * Every value on the document comes from the PO or its relations. The single
 * exception is the issuing company's own identity (name, address, logo), which
 * describes the tenant rather than the order and lives in config/company.php.
 */
class PurchaseOrderPdfService
{
    private const VIEW = 'pdf.purchase-order';

    private const DISK = 'local';

    /**
     * Relations the template touches. Loaded in one place so a template edit
     * cannot silently introduce an N+1 across every line of a large order.
     */
    private const WITH = [
        'vendor',
        'project',
        'status',
        'issuedBy',
        'materialRequest',
        'items.unit',
        'items.catalogItem',
        'items.costCode',
    ];

    public function __construct(private readonly AttachmentService $attachments) {}

    /** Raw PDF bytes for this purchase order, rendered from current data. */
    public function render(PurchaseOrder $po): string
    {
        $po->loadMissing(self::WITH);

        // Items are ordered by id on the relation already; the document numbers
        // lines in that same order, so the two can never disagree.
        return Pdf::loadView(self::VIEW, [
            'po' => $po,
            'company' => config('company'),
        ])->setPaper('a4')->output();
    }

    public function fileName(PurchaseOrder $po): string
    {
        return str_replace(['/', '\\', ' '], '-', $po->po_number).'.pdf';
    }

    /**
     * Render and file the document against the purchase order.
     *
     * Called at issue, which is the moment the order becomes real and its
     * ship-to and terms snapshots stop moving. Returns the Attachment so the
     * caller can expose its download URL.
     *
     * Idempotent by replacement: issuing is a one-way transition
     * (PurchaseOrderService::issue() rejects anything but a draft), so this
     * runs once per PO in practice — but if it is ever re-run, the prior
     * document is soft-deleted rather than left as a second "current" copy.
     */
    public function storeFor(PurchaseOrder $po, ?int $userId = null): Attachment
    {
        $po->loadMissing(self::WITH);

        $this->existingDocuments($po)->each->delete();

        return $this->attachments->storePdf($this->render($po), $this->fileName($po), [
            'attachable_type' => PurchaseOrder::class,
            'attachable_id' => $po->id,
            'project_id' => $po->project_id,
            'attachment_type' => 'document',
            'directory' => 'purchase-orders',
            'uploaded_by' => $userId,
        ]);
    }

    /** The stored document for this PO, if one has been generated. */
    public function storedDocument(PurchaseOrder $po): ?Attachment
    {
        return $this->existingDocuments($po)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Attachment> */
    private function existingDocuments(PurchaseOrder $po)
    {
        return Attachment::query()
            ->where('attachable_type', PurchaseOrder::class)
            ->where('attachable_id', $po->id)
            ->where('attachment_type', 'document')
            ->orderByDesc('id')
            ->get();
    }
}
