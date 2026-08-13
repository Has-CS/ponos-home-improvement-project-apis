{{--
  resources/views/pdf/material-request.blade.php

  Rendered by MaterialRequestPdfService, which supplies $mr (eager-loaded),
  $company (config('company')) and $described (the parsed request_text).

  Kept in step with pdf/purchase-order.blade.php on purpose so the two internal
  documents read as a set. That includes the dompdf workarounds discovered while
  building the PO — page margins on <body>, explicit insets on fixed boxes, a
  spacer COLUMN between panels — each commented where it appears, because every
  one of them looks like pointless verbosity until you remove it and the layout
  prints to the paper edge.

  Differences from the PO are all driven by what a material request is:
    - NO money anywhere. A request carries no prices by design, and the roles
      that read it (Foreman, Site Engineer) do not hold view_pricing.
    - The requester's own words print as a numbered list when they sent prose
      instead of catalog lines.
    - No approval chain and no signature block: this is an internal working
      document, and the chain is already on screen in the app.
--}}
@php
    $qty  = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $dash = '—';

    $requesterName = $mr->requester
        ? trim("{$mr->requester->first_name} {$mr->requester->last_name}")
        : null;

    // One request can mix cost codes (schema R-3): show the single code when
    // there is one, otherwise say how many rather than crowding the strip.
    $costCodes = $mr->items->pluck('costCode.code')->filter()->unique()->values();

    // Anything not live, or no longer actionable, must not read as an open request.
    $watermarkStatuses = ['draft', 'rejected', 'returned'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Material Request {{ $mr->request_no }}</title>
<style>
/* PAGE MARGINS — deliberately on <body>, not @page.
   This dompdf build IGNORES @page margins outright: with margin on @page, a
   width:100% table lays out against the full 210mm sheet and prints to the
   paper edge. Margin on <body> is honoured and repeats on every page.
   @page keeps only the sheet size. */
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
   box, not the content box, so left/right:0 would run the rule and text
   edge-to-edge across the paper regardless of the body margin. */
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
.doc-footer .f-right { text-align: right; }
.pageno:after    { content: counter(page);  }
.pagecount:after { content: counter(pages); }

/* MASTHEAD — logo left, company identity right-aligned on the SAME row. */
.masthead { width: 100%; border-collapse: collapse; }
.masthead td { vertical-align: top; padding: 0; }
.masthead .m-left  { width: 38%; }
.masthead .m-right { width: 62%; text-align: right; }

.logo { height: 20mm; width: auto; }
/* Shown when no logo file is present. A quiet typographic mark rather than a
   dashed "LOGO" box, so an un-branded install still prints as a finished
   document. Drop a PNG at config('company.logo_path') to replace it. */
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

/* TITLE BAND — sits BELOW the rule: title left, summary box right. */
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

/* Summary: label/value rows, hairline-separated, gold top edge. */
.pobox { width: 100%; border-collapse: collapse;
         border: 0.5pt solid #D9D4C7; border-top: 2pt solid #AF8D2B; }
/* Explicit .last rather than tr:last-child — dompdf's support for structural
   pseudo-classes is unreliable. */
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
.status-rejected  { border-color: #8A3B2A; color: #8A3B2A; background: #FAF1EF; }
.status-returned  { border-color: #8A3B2A; color: #8A3B2A; background: #FAF1EF; }
.status-approved  { border-color: #2F6B3B; color: #2F6B3B; background: #F1F6F2; }
.status-delivered { border-color: #2F6B3B; color: #2F6B3B; background: #F1F6F2; }

/* Urgency reads as a warning only when it actually is one. */
.urgency-high, .urgency-critical { color: #8A3B2A; font-weight: bold; }

.watermark {
  position: fixed;
  /* Same fixed-position caveat as .doc-footer — inset explicitly. */
  top: 120mm; left: 12mm; right: 12mm;
  text-align: center;
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 60pt; font-weight: bold; letter-spacing: 8pt;
  color: #EDE8DA;
}

/* REFERENCE STRIP — one outlined box, cells divided by hairlines only. */
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

/* REQUESTED DESCRIPTION — the requester's own words, rendered as a list.
   Same table furniture as the line items so the two blocks sit together. */
.described { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.described thead { display: table-header-group; }
.described tr { page-break-inside: avoid; }
.described .colhead th {
  background: #1C1B18; color: #FFFFFF;
  font-size: 6.6pt; letter-spacing: 0.7pt; text-transform: uppercase;
  font-weight: bold; padding: 2.2mm 3mm; text-align: left;
  white-space: nowrap;
}
.described td {
  padding: 2.2mm 3mm;
  border-bottom: 0.5pt solid #E7E3D8;
  vertical-align: top;
}
.described tbody tr.alt td { background: #FBFAF6; }
.described .d-no { width: 8%; text-align: right; font-size: 8pt; color: #6B665C; }
.described .d-head { font-size: 9pt; font-weight: bold; }
.described .d-rest { font-size: 8pt; color: #4A473F; margin-top: 1mm; line-height: 1.45; }
.described .d-intro td { font-size: 8.5pt; color: #4A473F; background: #FBFAF6; }

/* Verbatim fallback: used when the text was never a numbered list. */
.prose-box { border: 0.5pt solid #D9D4C7; padding: 2.6mm 3mm;
             font-size: 8.5pt; color: #3B3931; line-height: 1.5;
             border-top: 0; }

/* Line items */
.items { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.items thead { display: table-header-group; }   /* repeats on every page */
.items tr    { page-break-inside: avoid; }

/* Single dark header row. */
.items .colhead th {
  background: #1C1B18; color: #FFFFFF;
  font-size: 6.6pt; letter-spacing: 0.7pt; text-transform: uppercase;
  font-weight: bold;
  padding: 2.2mm 3mm; text-align: left;
  /* Without this the tracked-out labels ("Cost code") wrap to a second line and
     the header band doubles in height on EVERY page. */
  white-space: nowrap;
}
.items td {
  padding: 2.4mm 3mm;
  border-bottom: 0.5pt solid #E7E3D8;
  vertical-align: top;
}
/* Striping is applied by the loop, not :nth-child — dompdf ignores it. */
.items tbody tr.alt td { background: #FBFAF6; }

/* No price columns: a material request carries no money, so the width the PO
   spends on unit price and amount goes to the description instead. */
.c-no    { width: 6%;  text-align: right; }
.c-desc  { width: 46%; }
.c-trade { width: 17%; }
.c-code  { width: 13%; }
.c-unit  { width: 8%;  text-align: center; }
.c-qty   { width: 10%; text-align: right; }
th.c-no, th.c-qty { text-align: right; }
th.c-unit { text-align: center; }

.num       { font-size: 9pt; }
.row-no    { font-size: 8pt; color: #6B665C; }
.item-name { font-size: 9pt; }
.item-sub  { font-size: 7.5pt; color: #6B665C; margin-top: 1mm; line-height: 1.4; }
.item-sku  { font-size: 7.5pt; color: #8A8578; margin-top: 0.8mm; }
.cell-code { font-size: 8pt; color: #6B665C; }

.empty-row td {
  text-align: center; color: #6B665C; font-style: italic;
  padding: 9mm 0; border-bottom: 0.5pt solid #E7E3D8;
}

.attach-note { margin-top: 2mm; font-size: 7.5pt; color: #6B665C; text-align: right; }

/* Notes */
.notes-box { border: 0.5pt solid #D9D4C7; background: #FBFAF6;
             padding: 2.6mm 3mm; font-size: 8.5pt; margin-top: 5mm;
             page-break-inside: avoid; }
.notes-box h3 { margin: 0 0 1.5mm 0; font-size: 6.5pt; letter-spacing: 1.1pt;
                text-transform: uppercase; color: #6B665C; }
</style>
</head>
<body>

{{-- ===================== RUNNING FOOTER ===================== --}}
<div class="doc-footer">
  <table>
    <tr>
      <td>
        {{ $company['name'] }}
        &nbsp;&middot;&nbsp; {{ $mr->request_no }}
        @if($mr->project?->code)
          &nbsp;&middot;&nbsp; {{ $mr->project->code }}
        @endif
        &nbsp;&middot;&nbsp; Internal document
      </td>
      <td class="f-right">
        This document is computer-generated. &nbsp;
        Page <span class="pageno"></span> of <span class="pagecount"></span>
      </td>
    </tr>
  </table>
</div>

@if(in_array($mr->status->code ?? null, $watermarkStatuses, true))
  <div class="watermark">{{ strtoupper($mr->status->label ?? $mr->status->code) }}</div>
@endif

{{-- ===================== MASTHEAD ===================== --}}
<table class="masthead">
  <tr>
    <td class="m-left">
      @if(!empty($company['logo_path']) && is_file($company['logo_path']))
        <img class="logo" src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}">
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
      <div class="doc-title">MATERIAL REQUEST</div>
      <div class="doc-tagline">
        Internal document. Quantities are as requested from site and carry no pricing.
      </div>
    </td>

    <td class="t-right">
      <table class="pobox">
        <tr>
          <td class="k">Request No.</td>
          <td class="v v-po">{{ $mr->request_no }}</td>
        </tr>
        <tr>
          <td class="k">Raised</td>
          <td class="v">{{ optional($mr->created_at)->format('d M Y') }}</td>
        </tr>
        <tr class="last">
          <td class="k">Status</td>
          <td class="v">
            <span class="status-pill status-{{ $mr->status->code ?? 'draft' }}">
              {{ $mr->status->label ?? 'Draft' }}
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
      <div class="phead">Requested by</div>
      <div class="pbody">
        <div class="pname">{{ $requesterName ?: $dash }}</div>
        @if($mr->requester?->credential?->email)
          <div class="pline">{{ $mr->requester->credential->email }}</div>
        @endif
        <div class="pcontact">
          Raised {{ optional($mr->created_at)->format('d M Y \a\t H:i') }}
          @if($mr->structuredBy)
            <br>Structured by {{ trim("{$mr->structuredBy->first_name} {$mr->structuredBy->last_name}") }}
            @if($mr->structured_at) on {{ $mr->structured_at->format('d M Y') }} @endif
          @endif
        </div>
      </div>
    </td>

    <td class="gap"></td>

    <td class="panel">
      <div class="phead">Project / Site</div>
      <div class="pbody">
        <div class="pname">{{ $mr->project->name ?? $dash }}</div>
        @if($mr->project?->code)
          <div class="pline">{{ $mr->project->code }}</div>
        @endif
        @if($mr->project?->site_address)
          <div class="pline">{!! nl2br(e($mr->project->site_address)) !!}</div>
        @endif
        @if($mr->project?->client)
          <div class="pcontact">Client: {{ $mr->project->client->name }}</div>
        @endif
      </div>
    </td>
  </tr>
</table>

{{-- ===================== REFERENCE STRIP =====================
     Assembled as an array first so empty optional fields collapse out entirely
     instead of leaving blank boxes, and the surviving cells always divide the
     width evenly. --}}
@php
    $refs = array_filter([
        'Project' => $mr->project->code ?? null,
        'Urgency' => $mr->urgency->label ?? null,
        'Needed by' => optional($mr->needed_by_date)->format('d M Y'),
        'Cost code' => $costCodes->isEmpty()
            ? null
            : ($costCodes->count() === 1 ? $costCodes->first() : 'Multiple ('.$costCodes->count().')'),
        'Line items' => (string) $mr->items->count(),
    ]);
@endphp
@if(count($refs))
<table class="refstrip">
  <tr>
    @foreach($refs as $label => $value)
      <td class="{{ $loop->first ? 'first' : '' }}" style="width: {{ round(100 / count($refs), 4) }}%">
        <span class="k">{{ $label }}</span>
        <span class="v {{ $label === 'Urgency' ? 'urgency-'.($mr->urgency->code ?? '') : '' }}">{{ $value }}</span>
      </td>
    @endforeach
  </tr>
</table>
@endif

{{-- ===================== REQUESTED DESCRIPTION =====================
     Present only when the request came in as prose. A field user who cannot
     work the catalog pickers describes what they need instead, and the office
     maps it to catalog lines later — so this text is the source those lines
     were derived from and belongs on the document.

     $described is parsed by MaterialRequestPdfService::describedItems(): a
     DISPLAY transformation only, nothing is written back. When the text was
     never a numbered list it comes back with items empty and the original in
     `intro`, which prints verbatim below. --}}
@if(filled($mr->request_text))
<table class="described">
  <thead>
    <tr class="colhead">
      <th colspan="2">Requested description (as submitted)</th>
    </tr>
  </thead>
  <tbody>
    @if(count($described['items']))
      @if($described['intro'])
        <tr class="d-intro">
          <td colspan="2">{{ $described['intro'] }}</td>
        </tr>
      @endif
      @foreach($described['items'] as $i => $line)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
          <td class="d-no">{{ $line['no'] }}.</td>
          <td>
            <div class="d-head">{{ $line['head'] }}</div>
            @if($line['rest'])
              <div class="d-rest">{{ $line['rest'] }}</div>
            @endif
          </td>
        </tr>
      @endforeach
    @else
      {{-- Not a list: print exactly what was written, line breaks and all. --}}
      <tr>
        <td colspan="2" class="prose-box">{!! nl2br(e($described['intro'] ?? $mr->request_text)) !!}</td>
      </tr>
    @endif
  </tbody>
</table>
@endif

{{-- ===================== LINE ITEMS ===================== --}}
<table class="items">
  <thead>
    <tr class="colhead">
      <th class="c-no">#</th>
      <th class="c-desc">Item &amp; description</th>
      <th class="c-trade">Trade</th>
      <th class="c-code">Cost code</th>
      <th class="c-unit">Unit</th>
      <th class="c-qty">Qty</th>
    </tr>
  </thead>
  <tbody>
    @forelse($mr->items as $i => $item)
      @php
          // catalog_items.name is the canonical label; the line's own
          // description identifies a free-text item, and is the sub-line when
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
          @if($sub || $item->notes)
            <div class="item-sub">
              @if($sub){{ $sub }}@endif
              @if($sub && $item->notes)<br>@endif
              @if($item->notes){{ $item->notes }}@endif
            </div>
          @endif
          @if($sku)<div class="item-sku">SKU {{ $sku }}</div>@endif
        </td>
        <td class="c-trade cell-code">{{ $item->tradeCategory->name ?? $dash }}</td>
        <td class="c-code cell-code">{{ $item->costCode->code ?? $dash }}</td>
        <td class="c-unit">{{ $item->unit->code ?? $dash }}</td>
        <td class="c-qty num">{{ $qty($item->quantity) }}</td>
      </tr>
    @empty
      <tr class="empty-row">
        <td colspan="6">
          @if(filled($mr->request_text))
            No line items yet — see the requested description above.
          @else
            No line items on this request.
          @endif
        </td>
      </tr>
    @endforelse
  </tbody>
</table>

@if($mr->photos->isNotEmpty())
  <div class="attach-note">
    {{ $mr->photos->count() }} photo(s) attached to this request &mdash; view them in the system.
  </div>
@endif

{{-- ===================== NOTES ===================== --}}
@if($mr->notes)
<div class="notes-box">
  <h3>Notes</h3>
  {!! nl2br(e($mr->notes)) !!}
</div>
@endif

</body>
</html>
