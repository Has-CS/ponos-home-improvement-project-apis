<?php

/**
 * Issuing-company identity for generated documents — the purchase order, the
 * material request, the change order and the RFQ all render their masthead
 * from this one array.
 *
 * This is the ONE part of those documents that cannot come from the database:
 * there is no companies table, and these values describe the tenant issuing the
 * document rather than anything about a particular order. Same rationale as
 * config/ponos.php.
 *
 * Everything else on the PDFs — vendor, ship-to, project, line items, totals,
 * terms — binds to real columns. See PurchaseOrderPdfService.
 *
 * Change the address HERE, never in a Blade template: every template reads
 * $company['address'] (see the masthead block in each of the four views), so a
 * value edited here reaches all of them at once. Each field also accepts a
 * per-deployment override through its COMPANY_* environment variable.
 *
 * LOGO: a real file on disk, not a packaged asset. Drop the Ponos mark at
 *
 *     public/brand/ponos-logo.png
 *
 * PurchaseOrderPdfService reads it and embeds it in the document as a base64
 * data URI, so nothing depends on dompdf resolving a filesystem path — the
 * failure mode that kept this from rendering before. storage/app/brand/ is
 * also searched, as are .jpg and .svg, so an existing file need not move.
 * With no file present the masthead degrades to a text placeholder rather than
 * breaking the render.
 */
return [
    'name' => env('COMPANY_NAME', 'Ponos Home Improvement, Ltd.'),

    // Street lines only. The masthead prints the company NAME above this block
    // and the phone/email/website below it, so repeating any of them here would
    // double them up on every document. Newlines become line breaks (nl2br).
    'address' => env('COMPANY_ADDRESS', "24 Grandview Avenue\nCornwall-on-Hudson, NY 12520"),

    'phone' => env('COMPANY_PHONE', '(203) 491-4431'),
    'email' => env('COMPANY_EMAIL', 'purchasing@ponoshome.com'),
    'website' => env('COMPANY_WEBSITE', 'www.ponoshi.com'),

    // Optional. Omitted from the masthead entirely when blank.
    'tax_id' => env('COMPANY_TAX_ID'),
    'tax_id_label' => env('COMPANY_TAX_ID_LABEL', 'EIN'),

    // Optional override. Leave unset and the service searches its standard
    // locations (public/brand/, then storage/app/brand/) for png/jpg/svg.
    'logo_path' => env('COMPANY_LOGO_PATH'),

    // Printed under the authoriser's name on the signature block.
    'authorizer_title' => env('COMPANY_AUTHORIZER_TITLE', 'Purchasing'),

    // Optional line under the ship-to panel, e.g. "Deliveries 07:00-16:00, Mon-Fri".
    'delivery_hours' => env('COMPANY_DELIVERY_HOURS'),
];
