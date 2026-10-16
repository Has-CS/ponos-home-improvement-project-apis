{{--
  resources/views/pdf/change-order-authorization.blade.php

  The WORK AUTHORIZATION sheet. Rendered by ChangeOrderAuthorizationPdfService,
  which supplies $co (eager-loaded), $signature (the authorising
  ChangeOrderSignature), $company (config('company')), $logoSrc and
  $signatureSrc.

  WHAT THIS DOCUMENT IS, and why it is a separate template rather than a variant
  of pdf/change-order.blade.php:

  An emergency change order now has two moments. On site, a General Contractor's
  representative signs to authorise WORK — and at that point nobody has priced
  anything. Later a PM prices it, and the change order proper goes to the GC
  asking them to agree MONEY. Those are different documents making different
  claims, and one of them states a figure the other must not.

  So this sheet carries NO money. No value band, no total, no payment terms.
  Printing a price here would misrepresent what the signer agreed to, which is
  the very defect this redesign exists to fix.

  It reads the signed_* SNAPSHOT exclusively — never the change order's live
  title/description/scope/location, which the PM may edit afterwards. That is
  what makes this an evidentiary record rather than a view of current data. On a
  grandfathered emergency CO the snapshot is null and the ?? fallbacks below read
  the live columns instead: true of exactly those rows, because assertEditable()
  blocked every edit for their entire lives.

  It travels to the GC merged behind the priced change order, as proof the work
  was requested. It is also downloadable on its own.

  The dompdf workarounds are the ones commented in pdf/change-order.blade.php —
  page margins on <body>, explicit insets on the fixed footer, explicit .last
  instead of :last-child. Each looks like pointless verbosity until you remove it
  and the layout prints to the paper edge.
--}}
@php
    $dash = '—';

    // Snapshot first, live columns as the grandfathered fallback. See above.
    $title = $co->signed_title ?? $co->title;
    $description = $co->signed_description ?? $co->description;
    $scope = $co->signed_scope ?? $co->scope;
    $location = $co->signed_location ?? $co->location;

    $inclusions = \App\Models\PurchaseOrderTerm::splitClauses($co->signed_inclusions ?? $co->inclusions);
    $exclusions = \App\Models\PurchaseOrderTerm::splitClauses($co->signed_exclusions ?? $co->exclusions);

    $gc = $co->gcBlock();

    $name = fn ($u) => $u ? trim("{$u->first_name} {$u->last_name}") : null;
    $capturedByName = $name($signature?->capturedBy) ?: $name($co->originator);

    // device_info is free-form jsonb, so only scalar values print and the keys
    // are dropped — "iOS / iPad 9th gen" reads better than
    // "platform: iOS, model: iPad 9th gen", and a nested structure cannot break
    // the render.
    $device = collect((array) ($signature?->device_info ?? []))
        ->filter(fn ($v) => is_scalar($v) && filled($v))
        ->map(fn ($v) => (string) $v)
        ->implode(' / ');

    // Printed EXACTLY as stored, never rounded: this is evidence for a disputed
    // signature, and decimal(9,6) is the precision the device reported.
    $coords = ($signature && $signature->signed_lat !== null && $signature->signed_lng !== null)
        ? "{$signature->signed_lat}, {$signature->signed_lng}"
        : null;

    $locationValue = $signature ? ($signature->location_note ?: $coords) : null;

    $logoSrc = $logoSrc ?? \App\Support\BrandLogo::dataUri();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Work Authorization {{ $co->change_order_no }}</title>
<style>
/* See pdf/change-order.blade.php — this dompdf build IGNORES @page margins;
   margin on <body> is honoured and repeats on every page. */
@page { size: A4 portrait; margin: 0; }

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

/* The insets restate the body margin deliberately: dompdf positions a fixed box
   against the PAGE box, not the @page content box. */
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

/* MASTHEAD */
.masthead { width: 100%; border-collapse: collapse; }
.masthead td { vertical-align: top; padding: 0; }
.masthead .m-left  { width: 38%; }
.masthead .m-right { width: 62%; text-align: right; }

