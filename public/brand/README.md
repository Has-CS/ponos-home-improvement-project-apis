# Brand assets for generated documents

Drop the company logo here:

    public/brand/ponos-logo.png

That is all the purchase-order PDF needs — no config change, no cache clear.

## How it is used

`PurchaseOrderPdfService::logoDataUri()` reads the file and embeds it in the
PDF as a base64 data URI. It is **not** referenced by path at render time,
because handing dompdf a filesystem path fails in several ways that are hard to
diagnose: `public_path()` / `storage_path()` produce mixed separators on Windows
(`...\public/brand/logo.png`), dompdf resolves relative paths against its own
chroot rather than the application root, and a path valid on a developer machine
need not exist on the deploy target.

## Where it looks, in order

1. `COMPANY_LOGO_PATH` from `.env`, if set
2. `public/brand/ponos-logo.{png,jpg,svg}`
3. `storage/app/brand/ponos-logo.{png,jpg,svg}`
4. `public/brand/logo.png`

First readable match wins. With none present the masthead falls back to a text
placeholder — the document still renders.

## Sizing

Roughly **600–900 px wide** is plenty; it prints at 20 mm tall. The image is
embedded in every generated PDF, so an oversized source inflates each stored
document. A 2-page order should land near 1.4 MB, most of which is the embedded
DejaVu font set.

PNG with transparency is preferred. SVG works (php-svg-lib ships with dompdf)
but is less predictable for raster-heavy marks.
