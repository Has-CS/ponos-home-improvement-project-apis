<?php

namespace App\Services\PurchaseOrder;

use App\Models\Attachment;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Composes ONE vendor PDF: the purchase order, then its supporting files.
 *
 * Vendors ignore emails carrying three separate documents, so the order and its
 * evidence travel as a single file.
 *
 * Why FPDI and not dompdf: dompdf renders HTML but cannot import an existing
 * PDF, so it can never append a vendor's quote to our order. FPDI imports pages;
 * FPDF (which it extends) draws the divider and places images. Both are pure PHP
 * — no Ghostscript or pdftk to install per environment.
 *
 * The FREE FPDI parser is enough for OUR documents: dompdf writes a classic xref
 * table with no object streams, despite the %PDF-1.7 header. A vendor's PDF is
 * another matter — anything modern or encrypted is rejected at upload by
 * assertMergeable(), with a placeholder page here as a last resort so a bad file
 * can never stop an order reaching its vendor.
 *
 * This class only ever READS the filed purchase-order document. The copy stored
 * at issue is never modified.
 */
class PurchaseOrderPdfMergeService
{
    /** A4 in millimetres, matching PurchaseOrderPdfService::render()'s setPaper('a4'). */
    private const PAGE_WIDTH = 210.0;

    private const PAGE_HEIGHT = 297.0;

    private const MARGIN = 12.0;

    /**
     * The order followed by every supporting file, as one PDF.
     *
     * Returns `$baseBytes` UNCHANGED when there is nothing to append — not a
     * re-imported copy. A purchase order without attachments must behave exactly
     * as it did before this feature existed, byte for byte.
     */
    public function merge(PurchaseOrder $po, string $baseBytes): string
    {
        $attachments = $this->supportingAttachments($po);

        if ($attachments->isEmpty()) {
            return $baseBytes;
        }

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Ponos Home Improvement');
        $pdf->SetTitle($po->po_number.' with attachments');

        $this->appendPdf($pdf, $baseBytes);
        $this->addDividerPage($pdf, $po, $attachments);

        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, $attachment);
        }

        return (string) $pdf->Output('S');
    }

    /**
     * Reject a PDF the free parser cannot read, at UPLOAD time.
     *
     * The alternative is discovering it when the order is being emailed, in a
     * queue worker, with nobody watching.
     */
    public function assertMergeable(string $pdfBytes): void
    {
        try {
            (new Fpdi())->setSourceFile(StreamReader::createByString($pdfBytes));
        } catch (Throwable $e) {
            abort(422, 'This PDF cannot be merged into the purchase order. Re-save it (or print it to PDF) and try again.');
        }
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

    /** Copy every page of a source PDF at its own size, so nothing is rescaled. */
    private function appendPdf(Fpdi $pdf, string $bytes): void
    {
        $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
    }

    /**
     * A page announcing what follows, so attachments never look like part of the
     * order itself and the vendor can see at a glance what they received.
     */
    private function addDividerPage(Fpdi $pdf, PurchaseOrder $po, $attachments): void
    {
        $pdf->AddPage('P', [self::PAGE_WIDTH, self::PAGE_HEIGHT]);

        // Core fonts only — no font files to ship, unlike the order itself which
        // dompdf renders with the brand faces.
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetXY(self::MARGIN, 40);
        $pdf->Cell(0, 12, 'Attachments', 0, 1);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetX(self::MARGIN);
        $pdf->Cell(0, 7, 'Supporting documents for purchase order '.$po->po_number, 0, 1);

        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', '', 10);

        foreach ($attachments as $index => $attachment) {
            $pdf->SetX(self::MARGIN);
            $pdf->Cell(0, 6, ($index + 1).'. '.$this->latin1($attachment->file_name), 0, 1);
        }
    }

    /** One attachment: its pages if a PDF, one scaled page if an image. */
    private function appendAttachment(Fpdi $pdf, Attachment $attachment): void
    {
        try {
            if (! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
                throw new \RuntimeException('The stored file is missing.');
            }

            $bytes = Storage::disk($attachment->disk)->get($attachment->file_path);

            $attachment->mime_type === 'application/pdf'
                ? $this->appendPdf($pdf, $bytes)
                : $this->appendImage($pdf, $attachment, $bytes);
        } catch (Throwable $e) {
            // Never let one unreadable file stop an order reaching its vendor.
            // The page says plainly what is missing, rather than leaving the
            // vendor to notice a gap they cannot see.
            Log::warning('Purchase-order attachment could not be merged.', [
                'attachment_id' => $attachment->id,
                'error' => $e->getMessage(),
            ]);

            $this->addPlaceholderPage($pdf, $attachment);
        }
    }

    /**
     * An image on its own page, scaled to fit inside the margins with its aspect
     * ratio kept, captioned with the file name.
     *
     * Written to a temp file because FPDF::Image() takes a path; passing raw
     * bytes relies on behaviour FPDF does not document.
     */
    private function appendImage(Fpdi $pdf, Attachment $attachment, string $bytes): void
    {
        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false) {
            throw new \RuntimeException('Not a readable image.');
        }

        $pdf->AddPage('P', [self::PAGE_WIDTH, self::PAGE_HEIGHT]);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(self::MARGIN, self::MARGIN);
        $pdf->Cell(0, 6, $this->latin1($attachment->file_name), 0, 1);

        $top = self::MARGIN + 10;
        $maxWidth = self::PAGE_WIDTH - (self::MARGIN * 2);
        $maxHeight = self::PAGE_HEIGHT - $top - self::MARGIN;

        // Fit inside the box on whichever axis is tighter; never enlarge beyond
        // the box, so a small screenshot is not blown up into mush.
        $scale = min($maxWidth / $dimensions[0], $maxHeight / $dimensions[1]);
        $width = $dimensions[0] * $scale;
        $height = $dimensions[1] * $scale;

        $temp = tempnam(sys_get_temp_dir(), 'po-attach-');

        try {
            file_put_contents($temp, $bytes);

            $pdf->Image(
                $temp,
                self::MARGIN + (($maxWidth - $width) / 2),   // centred
                $top,
                $width,
                $height,
                $attachment->mime_type === 'image/png' ? 'PNG' : 'JPG',
            );
        } finally {
            @unlink($temp);
        }
    }

    private function addPlaceholderPage(Fpdi $pdf, Attachment $attachment): void
    {
        $pdf->AddPage('P', [self::PAGE_WIDTH, self::PAGE_HEIGHT]);

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetXY(self::MARGIN, 40);
        $pdf->Cell(0, 8, 'Attachment could not be included', 0, 1);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetX(self::MARGIN);
        $pdf->MultiCell(
            self::PAGE_WIDTH - (self::MARGIN * 2),
            6,
            $this->latin1($attachment->file_name)."\n\nThis file could not be embedded in the document. Please ask for it to be sent separately.",
        );
    }

    /**
     * FPDF's core fonts are Latin-1; a UTF-8 file name would otherwise emit
     * mojibake on the page.
     */
    private function latin1(string $text): string
    {
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
}
