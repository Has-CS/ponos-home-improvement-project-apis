<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The issuing company's brand mark, resolved from disk and embedded as a
 * base64 data URI for use in generated PDFs.
 *
 * Extracted from PurchaseOrderPdfService when the change-order document became
 * the second generated document to need it. The logo describes the tenant, not
 * any particular document, so it does not belong to either module.
 * PurchaseOrderPdfService::logoDataUri() delegates here and remains the entry
 * point its own Blade template and tests already use.
 *
 * Embedded rather than referenced BY PATH on purpose. Passing dompdf a
 * filesystem path is a standing source of breakage: config paths built with
 * storage_path()/public_path() mix separators on Windows
 * ("...\storage\app/brand/logo.png"), dompdf resolves relative paths against
 * its own chroot rather than the app root, and a path that works on a dev box
 * need not exist on the deploy target. A data URI has none of those failure
 * modes — dompdf decodes it inline, so the image either renders or the file
 * genuinely is not there.
 */
class BrandLogo
{
    /**
     * Where the brand mark is looked for, in order. The configured path wins;
     * the rest are conventional fallbacks so the file works wherever it is
     * dropped without a config change.
     */
    private const FALLBACKS = [
        'brand/ponos-logo.png',
        'brand/ponos-logo.jpg',
        'brand/ponos-logo.svg',
        'brand/logo.png',
    ];

    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];

    /**
     * The brand mark as a base64 data URI, or null when nothing usable is
     * installed.
     *
     * Walks EVERY candidate and returns the first one it can actually embed,
     * rather than locking onto the first file that merely exists. That
     * distinction is the whole point of the fallback list: with a PNG and a JPEG
     * both installed and GD unavailable, an earlier version found the PNG,
     * discovered it could not use it, and gave up — never trying the JPEG
     * sitting right beside it, which needs no GD at all. The result was a
     * documented "fallback" list that could not fall back.
     */
    public static function dataUri(): ?string
    {
        $skippedPng = null;

        foreach (self::candidates() as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = self::MIME[$ext] ?? null;

            if ($mime === null) {
                continue;
            }

            // dompdf renders PNGs through GD (Cpdf::addPngFromFile) to handle the
            // alpha channel, and THROWS outright when the extension is missing —
            // which would take down document generation entirely rather than
            // merely dropping the logo. Skip this candidate and keep looking: a
            // JPEG copy goes through addJpegImage_common(), which needs no GD.
            if ($ext === 'png' && ! extension_loaded('gd')) {
                $skippedPng ??= $path;

                continue;
            }

            $bytes = @file_get_contents($path);

            if ($bytes === false || $bytes === '') {
                continue;
            }

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        // Only worth reporting once nothing else worked. A PNG skipped while a
        // JPEG rendered fine is not a problem anyone needs telling about.
        if ($skippedPng !== null) {
            Log::warning('Document PDF: logo skipped, the GD extension is not enabled and no JPEG fallback was found.', [
                'logo' => $skippedPng,
                'fix' => 'Enable extension=gd in the WEB SERVER php.ini (the CLI one is separate), or install the logo as JPEG alongside the PNG.',
            ]);
        }

        return null;
    }

    /**
     * Every place a brand mark may live, in precedence order. Separators
     * normalised for Windows.
     *
     * @return array<int,string>
     */
    private static function candidates(): array
    {
        $candidates = [];

        if ($configured = config('company.logo_path')) {
            $candidates[] = $configured;
        }

        foreach (self::FALLBACKS as $relative) {
            $candidates[] = public_path($relative);
            $candidates[] = storage_path('app/'.$relative);
        }

        return array_map(
            fn ($candidate) => str_replace('\\', '/', (string) $candidate),
            $candidates,
        );
    }
}
