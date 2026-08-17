<?php

/**
 * Issuing-company identity for generated documents (currently the purchase
 * order PDF).
 *
 * This is the ONE part of the PO document that cannot come from the database:
 * there is no companies table, and these values describe the tenant issuing the
 * order rather than anything about a particular order. Same rationale as
 * config/ponos.php.
 *
 * Everything else on the PDF — vendor, ship-to, project, line items, totals,
 * terms — binds to real columns. See PurchaseOrderPdfService.
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

    'address' => env('COMPANY_ADDRESS', "1420 Larkspur Avenue, Suite 300\nNaperville, IL 60563, United States"),

    'phone' => env('COMPANY_PHONE', '(630) 555-0142'),
    'email' => env('COMPANY_EMAIL', 'purchasing@ponoshome.com'),
    'website' => env('COMPANY_WEBSITE', 'www.ponoshome.com'),

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
