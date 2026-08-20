<?php

namespace App\Services\Rfq;

use App\Models\Attachment;
use App\Models\Rfq;
use App\Services\Attachment\AttachmentService;
use App\Support\BrandLogo;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the Request for Quotation document.
 *
 * Two modes, same split as PurchaseOrderPdfService and for the same reason:
 *
 *  - render()   builds the PDF from the RFQ's CURRENT data, every time. Used
 *               while the list is still being built, printing with a DRAFT
 *               watermark.
 *  - storeFor() renders once and files the result as an Attachment. Called
 *               from RfqService::submit(), so the stored document is exactly
 *               what was emailed to the vendor and can be retrieved
 *               byte-for-byte afterwards — unlike the material-request
 *               document, an RFQ does not keep changing once it is sent.
 */
class RfqPdfService
{
    private const VIEW = 'pdf.rfq';

    /**
     * Relations the template touches. Loaded in one place so a template edit
     * cannot silently introduce an N+1 across every line of a large RFQ.
     */
    private const WITH = [
        'vendor',
        'project',
        'status',
        'items.unit',
        'items.catalogItem',
        'items.tradeCategory',
    ];

    /** Footer geometry, in PDF points — see PurchaseOrderPdfService for why these must stay in step with the template's .doc-footer rule. */
    private const PAGE_MARGIN_PT = 34.02;

    private const FOOTER_TEXT_TOP_PT = 801.6;

    private const FOOTER_FONT_SIZE = 7.0;

    private const FOOTER_COLOR = [0.42, 0.40, 0.36]; // #6B665C

    public function __construct(private readonly AttachmentService $attachments) {}

    /** Raw PDF bytes for this RFQ, rendered from current data. */
    public function render(Rfq $rfq): string
    {
        $rfq->loadMissing(self::WITH);

        $pdf = Pdf::loadView(self::VIEW, [
            'rfq' => $rfq,
            'company' => config('company'),
            'logoSrc' => self::logoDataUri(),
        ])->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $this->stampPageNumbers($dompdf);

        return (string) $dompdf->output();
    }

    /**
     * Draw "Page X of Y" into the footer of every page. See
     * PurchaseOrderPdfService::stampPageNumbers() for why this is done on the
     * canvas rather than in the Blade footer (CSS counter(pages) is 0 here).
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

    /** The brand mark as a base64 data URI, or null when no file is present. */
    public static function logoDataUri(): ?string
    {
        return BrandLogo::dataUri();
    }

    public function fileName(Rfq $rfq): string
    {
        return str_replace(['/', '\\', ' '], '-', $rfq->rfq_no).'.pdf';
    }

    /**
     * Render and file the document against the RFQ.
     *
     * Called at submit, which is the moment the RFQ becomes a real request
     * sent to a vendor. Returns the Attachment so the caller (RfqService, and
     * the mail job reading it back) can retrieve the exact bytes emailed.
     *
     * Idempotent by replacement: submitting is a one-way transition
     * (RfqService::submit() rejects anything but a draft), so this runs once
     * per RFQ in practice — but if it is ever re-run, the prior document is
     * soft-deleted rather than left as a second "current" copy.
     */
    public function storeFor(Rfq $rfq, ?int $userId = null): Attachment
    {
        $rfq->loadMissing(self::WITH);

        $this->existingDocuments($rfq)->each->delete();

        return $this->attachments->storePdf($this->render($rfq), $this->fileName($rfq), [
            'attachable_type' => Rfq::class,
            'attachable_id' => $rfq->id,
            'project_id' => $rfq->project_id,
            'attachment_type' => 'document',
            'directory' => 'rfqs',
            'uploaded_by' => $userId,
        ]);
    }

    /** The stored document for this RFQ, if one has been generated. */
    public function storedDocument(Rfq $rfq): ?Attachment
    {
        return $this->existingDocuments($rfq)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Attachment> */
    private function existingDocuments(Rfq $rfq)
    {
        return Attachment::query()
            ->where('attachable_type', Rfq::class)
            ->where('attachable_id', $rfq->id)
            ->where('attachment_type', 'document')
            ->orderByDesc('id')
            ->get();
    }
}
