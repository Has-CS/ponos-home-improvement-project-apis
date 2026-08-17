<?php

namespace App\Services\PurchaseOrder;

use App\Models\Attachment;
use App\Models\PurchaseOrder;
use App\Services\Attachment\AttachmentService;
use App\Support\BrandLogo;
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

    /**
     * Footer geometry, in PDF points (1mm = 2.8346pt). These mirror the
     * .doc-footer rule in the template — bottom:6mm, height:9mm, padding-top:2mm,
     * font-size:7pt, colour #6B665C — because the page-number string is drawn on
     * the canvas and cannot inherit them. Change them together.
     */
    private const PAGE_MARGIN_PT = 34.02;      // 12mm, matching body's side margin

    // From the page top. Tuned so the canvas-drawn string sits on the same
    // baseline as the HTML footer text opposite it (both measured at y=32.1pt
    // from the page bottom in the rendered output).
    private const FOOTER_TEXT_TOP_PT = 801.6;

    private const FOOTER_FONT_SIZE = 7.0;

    private const FOOTER_COLOR = [0.42, 0.40, 0.36]; // #6B665C

    public function __construct(private readonly AttachmentService $attachments) {}

    /** Raw PDF bytes for this purchase order, rendered from current data. */
    public function render(PurchaseOrder $po): string
    {
        $po->loadMissing(self::WITH);

        // Items are ordered by id on the relation already; the document numbers
        // lines in that same order, so the two can never disagree.
        $pdf = Pdf::loadView(self::VIEW, [
            'po' => $po,
            'company' => config('company'),
            'logoSrc' => self::logoDataUri(),
        ])->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $this->stampPageNumbers($dompdf);

        return (string) $dompdf->output();
    }

    /**
     * Draw "Page X of Y" into the footer of every page.
     *
     * Done on the canvas rather than in the Blade footer because CSS
     * counter(pages) does not work here: dompdf evaluates it before pagination
     * has finished, so the total renders as 0 ("Page 1 of 0") — and inside a
     * :after it does not render at all. page_text() defers substitution of
     * {PAGE_NUM}/{PAGE_COUNT} until the document is closed and the real count
     * is known.
     *
     * The left-hand footer text and the rule above it stay in the template as
     * ordinary HTML; only this one string is drawn here.
     */
    private function stampPageNumbers(\Dompdf\Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();

        $font = $metrics->getFont('DejaVu Sans');

        if ($font === null) {
            return; // Never worth failing a document over a page number.
        }

        $text = 'This document is computer-generated.    Page {PAGE_NUM} of {PAGE_COUNT}';

        // Width has to be measured on the SUBSTITUTED string — "{PAGE_NUM}" is
        // ten characters wide and the digit that replaces it is one, so
        // measuring the raw template would push the text well off the page.
        // The real count is known now that render() has run; using it for both
        // placeholders also covers the widest page number the document reaches.
        $count = (string) $canvas->get_page_count();
        $sample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], [$count, $count], $text);

        $width = $metrics->getTextWidth($sample, $font, self::FOOTER_FONT_SIZE);

        $canvas->page_text(
            $canvas->get_width() - self::PAGE_MARGIN_PT - $width,
            self::FOOTER_TEXT_TOP_PT,
            $text,
            $font,
            self::FOOTER_FONT_SIZE,
            self::FOOTER_COLOR,
        );
    }

    /**
     * The brand mark as a base64 data URI, or null when no file is present.
     *
     * The resolution and embedding logic moved to App\Support\BrandLogo when the
     * change-order document became the second generated document to need it —
     * the logo describes the tenant, not a purchase order. Kept here as the
     * entry point this module's Blade template and tests already call.
     *
     * Static so the Blade view can resolve it for itself when rendered
     * directly (tests, preview harnesses) rather than through render().
     */
    public static function logoDataUri(): ?string
    {
        return BrandLogo::dataUri();
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
