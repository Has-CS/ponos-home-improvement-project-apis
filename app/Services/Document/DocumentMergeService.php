<?php

namespace App\Services\Document;

use App\Models\Attachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Composes ONE PDF: a generated document, then the files that evidence it.
 *
 * Extracted verbatim from PurchaseOrderPdfMergeService, which now delegates
 * here. The mechanics were never purchase-order-specific — only three things
 * were: the method signature, the divider caption, and which attachments to
 * collect. Those stay with each caller; everything below is shared.
 *
 * The extraction was forced by a second caller, the change order, whose proof
 * package appends the on-site work authorization to the change-order sheet. It
 * also fixes a pre-existing leak: assertMergeable() is called by
 * AttachmentService for EVERY uploaded PDF regardless of what it is attached
 * to, so its refusal used to tell someone uploading a change-order file that
 * their PDF "cannot be merged into the purchase order".
 *
 * Why FPDI and not dompdf: dompdf renders HTML but cannot import an existing
 * PDF, so it can never append a third party's document to ours. FPDI imports
 * pages; FPDF (which it extends) draws the divider and places images. Both are
 * pure PHP — no Ghostscript or pdftk to install per environment.
 *
 * The FREE FPDI parser is enough for OUR documents: dompdf writes a classic
 * xref table with no object streams, despite the %PDF-1.7 header. A third
 * party's PDF is another matter — anything modern or encrypted is rejected at
 * upload by assertMergeable(), with a placeholder page here as a last resort so
 * a bad file can never stop a document reaching its recipient.
 *
 * This class only ever READS. The filed copy of a generated document is never
 * modified.
 */
class DocumentMergeService
{
    /** A4 in millimetres, matching the setPaper('a4') used by every PDF service. */
    private const PAGE_WIDTH = 210.0;

    private const PAGE_HEIGHT = 297.0;

    private const MARGIN = 12.0;

    /**
     * The document followed by every attachment, as one PDF.
     *
     * Returns `$baseBytes` UNCHANGED when there is nothing to append — not a
     * re-imported copy. A document without attachments must behave exactly as it
     * did before this feature existed, byte for byte, and callers rely on
     * identity (`$merged !== $bytes`) to decide whether a merged copy is even
     * worth filing.
     *
     * @param  iterable<int,Attachment>&\Countable  $attachments  in the order they should print
     * @param  string  $pdfTitle  the PDF's own Title metadata
     * @param  string  $dividerSubtitle  the line under "Attachments" on the divider page
     */
    public function compose(string $baseBytes, $attachments, string $pdfTitle, string $dividerSubtitle): string
    {
        if (count($attachments) === 0) {
            return $baseBytes;
        }

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('Ponos Home Improvement');
        $pdf->SetTitle($pdfTitle);

        $this->appendPdf($pdf, $baseBytes);
        $this->addDividerPage($pdf, $attachments, $dividerSubtitle);

        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, $attachment);
        }

        return (string) $pdf->Output('S');
    }

    /**
     * Reject a PDF the free parser cannot read, at UPLOAD time.
     *
     * The alternative is discovering it when the document is being emailed, in a
     * queue worker, with nobody watching.
     */
    public function assertMergeable(string $pdfBytes): void
    {
        try {
            (new Fpdi())->setSourceFile(StreamReader::createByString($pdfBytes));
        } catch (Throwable $e) {
            abort(422, 'This PDF cannot be merged into the document. Re-save it (or print it to PDF) and try again.');
        }
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
     * document itself and the recipient can see at a glance what they received.
     *
     * @param  iterable<int,Attachment>  $attachments
     */
    private function addDividerPage(Fpdi $pdf, $attachments, string $subtitle): void
    {
        $pdf->AddPage('P', [self::PAGE_WIDTH, self::PAGE_HEIGHT]);

        // Core fonts only — no font files to ship, unlike the document itself
        // which dompdf renders with the brand faces.
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetXY(self::MARGIN, 40);
        $pdf->Cell(0, 12, 'Attachments', 0, 1);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetX(self::MARGIN);
        $pdf->Cell(0, 7, $subtitle, 0, 1);

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
            // Never let one unreadable file stop a document reaching its
            // recipient. The page says plainly what is missing, rather than
            // leaving them to notice a gap they cannot see.
            Log::warning('Attachment could not be merged into a document.', [
                'attachment_id' => $attachment->id,
                'attachable' => $attachment->attachable_type.'#'.$attachment->attachable_id,
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

        $temp = tempnam(sys_get_temp_dir(), 'doc-attach-');

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
