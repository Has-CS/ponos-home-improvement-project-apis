<?php

namespace Tests\Concerns;

/**
 * Reads the decompressed content streams out of a dompdf-generated PDF, for
 * tests that assert on what was drawn (e.g. the canvas-stamped page numbers).
 *
 * Each stream is sliced by its declared /Length, NOT by matching up to
 * "endstream". The regex approach this replaces —
 * ~stream\r?\n(.*?)\r?\nendstream~ — strips a trailing carriage-return byte
 * that belongs to the compressed data whenever the data happens to end in one
 * (roughly 1 page in 256). That page then failed to decompress and read as
 * "unstamped", which made the page-number tests fail at random.
 *
 * dompdf writes /Length as a direct integer, which is all this needs to
 * support; a stream whose length it cannot read is skipped.
 */
trait ReadsPdfStreams
{
    /** @return list<string> every FlateDecode stream that decompressed */
    protected function decodedPdfStreams(string $pdf): array
    {
        // (?<!end): "endstream" ends in the same keyword.
        preg_match_all('~(?<!end)stream\r?\n~', $pdf, $starts, PREG_OFFSET_CAPTURE);

        $decoded = [];

        foreach ($starts[0] as [$marker, $offset]) {
            // The stream's own dictionary sits just before the keyword.
            $dict = substr($pdf, max(0, $offset - 300), min(300, $offset));

            // Possessive \d++ so an indirect "/Length 12 0 R" cannot backtrack
            // into a partial match; those are skipped (dompdf never writes them).
            if (! preg_match_all('~/Length\s+(\d++)(?!\s+\d+\s+R)~', $dict, $len)) {
                continue;
            }

            $data = substr($pdf, $offset + strlen($marker), (int) end($len[1]));
            $plain = @gzuncompress($data);

            if ($plain !== false) {
                $decoded[] = $plain;
            }
        }

        return $decoded;
    }

    /**
     * How many pages carry the canvas-drawn page-number stamp, and the leftmost
     * x it was drawn at. The stamp is the right-hand text run in the footer band.
     *
     * @return array{pages:int, stamped:int, minX:float}
     */
    protected function pageNumberStamps(string $pdf): array
    {
        $stamped = 0;
        $minX = INF;

        foreach ($this->decodedPdfStreams($pdf) as $d) {
            if (! str_contains($d, 'Td')) {
                continue;
            }

            preg_match_all('~([\d.]+)\s+([\d.]+)\s+Td~', $d, $t, PREG_SET_ORDER);

            foreach ($t as $q) {
                if ((float) $q[2] < 50 && (float) $q[1] > 200) {
                    $stamped++;
                    $minX = min($minX, (float) $q[1]);
                    break;
                }
            }
        }

        return [
            'pages' => preg_match_all('~/Type\s*/Page(?![s])~', $pdf),
            'stamped' => $stamped,
            'minX' => $minX,
        ];
    }
}
