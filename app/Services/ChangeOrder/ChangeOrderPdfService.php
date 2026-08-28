<?php

namespace App\Services\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Services\Attachment\AttachmentService;
use App\Support\BrandLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the formal change-order document.
 *
 * Deliberately the same shape as PurchaseOrderPdfService, and for the same
 * reason — two modes with different guarantees:
 *
 *  - render()   builds the PDF from the CO's CURRENT data, every time. Used
 *               before the document has been prepared, and prints a watermark
 *               carrying the status so an unapproved change cannot be mistaken
 *               for an authorised one.
 *  - storeFor() renders once and files the result as an Attachment. Called
 *               twice in the normal flow: at prepareDocument(), so the PM has a
 *               filed copy to send the GC, and again at counterSign(), so the
 *               filed copy carries the counter-signature that makes it binding.
 *
 * Filing twice is intentional rather than wasteful. The counter-signature is
 * the point at which the document becomes contractual, and a stored PDF that
 * predates it would show an unsigned signature block. existingDocuments() is
 * soft-deleted on each store, so the superseded copy is retained for audit and
 * exactly one current document is ever live.
 *
 * Every value on the document comes from the CO or its relations. The single
 * exception is the issuing company's own identity, which describes the tenant
 * rather than the change and lives in config/company.php.
 */
class ChangeOrderPdfService
{
    private const VIEW = 'pdf.change-order';

    /**
     * Relations the template touches. Loaded in one place so a template edit
     * cannot silently introduce an N+1.
     *
     * `approvals.*` is deliberately absent: the internal authorization chain no
     * longer prints on the document (it stays in the API and in
     * change_order_approvals), so loading it here would be three joins for
     * nothing on every render.
     *
     * The Payment Terms / Changes / Acceptance text is NOT loaded either — the
     * template reads the frozen snapshot columns on the change order itself,
     * never the related terms row, which is what keeps an issued document fixed.
     */
    private const WITH = [
        'type',
        'status',
        'gcDecision',
        'costCode',
        'urgency',
        'originator',
        'counterSignedBy',
        // The Ponos signature name falls back to whoever performed the
        // prepare_document step when the change order has not been
        // counter-signed yet — see the $authorisedName note in the template.
        'approvals.actor',
        'gcDecisionBy',
        'project.client',
        'generalContractor',
        // Loaded here so a long Scope of Work table cannot introduce an N+1 on
        // the units lookup, one query per printed row.
        'scopeItems.unit',
        'signatures.capturedBy',
        'signatures.signatureAttachment',
    ];

    /**
     * Footer geometry, in PDF points (1mm = 2.8346pt). These mirror the
     * .doc-footer rule in the template, because the page-number string is drawn
     * on the canvas and cannot inherit them. Change them together. Same values
     * as the purchase order so the two documents' footers sit on one baseline.
     */
    private const PAGE_MARGIN_PT = 34.02;      // 12mm, matching body's side margin

    private const FOOTER_TEXT_TOP_PT = 801.6;

    private const FOOTER_FONT_SIZE = 7.0;

    private const FOOTER_COLOR = [0.42, 0.40, 0.36]; // #6B665C

    public function __construct(private readonly AttachmentService $attachments) {}

