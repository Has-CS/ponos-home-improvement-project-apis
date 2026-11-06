<?php

namespace App\Services\MaterialRequest;

use App\Models\MaterialRequest;
use App\Support\BrandLogo;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the material-request document.
 *
 * Render-only, unlike PurchaseOrderPdfService, which also files a copy as an
 * Attachment at issue. The reason is the difference between the two documents:
 * an issued purchase order is what a vendor was sent and must be retrievable
 * byte-for-byte forever, whereas a material request is an internal document that
 * keeps changing right through the approval chain — a stored copy would be stale
 * the moment a reviewer edited a line. So this always prints current data.
 * (AttachmentService::storePdf() is there if a frozen copy at approval is ever
 * wanted.)
 *
 * Every value comes from the request or its relations. The single exception is
 * the company's own identity, which describes the tenant rather than the
 * request and lives in config/company.php — shared with the PO document so the
 * two print as a matching set.
 */
class MaterialRequestPdfService
{
    private const VIEW = 'pdf.material-request';

    /**
     * Relations the template touches. Declared in one place so a template edit
     * cannot silently introduce an N+1 across every line of a long request.
     */
    private const WITH = [
        'status',
        'urgency',
        // credential carries the requester's email, which the masthead prints.
        'requester.credential',
        'structuredBy',
        'project.client',
        'items.catalogItem',
        'items.unit',
        'items.costCode',
        'items.tradeCategory',
        'approvals.approver',
        'approvals.fromStatus',
        'approvals.toStatus',
        'photos',
    ];

    /**
     * Footer geometry, in PDF points — the same values as PurchaseOrderPdfService,
     * because the template's .doc-footer rule is identical to the PO's. They must
     * stay in step with that rule: the page-number string is drawn on the canvas
     * and cannot inherit it.
     */
    private const PAGE_MARGIN_PT = 34.02;      // 12mm, matching body's side margin

    private const FOOTER_TEXT_TOP_PT = 801.6;

    private const FOOTER_FONT_SIZE = 7.0;

    private const FOOTER_COLOR = [0.42, 0.40, 0.36]; // #6B665C

    /** Raw PDF bytes for this material request, rendered from current data. */
    public function render(MaterialRequest $mr): string
    {
        $mr->loadMissing(self::WITH);

        $pdf = Pdf::loadView(self::VIEW, [
            'mr' => $mr,
            'company' => config('company'),
            // Embedded as a data URI, the same way every other document gets
            // its logo — see App\Support\BrandLogo for why never by path.
            'logoSrc' => BrandLogo::dataUri(),
            // Parsed here rather than in the template so the (defensive) regex
            // is unit-testable on its own.
            'described' => $this->describedItems($mr->request_text),
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
     * has finished, so the total rendered as 0 ("Page 2 of 0"). page_text()
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

        // Measured on the SUBSTITUTED string — "{PAGE_NUM}" is ten characters
        // wide and the digit replacing it is one.
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

    public function fileName(MaterialRequest $mr): string
    {
        return str_replace(['/', '\\', ' '], '-', $mr->request_no).'.pdf';
    }

    /**
     * Split the requester's free-text description into a numbered list FOR
     * DISPLAY ONLY. Nothing is written back — material_requests.request_text
     * keeps the foreman's exact words.
     *
     * This input is unstructured: it arrives as one long run-on line, with
     * inconsistent numbering, stray spacing, and items that may not follow the
     * "name - qty - reason" shape at all. The splitter is therefore deliberately
     * conservative, and the guiding rule is that text is never lost:
     *
     *  - A marker is 1-3 digits followed by '.' or ')' AND whitespace, at the
     *    start of the string or after whitespace. Requiring the trailing space
     *    is what keeps decimals intact ("1.5 Tons" never splits); requiring
     *    digits is what keeps prose intact ("...foundation of Block A." and
     *    "No. 4 rebar" never split).
     *  - Anything before the first marker is kept as an intro rather than
     *    discarded.
     *  - Fewer than two markers means this was never a list, so the ORIGINAL
     *    text is returned verbatim instead of a mangled one-item list.
     *
     * @return array{intro: ?string, items: array<int, array{no: string, head: string, rest: ?string}>}
     */
    public function describedItems(?string $text): array
    {
        $original = trim((string) $text);

        if ($original === '') {
            return ['intro' => null, 'items' => []];
        }

        // Collapse whitespace runs so the split behaves identically whether the
        // foreman typed one long line or pressed Enter between items.
        $flat = trim((string) preg_replace('/\s+/u', ' ', $original));

        $parts = preg_split(
            '/(?:^|\s)(\d{1,3})[.)]\s+/u',
            $flat,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return ['intro' => $original, 'items' => []];
        }

        $intro = trim((string) array_shift($parts));

        $items = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $body = trim($parts[$i + 1]);

            // A bare marker with no text behind it carries nothing to show.
            if ($body === '') {
                continue;
            }

            $items[] = ['no' => $parts[$i], ...$this->splitItemHead($body)];
        }

        if (count($items) < 2) {
            return ['intro' => $original, 'items' => []];
        }

        return ['intro' => $intro !== '' ? $intro : null, 'items' => $items];
    }

    /**
     * Break one item into a bold lead line and the remainder.
     *
     * "Portland Cement - 50 Bags - Required for the foundation" reads best as
     * "Portland Cement — 50 Bags" in bold with the reason beneath. Two
     * separators give exactly that; one gives lead + remainder; none prints the
     * whole item as the lead. An oddly-shaped item therefore still prints in
     * full — it simply gets less visual structure.
     *
     * @return array{head: string, rest: ?string}
     */
    private function splitItemHead(string $body): array
    {
        $segments = preg_split('/\s+[-–—]\s+/u', $body, 3) ?: [$body];

        return match (count($segments)) {
            3 => ['head' => $segments[0].' — '.$segments[1], 'rest' => $segments[2]],
            2 => ['head' => $segments[0], 'rest' => $segments[1]],
            default => ['head' => $body, 'rest' => null],
        };
    }
}