.logo { height: 20mm; width: auto; }
.logo-fallback { display: inline-block; border-left: 2.5pt solid #AF8D2B; padding: 1mm 0 1mm 3mm; }
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
  text-transform: uppercase; margin: 1mm 0 2.5mm 0; color: #1C1B18;
}
.company-meta { font-size: 8pt; color: #6B665C; line-height: 1.6; }

.rule-accent { height: 1pt; background: #AF8D2B; margin: 3mm 0 0 0; font-size: 0; }

/* TITLE BAND */
.titleband { width: 100%; border-collapse: collapse; margin-top: 4.5mm; }
.titleband > tbody > tr > td { vertical-align: top; padding: 0; }
.titleband .t-left  { width: 52%; padding-right: 8mm !important; }
.titleband .t-right { width: 48%; }

.doc-title {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 19pt; font-weight: normal;
  letter-spacing: 2.2pt; color: #1C1B18;
  line-height: 1.1; margin: 0 0 2.5mm 0;
}
.doc-tagline { font-size: 8pt; color: #6B665C; line-height: 1.5; }

.cobox { width: 100%; border-collapse: collapse;
         border: 0.5pt solid #D9D4C7; border-top: 2pt solid #AF8D2B; }
/* Explicit .last rather than tr:last-child — dompdf's support for structural
   pseudo-classes is unreliable. */
.cobox td { padding: 1.7mm 3mm; border-bottom: 0.5pt solid #E7E3D8; }
.cobox tr.last td { border-bottom: 0; }
.cobox .k {
  font-size: 6.5pt; letter-spacing: 1.1pt; text-transform: uppercase;
  color: #6B665C; white-space: nowrap;
}
.cobox .v { text-align: right; font-size: 9pt; }
.cobox .v-co {
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 13pt; font-weight: bold; letter-spacing: 0.4pt; color: #AF8D2B;
}

.authorized-pill {
  display: inline-block;
  border: 0.75pt solid #2F6B3A; background: #F0F6F1;
  padding: 0.8mm 2.5mm;
  font-size: 7pt; font-weight: bold; letter-spacing: 1pt;
  text-transform: uppercase; color: #2F6B3A;
}

/* ADDRESSEE */
.parties { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.parties td.panel {
  border: 0.5pt solid #D9D4C7; border-left: 2pt solid #AF8D2B;
  padding: 2.5mm 3mm; vertical-align: top;
}
.phead {
  font-size: 6.5pt; letter-spacing: 1.2pt; text-transform: uppercase;
  color: #8A6E1F; margin-bottom: 1.5mm;
}
.pname { font-weight: bold; font-size: 9.5pt; }
.pline { font-size: 8.5pt; color: #3B3830; line-height: 1.5; }
.addressee { width: 100%; border-collapse: collapse; }
.addressee td { padding: 0; vertical-align: top; }
.addressee .ad-left { width: 60%; }
.addressee .ad-right { width: 40%; text-align: right; }
.pcontact { font-size: 8.5pt; color: #3B3830; line-height: 1.5; }

/* REFERENCE STRIP */
.refstrip { width: 100%; border-collapse: collapse; margin-top: 4mm;
            border: 0.5pt solid #E7E3D8; background: #FBFAF7; }
.refstrip td { padding: 2mm 3mm; border-left: 0.5pt solid #E7E3D8; vertical-align: top; }
.refstrip td.first { border-left: 0; }
.refstrip .k {
  display: block; font-size: 6.5pt; letter-spacing: 1pt;
  text-transform: uppercase; color: #6B665C; margin-bottom: 0.6mm;
}
.refstrip .v { display: block; font-size: 8.5pt; }

/* SECTIONS */
.section { margin-top: 6mm; }
.section-flow { page-break-inside: auto; }
.section-head {
  font-size: 7pt; letter-spacing: 1.5pt; text-transform: uppercase;
  color: #8A6E1F; border-bottom: 0.5pt solid #D9D4C7;
  padding-bottom: 1.2mm; margin-bottom: 2.5mm;
}
.co-title { font-size: 11pt; font-weight: bold; margin-bottom: 1.5mm; }
.prose { font-size: 9pt; line-height: 1.55; }
.prose-empty { color: #8A8578; }

.ie-list { margin: 0; padding-left: 6mm; font-size: 9pt; line-height: 1.55; }
.ie-list li { margin-bottom: 1mm; }

/* THE NO-PRICE NOTICE — the one thing on this sheet that must not be missed. An
   authorization mistaken for a priced change order would be worse than no
   authorization at all. */
.nomoney {
  margin-top: 6mm;
  border: 0.5pt solid #D9D4C7; border-left: 2pt solid #6B665C;
  background: #F5F4F1;
  padding: 2.5mm 3mm;
  font-size: 8.5pt; color: #3B3830; line-height: 1.55;
}

/* CAPTURE EVIDENCE */
.onsite { margin-top: 6mm; border: 0.5pt solid #D9D4C7; page-break-inside: avoid; }
.onsite-head {
  background: #FBF7EC; border-bottom: 0.5pt solid #D9D4C7;
  padding: 1.8mm 3mm;
  font-size: 6.5pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #8A6E1F;
}
.onsite-body { padding: 2.5mm 3mm; }
.onsite-grid { width: 100%; border-collapse: collapse; }
.onsite-grid td { padding: 0.9mm 0; vertical-align: top; font-size: 8.5pt; }
.onsite-grid .k {
  width: 30mm; font-size: 6.5pt; letter-spacing: 1pt;
  text-transform: uppercase; color: #6B665C;
}
.onsite-gps { display: block; font-size: 7pt; color: #8A8578; margin-top: 0.8mm; }

/* SIGNATURES — spacer column rather than border-spacing, as on the change
   order. */
.signatures { width: 100%; border-collapse: collapse; margin-top: 10mm;
              page-break-inside: avoid; }
.signatures td.sig { width: 47%; vertical-align: bottom; padding: 0; }
.signatures td.gap { width: 6%; padding: 0; }
.sig-line { border-bottom: 0.5pt solid #1C1B18; height: 16mm; }
/* max-height, not height, so a wide shallow signature keeps its aspect ratio;
   the top margin drops it toward the rule so it reads as signed rather than
   floating above it. */
.sig-img { display: block; max-height: 14mm; max-width: 60mm; margin-top: 2mm; }
.sig-name { font-size: 9pt; font-weight: bold; margin-top: 1.8mm; }
.sig-meta { font-size: 7.5pt; color: #6B665C; line-height: 1.5; }
</style>
</head>
<body>

{{-- ===================== RUNNING FOOTER ===================== --}}
<div class="doc-footer">
  <table>
    <tr>
      <td>
        {{ $company['name'] }}
        &nbsp;&middot;&nbsp; Work authorization for {{ $co->change_order_no }}
        @if($co->project?->code)
          &nbsp;&middot;&nbsp; {{ $co->project->code }}
        @endif
      </td>
    </tr>
  </table>
</div>

{{-- ===================== MASTHEAD ===================== --}}
<table class="masthead">
  <tr>
    <td class="m-left">
      @if($logoSrc)
        <img class="logo" src="{{ $logoSrc }}" alt="{{ $company['name'] }}">
      @else
        <div class="logo-fallback">
          <div class="lf-name">Ponos</div>
          <div class="lf-sub">Home Improvement</div>
        </div>
      @endif
    </td>
    <td class="m-right">
      <div class="company-name">{{ $company['name'] }}</div>
      <div class="company-meta">
        {{ $company['address'] ?? '' }}<br>
        @if(! empty($company['phone'])){{ $company['phone'] }}@endif
        @if(! empty($company['phone']) && ! empty($company['email'])) &nbsp;&middot;&nbsp; @endif
        @if(! empty($company['email'])){{ $company['email'] }}@endif
        @if(! empty($company['website']))<br>{{ $company['website'] }}@endif
      </div>
    </td>
  </tr>
</table>
<div class="rule-accent"></div>

{{-- ===================== TITLE BAND ===================== --}}
<table class="titleband">
  <tr>
    <td class="t-left">
      <div class="doc-title">WORK AUTHORIZATION</div>
      <div class="doc-tagline">
        This sheet records work authorised on site by a representative of the General
        Contractor named below, so that it could proceed without delay. It authorises
        the work described. It does not state or agree a price.
      </div>
    </td>

    <td class="t-right">
      <table class="cobox">
        <tr>
          <td class="k">Reference</td>
          <td class="v v-co">{{ $co->change_order_no }}</td>
        </tr>
        <tr>
          <td class="k">Authorised on</td>
          <td class="v">{{ optional($signature->signed_at ?? $co->work_authorized_at ?? $co->created_at)->format('d M Y H:i') }}</td>
        </tr>
        <tr class="last">
          <td class="k">Status</td>
          <td class="v"><span class="authorized-pill">Signed on site</span></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

{{-- ===================== ADDRESSEE =====================
     The GC whose representative signed. Values come from ChangeOrder::gcBlock(),
     snapshot-first — and on this path the snapshot is frozen at capture, in the
     same transaction that recorded the signature, so it always names the
     counterparty as at the moment of signing. --}}
<table class="parties">
  <tr>
    <td class="panel">
      <div class="phead">Authorised by — General Contractor</div>
      <div class="pbody">
        @if($gc)
          <table class="addressee">
            <tr>
              <td class="ad-left">
                <div class="pname">{{ $gc['name'] }}</div>
                @foreach($gc['lines'] as $line)
                  <div class="pline">{{ $line }}</div>
                @endforeach
              </td>
              <td class="ad-right">
                @if($gc['contact_name'] || $gc['phone'] || $gc['email'])
                  <div class="pcontact">
                    @if($gc['contact_name'])Attn: {{ $gc['contact_name'] }}<br>@endif
                    @if($gc['phone']){{ $gc['phone'] }}<br>@endif
                    @if($gc['email']){{ $gc['email'] }}@endif
                  </div>
                @endif
              </td>
            </tr>
          </table>
        @else
          <div class="pline">{{ $dash }}</div>
        @endif
      </div>
    </td>
  </tr>
</table>

{{-- ===================== REFERENCE STRIP =====================
     Assembled as an array first so empty optional fields collapse out entirely
     rather than leaving blank boxes, and the surviving cells always divide the
     width evenly. --}}
@php
    $refs = array_filter([
        'Project' => $co->project->code ?? null,
        'Cost code' => $co->costCode->code ?? null,
        'Urgency' => $co->urgency->label ?? null,
        'Captured by' => $capturedByName,
        'Signer' => $signature?->signer_name,
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

{{-- ===================== THE WORK AUTHORISED =====================
     Every value below is the SIGNED snapshot. Nothing here moves when the PM
     later edits the change order, which is the entire point of the sheet. --}}
<div class="section">
  <div class="section-head">Work authorised</div>
  <div class="co-title">{{ $title }}</div>

  @if($description)
    <div class="prose">{!! nl2br(e($description)) !!}</div>
  @endif
</div>

<div class="section section-flow">
  <div class="section-head">Scope as signed</div>
  @if($scope)
    <div class="prose">{!! nl2br(e($scope)) !!}</div>
  @else
    <div class="prose"><span class="prose-empty">Not specified.</span></div>
  @endif
</div>

@if($location)
  <div class="section">
    <div class="section-head">Location</div>
    <div class="prose">{{ $location }}</div>
  </div>
@endif

@if(count($inclusions))
<div class="section section-flow">
  <div class="section-head">Inclusions as signed</div>
  <ol class="ie-list">
    @foreach($inclusions as $entry)
      <li>{{ $entry }}</li>
    @endforeach
  </ol>
</div>
@endif

@if(count($exclusions))
<div class="section section-flow">
  <div class="section-head">Exclusions as signed</div>
  <ol class="ie-list">
    @foreach($exclusions as $entry)
      <li>{{ $entry }}</li>
    @endforeach
  </ol>
</div>
@endif

{{-- The sheet has to say plainly that it is not a bill. --}}
<div class="nomoney">
  No price is stated or agreed by this authorization. The cost of the work described
  above is set out in change order {{ $co->change_order_no }}, which is issued
  separately for the General Contractor's written approval.
</div>

{{-- ===================== CAPTURE EVIDENCE =====================
     Where the device was, who held it, and what it was running. Provenance for a
     disputed signature, kept beneath the signature itself rather than leading. --}}
@if($signature)
<div class="onsite">
  <div class="onsite-head">On-site capture record</div>
  <div class="onsite-body">
    <table class="onsite-grid">
      <tr>
        <td class="k">Signed</td>
        <td class="v">{{ optional($signature->signed_at)->format('d M Y H:i') }}</td>
      </tr>
      @if($locationValue)
      <tr>
        <td class="k">Location</td>
        <td class="v">
          {{ $locationValue }}
          {{-- Only a SECOND line when the note already filled the value slot —
               otherwise the coordinates ARE the value and printing them twice
               would be the duplication this block avoids. --}}
          @if($signature->location_note && $coords)
            <span class="onsite-gps">GPS {{ $coords }}</span>
          @endif
        </td>
      </tr>
      @endif
      @if($capturedByName)
      <tr>
        <td class="k">Recorded by</td>
        <td class="v">{{ $capturedByName }}</td>
      </tr>
      @endif
      @if($device)
      <tr>
        <td class="k">Device</td>
        <td class="v">{{ $device }}</td>
      </tr>
      @endif
    </table>
  </div>
</div>
@endif

{{-- ===================== SIGNATURES ===================== --}}
<table class="signatures">
  <tr>
    <td class="sig">
      <div class="sig-line"></div>
      {{-- Same escaping rule as the acceptance block: the name is ESCAPED and the
           blank-line spacer emitted separately, because `{{ $x ?? '&nbsp;' }}`
           would print a literal "&nbsp;" and `{!! !!}` would inject unescaped
           user input into HTML dompdf then renders. --}}
      <div class="sig-name">@if($capturedByName){{ $capturedByName }}@else&nbsp;@endif</div>
      <div class="sig-meta">Recorded on site for and on behalf of {{ $company['name'] }}</div>
      <div class="sig-meta">Date: {{ optional($co->work_authorized_at)->format('d M Y H:i') ?: '____________________' }}</div>
    </td>
    <td class="gap"></td>
    <td class="sig">
      {{-- The captured mark sits INSIDE the rule's box, so it prints on the
           signature line exactly as a wet signature would. --}}
      <div class="sig-line">
        @if($signatureSrc)
          <img class="sig-img" src="{{ $signatureSrc }}" alt="Signature">
        @endif
      </div>

      @if($signature)
        {{-- Every field is real, so nothing is left as a blank rule for someone to
             complete after the fact. The date carries the TIME as well — work is
             authorised at a moment on site, and the hour is part of the record. --}}
        <div class="sig-name">{{ $signature->signer_name }}</div>
        <div class="sig-meta">
          Authorising the work above for and on behalf of
          {{ $signature->signer_company ?: ($gc['name'] ?? 'the General Contractor') }}
        </div>
        <div class="sig-meta">Title: {{ $signature->signer_title ?: '____________________' }}</div>
        <div class="sig-meta">Date: {{ optional($signature->signed_at)->format('d M Y H:i') ?: '____________________' }}</div>
      @else
        {{-- Unreachable in practice: this sheet is only ever filed alongside a
             captured signature. Kept so a direct render cannot fail. --}}
        <div class="sig-name">&nbsp;</div>
        <div class="sig-meta">For and on behalf of {{ $gc['name'] ?? 'the General Contractor' }}</div>
        <div class="sig-meta">Title: ____________________</div>
        <div class="sig-meta">Date: ____________________</div>
      @endif
    </td>
  </tr>
</table>

</body>
</html>
