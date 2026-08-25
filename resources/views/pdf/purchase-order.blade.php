{{--
  resources/views/pdf/purchase-order.blade.php

  Rendered by PurchaseOrderPdfService, which supplies $po (eager-loaded) and
  $company (config('company')).

  Adapted from the reference design. Every value binds to a real column; the
  reference's tax, discount, freight, currency, payment-terms and
  amount-in-words blocks are GONE because this schema has no source for them —
  see the mapping in PurchaseOrderPdfService.

  Two blocks deliberately read the PO's own snapshot rather than the live
  related row, so an issued order keeps printing exactly what it was issued
  with:
    - Ship To      -> po.ship_to_* via PurchaseOrder::shipToLines()
    - Terms        -> po.terms_body via PurchaseOrder::termsClauses()
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2, '.', ',');

    // The grand-total callout carries a currency symbol, matching the change
    // order's TOTAL COST box. Deliberately 2 decimals where change-order.blade
    // .php's $usd uses 0: a change order states a rounded adjustment to the
    // contract sum, but a purchase order is a commitment to pay a vendor an
    // exact amount, and "$630" for $630.40 would misstate it.
    $usd = fn ($v) => '$'.number_format((float) $v, 2, '.', ',');
    $qty   = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $dash  = '—';

    // Derived from the line snapshots. total_amount is the stored header value
    // and is authoritative; subtotal exists so the two can be seen to agree.
    $subtotal = $po->items->sum('line_total');
    $grand    = (float) ($po->total_amount ?? $subtotal);

    // One PO can carry lines on several cost codes (schema R-3 carry-through).
    $costCodes = $po->items->pluck('costCode.code')->filter()->unique()->values();

    $shipToLines = $po->shipToLines();
    $termsClauses = $po->termsClauses();

    $issuer = $po->issuedBy;
    $issuerName = $issuer ? trim("{$issuer->first_name} {$issuer->last_name}") : null;

    // Normally supplied by PurchaseOrderPdfService::render(). Resolved here as
    // a fallback so the template still shows the mark when rendered directly
    // (tests, preview harnesses). Null when no logo file is installed, which
    // degrades to the placeholder below rather than a broken image.
    $logoSrc = $logoSrc ?? \App\Services\PurchaseOrder\PurchaseOrderPdfService::logoDataUri();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Purchase Order {{ $po->po_number }}</title>
<style>
/* PAGE MARGINS — deliberately on <body>, not @page.
   This dompdf build IGNORES @page margins outright: with margin on @page, a
   width:100% table lays out against the full 210mm sheet and prints to the
   paper edge (measured: 0.0mm left, 1.5mm right). Margin on <body> is honoured,
   and — verified across a 3-page render — repeats identically on every page.
   @page keeps only the sheet size.
   Switching to US Letter: change `size` here and raise the body bottom margin
   to ~20mm, since Letter is 18mm shorter. Nothing else needs touching; every
   column below is a percentage. */
@page {
  size: A4 portrait;
  margin: 0;
}

* { box-sizing: border-box; }

html { margin: 0; padding: 0; }

body {
  margin: 12mm 12mm 18mm 12mm;
  padding: 0;
  font-family: "DejaVu Sans", Helvetica, Arial, sans-serif;
  font-size: 9pt;
  line-height: 1.45;
  color: #1C1B18;
}

/* Running footer — repeats on every page in dompdf.
   The insets are NOT decorative: dompdf positions a fixed box against the PAGE
   box, not the @page content box, so left/right:0 would run the rule and text
   edge-to-edge across the paper regardless of the 12mm page margin. They must
   restate the margin explicitly. */
.doc-footer {
  position: fixed;
  bottom: 6mm; left: 12mm; right: 12mm;
  height: 9mm;
  padding-top: 2mm;
  border-top: 0.5pt solid #D9D4C7;
  font-size: 7pt;
  color: #6B665C;
}
.doc-footer table { width: 100%; border-collapse: collapse; }
.doc-footer td { vertical-align: top; padding: 0; }
/* NOTE: the right-hand "… Page X of Y" string is NOT here. CSS
   counter(pages) resolves to 0 in dompdf (it is evaluated before pagination
   completes), so it is drawn on the canvas instead — see
   PurchaseOrderPdfService::stampPageNumbers(). Keep this footer's geometry and
   the FOOTER_* constants in that class in step. */

/* MASTHEAD — logo left, company identity right-aligned on the SAME row. */
.masthead { width: 100%; border-collapse: collapse; }
.masthead td { vertical-align: top; padding: 0; }
.masthead .m-left  { width: 38%; }
.masthead .m-right { width: 62%; text-align: right; }

.logo { height: 20mm; width: auto; }
/* Shown when no logo file is present. A quiet typographic mark rather than a
   dashed "LOGO" box, so an un-branded install still prints as a finished
   document. Drop a PNG at config('company.logo_path') to replace it.
   Kept identical to pdf/material-request.blade.php. */
.logo-fallback {
  display: inline-block;
  border-left: 2.5pt solid #AF8D2B;
  padding: 1mm 0 1mm 3mm;
}
.logo-fallback .lf-name {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 15pt; letter-spacing: 1.4pt; text-transform: uppercase;
  line-height: 1.1; color: #1C1B18;
}
.logo-fallback .lf-sub {
  font-size: 6.5pt; letter-spacing: 1.6pt; text-transform: uppercase;
  color: #8A6E1F; margin-top: 1mm;
}
.company-name {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 14pt; font-weight: normal; letter-spacing: 1.6pt;
  text-transform: uppercase;
  margin: 1mm 0 2.5mm 0; color: #1C1B18;
}
.company-meta { font-size: 8pt; color: #6B665C; line-height: 1.6; }

.rule-accent { height: 1pt; background: #AF8D2B; margin: 3mm 0 0 0; font-size: 0; }

/* TITLE BAND — sits BELOW the rule: title left, PO summary box right. */
.titleband { width: 100%; border-collapse: collapse; margin-top: 4.5mm; }
.titleband > tbody > tr > td { vertical-align: top; padding: 0; }
.titleband .t-left  { width: 52%; padding-right: 8mm !important; }
.titleband .t-right { width: 48%; }

.doc-title {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 20pt; font-weight: normal;
  letter-spacing: 2.4pt; color: #1C1B18;
  line-height: 1.1; margin: 0 0 2.5mm 0;
}
.doc-tagline { font-size: 8pt; color: #6B665C; line-height: 1.5; }

/* PO summary: label/value rows, hairline-separated, gold top edge. */
.pobox { width: 100%; border-collapse: collapse;
         border: 0.5pt solid #D9D4C7; border-top: 2pt solid #AF8D2B; }
/* Explicit .last rather than tr:last-child — dompdf's selector support for
   structural pseudo-classes is unreliable. */
.pobox td { padding: 1.7mm 3mm; border-bottom: 0.5pt solid #E7E3D8; }
.pobox tr.last td { border-bottom: 0; }
.pobox .k {
  font-size: 6.5pt; letter-spacing: 1.1pt; text-transform: uppercase;
  color: #6B665C; white-space: nowrap;
}
.pobox .v { text-align: right; font-size: 9pt; }
.pobox .v-po {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 13pt; font-weight: bold; letter-spacing: 0.4pt; color: #AF8D2B;
}

.status-pill {
  display: inline-block;
  border: 0.75pt solid #AF8D2B;
  background: #FBF7EC;
  padding: 0.8mm 2.5mm;
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1pt; text-transform: uppercase;
  color: #8A6E1F;
}
.status-draft     { border-color: #6B665C; color: #6B665C; background: #F5F4F1; }
.status-cancelled { border-color: #8A3B2A; color: #8A3B2A; background: #FAF1EF; }

.watermark {
  /* Same fixed-position caveat as .doc-footer — inset explicitly. */
  position: fixed;
  top: 120mm; left: 12mm; right: 12mm;
  text-align: center;
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 60pt; font-weight: bold; letter-spacing: 8pt;
  color: #EDE8DA;
}

/* REFERENCE STRIP — one outlined box, cells divided by hairlines only
   (no per-cell border, no fill). */
.refstrip { width: 100%; border-collapse: collapse; margin-top: 4mm;
            border: 0.5pt solid #D9D4C7; }
.refstrip td {
  border-left: 0.5pt solid #E7E3D8;
  padding: 2.2mm 3mm;
  vertical-align: top;
}
.refstrip td.first { border-left: 0; }
.refstrip .k {
  font-size: 6.5pt; letter-spacing: 1pt; text-transform: uppercase;
  color: #6B665C; display: block; margin-bottom: 1.2mm;
}
.refstrip .v { font-size: 9pt; }

/* Parties — border on the td so both panels match height exactly.
   A real spacer COLUMN separates them rather than border-spacing plus a
   negative outer margin: that combination pulled the whole table outside the
   page content box in dompdf, which is what put the panels against the paper
   edge. */
.parties { width: 100%; border-collapse: collapse; margin-top: 4mm; }
.parties > tbody > tr > td.panel {
  width: 48%;
  vertical-align: top;
  padding: 0;
  border: 0.5pt solid #D9D4C7;
}
.parties > tbody > tr > td.gap { width: 4%; border: 0; padding: 0; }

/* Tinted header bar carrying the label; white body beneath. */
.parties .phead {
  background: #F0EDE4;
  border-bottom: 0.5pt solid #D9D4C7;
  padding: 2mm 3mm;
  font-size: 6.5pt; letter-spacing: 1.3pt; text-transform: uppercase;
  color: #6B665C; font-weight: bold;
}
.parties .pbody    { padding: 2.6mm 3mm; }
.parties .pname    { font-size: 10.5pt; font-weight: bold; margin-bottom: 1.5mm; }
.parties .pline    { font-size: 8.5pt; color: #3B3931; }
.parties .pcontact { font-size: 8pt; color: #6B665C; margin-top: 2mm; }

/* Line items */
.items { width: 100%; border-collapse: collapse; margin-top: 4.5mm; }
.items thead { display: table-header-group; }   /* repeats on every page */
.items tr    { page-break-inside: avoid; }

/* Single dark header row. The separate dark "caption" bar the earlier version
   carried above this has gone: it duplicated the PO number already printed in
   the title band, and cost ~8mm of vertical space on every page. */
.items .colhead th {
  background: #1C1B18; color: #FFFFFF;
  font-size: 6.6pt; letter-spacing: 0.7pt; text-transform: uppercase;
  font-weight: bold;
  padding: 2.2mm 3mm; text-align: left;
  /* Without this the tracked-out labels ("Unit price", "Cost code") wrap to a
     second line and the header band doubles to ~14mm on EVERY page. */
  white-space: nowrap;
}
.items td {
  padding: 2.4mm 3mm;
  border-bottom: 0.5pt solid #E7E3D8;
  vertical-align: top;
}
/* Striping is applied by the loop, not :nth-child — dompdf ignores it. */
.items tbody tr.alt td { background: #FBFAF6; }

.c-no    { width: 6%;  text-align: right; }
.c-desc  { width: 38%; }
.c-code  { width: 12%; }
.c-unit  { width: 8%;  text-align: center; }
.c-qty   { width: 10%; text-align: right; }
.c-price { width: 12%; text-align: right; }
.c-total { width: 14%; text-align: right; }
/* These MUST out-specify `.items .colhead th`, which sets text-align:left on
   every header cell. The obvious spelling — `th.c-qty { text-align: right }` —
   scores (0,1,1) against that rule's (0,2,1) and silently loses, which is why
   the #, Qty, Unit price and Amount headers sat left above right-aligned
   figures and Unit sat left above centred codes. Naming both ancestor classes
   takes these to (0,3,1) so they win. Data cells never had the problem:
   `.c-qty` is the only rule setting their alignment, so it applied unopposed —
   which is exactly why the header and its column disagreed. Same fix, same
   reason, as pdf/rfq.blade.php. */
.items .colhead th.c-no,
.items .colhead th.c-qty,
.items .colhead th.c-price,
.items .colhead th.c-total { text-align: right; }
.items .colhead th.c-unit  { text-align: center; }

.num       { font-size: 9pt; }
.row-no    { font-size: 8pt; color: #6B665C; }
.item-name { font-size: 9pt; }
.item-sub  { font-size: 7.5pt; color: #6B665C; margin-top: 1mm; line-height: 1.4; }
.item-sku  { font-size: 7.5pt; color: #8A8578; margin-top: 0.8mm; }
.cell-code { font-size: 8pt; color: #6B665C; }
.cell-amt  { font-size: 9pt; font-weight: bold; }

.empty-row td {
  text-align: center; color: #6B665C; font-style: italic;
  padding: 10mm 0; border-bottom: 0.5pt solid #E7E3D8;
}

/* Totals */
.totals-wrap { width: 100%; border-collapse: collapse; margin-top: 4mm;
               page-break-inside: avoid; }
.totals-wrap > tbody > tr > td { vertical-align: top; padding: 0; }
/* 64/36, not 58/42, so the totals column lines up with the items table's money
   columns instead of floating free: the table runs
   no 6 + desc 38 + code 12 + unit 8 = 64%, then qty 10 + price 12 + total 14 =
   36%. The block's left edge now falls exactly on the unit|qty boundary and its
   right edge on the page margin — the same two edges the Amount column already
   uses, which is the alignment a reader's eye expects on a priced document. */
.totals-left  { width: 64%; padding-right: 6mm !important; }
.totals-right { width: 36%; }

.totals { width: 100%; border-collapse: collapse; }
.totals td { padding: 2.2mm 3mm; font-size: 9pt; }
.totals .t-label { text-align: right; color: #4A473F; }
.totals .t-value { text-align: right; width: 45%; }
/* GRAND TOTAL — the framed callout, lifted verbatim from
   change-order.blade.php's .value-box so the two documents state their headline
   figure identically. It replaces the flat solid-gold band this template used to
   carry (and which the change order's own comment records moving away from):
   gold-on-cream reads as a framed callout rather than a printed bar, and serif
   numerals match .v-po so a large figure belongs to the document.

   Palette is entirely existing tokens — the 2.5pt gold top rule matches .pobox
   and the masthead accent, and #8A6E1F on #FBF7EC is the pairing .status-pill
   already uses.

   The .value-tbd variant from the change order is deliberately NOT carried over:
   $grand falls back to the line subtotal and purchase_orders.total_amount
   defaults to 0, so a purchase order has no "to be determined" state to render.

   margin-top is the one addition: in the change order the box is the only thing
   in its cell, whereas here it sits directly beneath the Subtotal line. */
.value-box {
  border: 0.75pt solid #D9D4C7;
  border-top: 2.5pt solid #AF8D2B;
  background: #FBF7EC;
  padding: 3.2mm 3.6mm;
  margin-top: 2mm;
  text-align: right;
  page-break-inside: avoid;
}
/* 6.5pt / 1.2pt tracking matches this template's own small-caps labels
   (.notes-box h3, .terms h3) rather than the change order's 7pt / 1.3pt — the
   callout should sit inside the purchase order's type scale, not import a
   second one. */
.value-box .vb-label {
  display: block;
  font-size: 6.5pt; font-weight: bold;
  letter-spacing: 1.2pt; text-transform: uppercase;
  color: #6B665C;
  margin-bottom: 1.4mm;
}
.value-box .vb-amount {
  display: block;
  font-family: "DejaVu Serif", Georgia, serif;
  /* 17pt, where the change order uses 22pt. There the figure is the ONLY number
     on the sheet and carries its own section; here the items table already
     prints an Amount column, so a 22pt restatement of a figure the reader has
     just seen reads as shouting — and it was sized for a 48% cell, not this
     36% one. */
  font-size: 17pt; font-weight: bold;
  letter-spacing: 0.3pt;
  color: #8A6E1F;
  line-height: 1.1;
}

/* Left accent bar only — no outline, no fill. */
.notes-box { border-left: 2pt solid #AF8D2B; padding: 0.5mm 0 0.5mm 3mm;
             font-size: 8.5pt; color: #3B3931; line-height: 1.5; }
.notes-box h3 { margin: 0 0 1.5mm 0; font-size: 6.5pt; letter-spacing: 1.2pt;
                text-transform: uppercase; color: #6B665C; }

/* Terms + signatures */
.terms { margin-top: 8mm; padding-top: 4mm; border-top: 0.5pt solid #D9D4C7;
         font-size: 7.8pt; color: #4A473F; line-height: 1.6;
         page-break-inside: avoid; }
.terms h3 { margin: 0 0 2.5mm 0; font-size: 6.5pt; letter-spacing: 1.2pt;
            text-transform: uppercase; color: #6B665C; }
.terms ol { margin: 0; padding-left: 5mm; }
.terms li { margin-bottom: 1.4mm; }

/* Spacer column rather than border-spacing + negative margin — see .parties. */
.signatures { width: 100%; border-collapse: collapse;
              margin-top: 12mm; page-break-inside: avoid; }
/* TOP-aligned, not bottom. With `vertical-align: bottom` the two cells' BOTTOMS
   were pinned together, so any difference in content height pushed the taller
   cell's top upward — and the tops are where the signature rules live. The
   left caption ("Authorised by, for and on behalf of Ponos Home Improvement,
   Ltd.") runs to two lines at this width while the vendor's "Accepted by …"
   usually fits one, so the two ruled lines printed at visibly different heights.
   Aligning to the top pins the rules instead, which is the pair that has to
   agree: they are what a person physically signs on. */
.signatures td.sig { width: 47%; vertical-align: top; padding: 0; }
.signatures td.gap { width: 6%; padding: 0; }
.sig-line { border-bottom: 0.5pt solid #1C1B18; height: 16mm; }
.sig-name { font-size: 9pt; font-weight: bold; margin-top: 1.8mm; }
.sig-meta { font-size: 7.5pt; color: #6B665C; line-height: 1.5; }

/* Reserve two lines for the "on behalf of …" caption so the Date lines beneath
   it stay level too — top-alignment alone fixes the rules, but a one-line
   caption on one side would still leave the two dates a line apart.
   7.5pt x 1.5 line-height = 11.25pt per line, so two lines = 22.5pt = 7.9mm.
   min-height, NOT height: a caption that genuinely needs a third line (a very
   long vendor name) then grows and pushes its own date down, rather than
   overflowing on top of it. */
.sig-role { min-height: 8mm; }
</style>
</head>
<body>

{{-- ===================== RUNNING FOOTER ===================== --}}
<div class="doc-footer">
  <table>
    <tr>
      <td>
        {{ $company['name'] }}
        &nbsp;&middot;&nbsp; {{ $po->po_number }}
        @if($po->ship_to_project_code)
          &nbsp;&middot;&nbsp; {{ $po->ship_to_project_code }}
        @endif
      </td>
      {{-- Right-hand cell intentionally empty: the "This document is
           computer-generated. Page X of Y" string is drawn on the canvas so the
           page total is correct. --}}
      <td></td>
    </tr>
  </table>
</div>

{{-- Anything not issued, or no longer valid, must not read as a live order. --}}
@if(in_array($po->status->code ?? null, ['draft', 'cancelled'], true))
  <div class="watermark">{{ strtoupper($po->status->label ?? $po->status->code) }}</div>
@endif

{{-- ===================== MASTHEAD ===================== --}}
<table class="masthead">
  <tr>
    <td class="m-left">
      @if($logoSrc)
        <img class="logo" src="{{ $logoSrc }}" alt="{{ $company['name'] }}">
      @else
        <div class="logo-fallback">
          <div class="lf-name">{{ \Illuminate\Support\Str::of($company['name'])->before(',')->trim() }}</div>
          <div class="lf-sub">Construction Management</div>
        </div>
      @endif
    </td>

    <td class="m-right">
      <div class="company-name">{{ $company['name'] }}</div>
      <div class="company-meta">
        {!! nl2br(e($company['address'])) !!}<br>
        @if(!empty($company['phone'])){{ $company['phone'] }}@endif
        @if(!empty($company['phone']) && !empty($company['email'])) &nbsp;&middot;&nbsp; @endif
        @if(!empty($company['email'])){{ $company['email'] }}@endif
        @if(!empty($company['website']))<br>{{ $company['website'] }}@endif
        @if(!empty($company['tax_id']))<br>{{ $company['tax_id_label'] ?? 'EIN' }} {{ $company['tax_id'] }}@endif
      </div>
    </td>
  </tr>
</table>
<div class="rule-accent"></div>

{{-- ===================== TITLE BAND ===================== --}}
<table class="titleband">
  <tr>
    <td class="t-left">
      <div class="doc-title">PURCHASE ORDER</div>
      <div class="doc-tagline">
        Please quote the purchase order number on all invoices, packing slips and correspondence.
      </div>
    </td>

    <td class="t-right">
      <table class="pobox">
        <tr>
          <td class="k">PO Number</td>
          <td class="v v-po">{{ $po->po_number }}</td>
        </tr>
        <tr>
          <td class="k">PO Date</td>
          {{-- issued_at is null until the PO is issued; created_at always exists. --}}
          <td class="v">{{ optional($po->issued_at ?? $po->created_at)->format('d M Y') }}</td>
        </tr>
        <tr class="last">
          <td class="k">Status</td>
          <td class="v">
            <span class="status-pill status-{{ $po->status->code ?? 'draft' }}">
              {{ $po->status->label ?? 'Draft' }}
            </span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

{{-- ===================== PARTIES ===================== --}}
<table class="parties">
  <tr>
    <td class="panel">
      <div class="phead">Vendor / Supplier</div>
      <div class="pbody">
        <div class="pname">{{ $po->vendor->name }}</div>
        @if($po->vendor->address)
          <div class="pline">{!! nl2br(e($po->vendor->address)) !!}</div>
        @endif
        @if($po->vendor->contact_name || $po->vendor->phone || $po->vendor->email)
          <div class="pcontact">
            @if($po->vendor->contact_name)Attn: {{ $po->vendor->contact_name }}<br>@endif
            @if($po->vendor->phone){{ $po->vendor->phone }}<br>@endif
            @if($po->vendor->email){{ $po->vendor->email }}@endif
          </div>
        @endif
      </div>
    </td>

    <td class="gap"></td>

    <td class="panel">
      <div class="phead">Ship to / Deliver to</div>
      <div class="pbody">
        {{-- The frozen snapshot, already formatted line-by-line by the model, so
             the PDF and the API render the identical block. First line is the
             recipient (a contact name or the site label). --}}
        @if(count($shipToLines))
          <div class="pname">{{ $shipToLines[0] }}</div>
          @foreach(array_slice($shipToLines, 1) as $line)
            <div class="pline">{{ $line }}</div>
          @endforeach
        @else
          <div class="pline">{{ $dash }}</div>
        @endif

        @if($po->ship_to_contact_phone || $po->ship_to_delivery_notes || !empty($company['delivery_hours']))
          <div class="pcontact">
            @if($po->ship_to_contact_phone){{ $po->ship_to_contact_phone }}<br>@endif
            @if($po->ship_to_delivery_notes){{ $po->ship_to_delivery_notes }}<br>@endif
            @if(!empty($company['delivery_hours'])){{ $company['delivery_hours'] }}@endif
          </div>
        @endif
      </div>
    </td>
  </tr>
</table>

{{-- ===================== REFERENCE STRIP =====================
     Assembled as an array first so empty optional fields collapse out
     entirely instead of leaving blank boxes, and the surviving cells always
     divide the width evenly. --}}
@php
    $refs = array_filter([
        'Request no.' => $po->materialRequest->request_no ?? null,
        'Project' => $po->ship_to_project_code ?? $po->project->code ?? null,
        'Cost code' => $costCodes->isEmpty()
            ? null
            : ($costCodes->count() === 1 ? $costCodes->first() : 'Multiple ('.$costCodes->count().')'),
        'Expected delivery' => optional($po->expected_delivery_date)->format('d M Y'),
    ]);
@endphp
@if(count($refs))
<table class="refstrip">
  <tr>
    @foreach($refs as $label => $value)
      <td class="{{ $loop->first ? 'first' : '' }}" style="width: {{ round(100 / count($refs), 4) }}%">
        <span class="k">{{ $label }}</span>
        <span class="v">{{ $value }}</span>
      </td>
    @endforeach
  </tr>
</table>
@endif

{{-- ===================== LINE ITEMS ===================== --}}
<table class="items">
  <thead>
    <tr class="colhead">
      <th class="c-no">#</th>
      <th class="c-desc">Item &amp; description</th>
      <th class="c-code">Cost code</th>
      <th class="c-unit">Unit</th>
      <th class="c-qty">Qty</th>
      <th class="c-price">Unit price</th>
      <th class="c-total">Amount</th>
    </tr>
  </thead>
  <tbody>
    @forelse($po->items as $i => $item)
      @php
          // catalog_items.name is the canonical label; purchase_order_items
          // .description is the free-text override, and also the sub-line when
          // both are present.
          $name = $item->catalogItem->name ?? $item->description ?? $dash;
          $sub  = ($item->catalogItem && $item->description && $item->description !== $name)
              ? $item->description
              : null;
          $sku = optional($item->catalogItem)->sku;
      @endphp
      <tr class="{{ $i % 2 ? 'alt' : '' }}">
        <td class="c-no row-no">{{ $i + 1 }}</td>
        <td class="c-desc">
          <div class="item-name">{{ $name }}</div>
          @if($sub)<div class="item-sub">{{ $sub }}</div>@endif
          @if($sku)<div class="item-sku">SKU {{ $sku }}</div>@endif
        </td>
        <td class="c-code cell-code">{{ $item->costCode->code ?? $dash }}</td>
        <td class="c-unit">{{ $item->unit->code ?? $dash }}</td>
        <td class="c-qty num">{{ $qty($item->quantity_ordered) }}</td>
        <td class="c-price num">{{ $money($item->unit_price) }}</td>
        <td class="c-total cell-amt">{{ $money($item->line_total) }}</td>
      </tr>
    @empty
      <tr class="empty-row">
        <td colspan="7">No line items on this order.</td>
      </tr>
    @endforelse
  </tbody>
</table>

{{-- ===================== TOTALS =====================
     Subtotal and total only. The reference design's tax, discount and freight
     rows are omitted: purchase_orders has no column for any of them, and tax
     was explicitly excluded. --}}
<table class="totals-wrap">
  <tr>
    <td class="totals-left">
      @if($po->notes)
        <div class="notes-box">
          <h3>Notes to vendor</h3>
          {!! nl2br(e($po->notes)) !!}
        </div>
      @endif
    </td>

    <td class="totals-right">
      <table class="totals">
        <tr>
          <td class="t-label">Subtotal</td>
          <td class="t-value">{{ $money($subtotal) }}</td>
        </tr>
      </table>

      {{-- The separator row that used to sit here has gone with the flat band:
           the callout's own 2.5pt gold top rule is the divider now, and keeping
           both would double it. --}}
      <div class="value-box">
        <span class="vb-label">Total</span>
        <span class="vb-amount">{{ $usd($grand) }}</span>
      </div>
    </td>
  </tr>
</table>

{{-- ===================== TERMS =====================
     From the PO's own frozen copy, not the live terms row — revising the
     company's standard terms must never alter an order already issued. --}}
@if(count($termsClauses))
<div class="terms">
  <h3>{{ $po->terms_title ?: 'Terms & conditions' }}</h3>
  <ol>
    @foreach($termsClauses as $clause)
      <li>{{ $clause }}</li>
    @endforeach
  </ol>
</div>
@endif

{{-- ===================== SIGNATURES ===================== --}}
<table class="signatures">
  <tr>
    <td class="sig">
      <div class="sig-line"></div>
      <div class="sig-name">{{ $issuerName ?: '&nbsp;' }}</div>
      <div class="sig-meta sig-role">Authorised by, for and on behalf of {{ $company['name'] }}</div>
      <div class="sig-meta">Date: {{ $po->issued_at?->format('d M Y') ?: '____________________' }}</div>
    </td>
    <td class="gap"></td>
    <td class="sig">
      <div class="sig-line"></div>
      <div class="sig-name">{{ $po->vendor->contact_name ?: '&nbsp;' }}</div>
      <div class="sig-meta sig-role">Accepted by, for and on behalf of {{ $po->vendor->name }}</div>
      <div class="sig-meta">Date: ____________________</div>
    </td>
  </tr>
</table>

</body>
</html>
