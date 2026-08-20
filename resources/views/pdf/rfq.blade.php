{{--
  resources/views/pdf/rfq.blade.php

  Rendered by RfqPdfService, which supplies $rfq (eager-loaded), $company
  (config('company')) and $logoSrc.

  Kept in step with pdf/purchase-order.blade.php and pdf/material-request.blade.php
  on purpose so the app's generated documents read as a set — same dompdf
  workarounds (body margins, fixed-box insets, spacer columns), each commented
  where it appears.

  Differences from both, driven by what an RFQ is:
    - Addressed TO a vendor (like a PO's "Vendor / Supplier" panel), not an
      internal report — this document leaves the building.
    - NO money anywhere: the whole point is to ASK for pricing, not state it.
    - No approval chain, no signatures: a single actor authors and sends this,
      with nothing to route between reviewers.
--}}
@php
    $qty  = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $dash = '—';

    $creatorName = $rfq->creator
        ? trim("{$rfq->creator->first_name} {$rfq->creator->last_name}")
        : null;

    // Only 'draft' needs a watermark — 'sent' is the live, final state this
    // document is meant to represent once it has actually gone to the vendor.
    $watermarkStatuses = ['draft'];

    $logoSrc = $logoSrc ?? \App\Services\Rfq\RfqPdfService::logoDataUri();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RFQ {{ $rfq->rfq_no }}</title>
<style>
/* PAGE MARGINS — deliberately on <body>, not @page. See purchase-order.blade.php
   for the full explanation; this dompdf build ignores @page margins outright. */
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

.masthead { width: 100%; border-collapse: collapse; }
.masthead td { vertical-align: top; padding: 0; }
.masthead .m-left  { width: 38%; }
.masthead .m-right { width: 62%; text-align: right; }

.logo { height: 20mm; width: auto; }
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

.pobox { width: 100%; border-collapse: collapse;
         border: 0.5pt solid #D9D4C7; border-top: 2pt solid #AF8D2B; }
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
.status-draft { border-color: #6B665C; color: #6B665C; background: #F5F4F1; }
.status-sent  { border-color: #2F6B3B; color: #2F6B3B; background: #F1F6F2; }

.watermark {
  position: fixed;
  top: 120mm; left: 12mm; right: 12mm;
  text-align: center;
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 60pt; font-weight: bold; letter-spacing: 8pt;
  color: #EDE8DA;
}

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

.parties { width: 100%; border-collapse: collapse; margin-top: 4mm; }
.parties > tbody > tr > td.panel {
  width: 48%;
  vertical-align: top;
  padding: 0;
  border: 0.5pt solid #D9D4C7;
}
.parties > tbody > tr > td.gap { width: 4%; border: 0; padding: 0; }

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

.items { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.items thead { display: table-header-group; }
.items tr    { page-break-inside: avoid; }

.items .colhead th {
  background: #1C1B18; color: #FFFFFF;
  font-size: 6.6pt; letter-spacing: 0.7pt; text-transform: uppercase;
  font-weight: bold;
  padding: 2.2mm 3mm; text-align: left;
  white-space: nowrap;
}
.items td {
  padding: 2.4mm 3mm;
  border-bottom: 0.5pt solid #E7E3D8;
  vertical-align: top;
}
.items tbody tr.alt td { background: #FBFAF6; }

.c-no    { width: 7%;  text-align: right; }
.c-desc  { width: 43%; }
.c-unit  { width: 10%; text-align: center; }
.c-qty   { width: 12%; text-align: right; }
.c-notes { width: 28%; }
th.c-no, th.c-qty { text-align: right; }
th.c-unit { text-align: center; }

.num       { font-size: 9pt; }
.row-no    { font-size: 8pt; color: #6B665C; }
.item-name { font-size: 9pt; }
.item-sub  { font-size: 7.5pt; color: #6B665C; margin-top: 1mm; line-height: 1.4; }
.item-sku  { font-size: 7.5pt; color: #8A8578; margin-top: 0.8mm; }
.cell-notes { font-size: 8pt; color: #4A473F; }

.empty-row td {
  text-align: center; color: #6B665C; font-style: italic;
  padding: 9mm 0; border-bottom: 0.5pt solid #E7E3D8;
}

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
        &nbsp;&middot;&nbsp; {{ $rfq->rfq_no }}
        &nbsp;&middot;&nbsp; Request for Quotation
      </td>
      <td class="f-right">
        This document is computer-generated. &nbsp;
        Page <span class="pageno"></span> of <span class="pagecount"></span>
      </td>
    </tr>
  </table>
</div>

@if(in_array($rfq->status->code ?? null, $watermarkStatuses, true))
  <div class="watermark">{{ strtoupper($rfq->status->label ?? $rfq->status->code) }}</div>
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
      </div>
    </td>
  </tr>
</table>
<div class="rule-accent"></div>

{{-- ===================== TITLE BAND ===================== --}}
<table class="titleband">
  <tr>
    <td class="t-left">
      <div class="doc-title">REQUEST FOR QUOTATION</div>
      <div class="doc-tagline">
        Please provide your best pricing for the items listed below.
      </div>
    </td>

    <td class="t-right">
      <table class="pobox">
        <tr>
          <td class="k">RFQ No.</td>
          <td class="v v-po">{{ $rfq->rfq_no }}</td>
        </tr>
        <tr>
          <td class="k">Date</td>
          <td class="v">{{ optional($rfq->sent_at ?? $rfq->created_at)->format('d M Y') }}</td>
        </tr>
        <tr class="last">
          <td class="k">Status</td>
          <td class="v">
            <span class="status-pill status-{{ $rfq->status->code ?? 'draft' }}">
              {{ $rfq->status->label ?? 'Draft' }}
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
      <div class="phead">To</div>
      <div class="pbody">
        <div class="pname">{{ $rfq->vendor->name ?? $dash }}</div>
        @if($rfq->vendor?->address)
          <div class="pline">{!! nl2br(e($rfq->vendor->address)) !!}</div>
        @endif
        @if($rfq->vendor?->contact_name || $rfq->vendor?->phone || $rfq->vendor?->email)
          <div class="pcontact">
            @if($rfq->vendor->contact_name)Attn: {{ $rfq->vendor->contact_name }}<br>@endif
            @if($rfq->vendor->phone){{ $rfq->vendor->phone }}<br>@endif
            @if($rfq->vendor->email){{ $rfq->vendor->email }}@endif
          </div>
        @endif
      </div>
    </td>

    <td class="gap"></td>

    <td class="panel">
      <div class="phead">Requested by</div>
      <div class="pbody">
        <div class="pname">{{ $creatorName ?: $dash }}</div>
        <div class="pline">{{ $company['name'] }}</div>
        @if($rfq->project)
          <div class="pcontact">Project: {{ $rfq->project->name }}
            @if($rfq->project->code) ({{ $rfq->project->code }})@endif
          </div>
        @endif
      </div>
    </td>
  </tr>
</table>

{{-- ===================== REFERENCE STRIP ===================== --}}
@php
    $refs = array_filter([
        'Project' => $rfq->project->code ?? null,
        'Due by' => optional($rfq->due_date)->format('d M Y'),
        'Line items' => (string) $rfq->items->count(),
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
      <th class="c-unit">Unit</th>
      <th class="c-qty">Qty</th>
      <th class="c-notes">Notes / specs</th>
    </tr>
  </thead>
  <tbody>
    @forelse($rfq->items as $i => $item)
      @php
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
        <td class="c-unit">{{ $item->unit->code ?? $dash }}</td>
        <td class="c-qty num">{{ $qty($item->quantity) }}</td>
        <td class="c-notes cell-notes">{{ $item->notes ?? $dash }}</td>
      </tr>
    @empty
      <tr class="empty-row">
        <td colspan="5">No line items on this RFQ.</td>
      </tr>
    @endforelse
  </tbody>
</table>

{{-- ===================== NOTES ===================== --}}
@if($rfq->notes)
<div class="notes-box">
  <h3>Notes</h3>
  {!! nl2br(e($rfq->notes)) !!}
</div>
@endif

</body>
</html>