    /** Raw PDF bytes for this change order, rendered from current data. */
    public function render(ChangeOrder $co): string
    {
        $co->loadMissing(self::WITH);

        $pdf = Pdf::loadView(self::VIEW, [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => BrandLogo::dataUri(),
            // On-site (emergency) signatures only. Keyed by signature id and
            // resolved here, not in the template, because embedding needs the
            // same disk-read + base64 step BrandLogo::dataUri() uses for the
            // logo — dompdf cannot fetch the authenticated download route.
            'signatureImages' => $co->signatures->mapWithKeys(
                fn ($sig) => [$sig->id => $sig->signatureAttachment ? $this->attachments->dataUri($sig->signatureAttachment) : null]
            ),
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
     * has finished, so the total renders as 0 ("Page 1 of 0"). page_text()
     * defers substitution until the document is closed and the real count is
     * known. Mirrors PurchaseOrderPdfService::stampPageNumbers().
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

    public function fileName(ChangeOrder $co): string
    {
        return str_replace(['/', '\\', ' '], '-', $co->change_order_no).'.pdf';
    }

    /**
     * Render and file the document against the change order.
     *
     * Returns the Attachment so the caller can write document_attachment_id and
     * expose a download URL. Any previously filed document is soft-deleted
     * rather than left as a second "current" copy — see the class docblock on
     * why this legitimately runs twice per change order.
     */
    public function storeFor(ChangeOrder $co, ?int $userId = null): Attachment
    {
        $co->loadMissing(self::WITH);

        $this->existingDocuments($co)->each->delete();

        return $this->attachments->storePdf($this->render($co), $this->fileName($co), [
            'attachable_type' => ChangeOrder::class,
            'attachable_id' => $co->id,
            'project_id' => $co->project_id,
            'attachment_type' => 'document',
            'directory' => 'change-orders',
            'uploaded_by' => $userId,
            // Record WHICH signature this copy carries. render() embeds whatever
            // embeddableSignatureId() can resolve, so the two agree by
            // construction, and a later reader can tell whether the filed bytes
            // show the signature without parsing the PDF.
            'metadata' => ['signature_attachment_id' => $this->embeddableSignatureId($co)],
        ]);
    }

    /** The stored document for this CO, if one has been generated. */
    public function storedDocument(ChangeOrder $co): ?Attachment
    {
        return $this->existingDocuments($co)->first();
    }

    /**
     * The filed document to serve, re-filing first when the stored copy cannot
     * be showing the current signature.
     *
     * An emergency change order files its PDF exactly once, inside
     * createEmergency() — prepareDocument()/counterSign() are Normal-flow steps
     * and refuse an already-active CO. So a copy filed while the signature could
     * not be read (a storage fault, a half-finished deploy) stays wrong forever,
     * and every later download serves those same bytes. Healing on read is what
     * makes the signature appear reliably rather than only on COs raised after
     * the environment was fixed.
     *
     * Null means nothing has been filed yet — a Normal CO before
     * prepareDocument() — and the caller should render live instead.
     */
    public function currentDocument(ChangeOrder $co, ?int $userId = null): ?Attachment
    {
        $doc = $this->storedDocument($co);

        if (! $doc) {
            return null;
        }

        return $this->documentIsStale($co, $doc)
            ? $this->storeFor($co, $userId)
            : $doc;
    }

    /**
     * Whether the filed copy can still be trusted to represent the change order.
     *
     * Deliberately NOT a timestamp comparison: createEmergency() writes the
     * signature before it files the document, so a copy that failed to embed the
     * image is NEWER than the signature and a date check would call it current.
     */
    private function documentIsStale(ChangeOrder $co, Attachment $doc): bool
    {
        if (! Storage::disk($doc->disk)->exists($doc->file_path)) {
            return true;
        }

        $embeddable = $this->embeddableSignatureId($co);

        // No signature, or one whose image cannot be read at all: re-filing
        // would produce the same bytes, so the stored copy is as good as it gets.
        // This is what stops a permanently missing signature file turning every
        // download into a fresh render.
        if ($embeddable === null) {
            return false;
        }

        return ($doc->metadata['signature_attachment_id'] ?? null) !== $embeddable;
    }

    /**
     * The id of the signature attachment this CO's document should carry, or
     * null when there is none or its bytes cannot be read.
     */
    private function embeddableSignatureId(ChangeOrder $co): ?int
    {
        $co->loadMissing(['signatures.signatureAttachment']);

        $signature = $co->signatures->first();

        if (! $signature?->signatureAttachment) {
            return null;
        }

        return $this->attachments->dataUri($signature->signatureAttachment) === null
            ? null
            : (int) $signature->signature_attachment_id;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Attachment> */
    private function existingDocuments(ChangeOrder $co)
    {
        return Attachment::query()
            ->where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $co->id)
            ->where('attachment_type', 'document')
            ->orderByDesc('id')
            ->get();
    }
}
