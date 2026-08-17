{{--
  resources/views/pdf/change-order.blade.php

  Rendered by ChangeOrderPdfService, which supplies $co (eager-loaded),
  $company (config('company')) and $logoSrc.

  Kept in step with pdf/purchase-order.blade.php and pdf/material-request.blade.php
  so the three documents read as a set. That includes the dompdf workarounds
  discovered while building the PO — page margins on <body>, explicit insets on
  fixed boxes, a spacer COLUMN between panels, explicit .last instead of
  :last-child, striping applied by the loop rather than :nth-child — each
  commented where it appears, because every one of them looks like pointless
  verbosity until you remove it and the layout prints to the paper edge.

  What is different here, and why:
    - This is an EXTERNAL document. The purchase order is addressed to a vendor;
      this one is addressed to the General Contractor, who is not a system user.
      The "To" panel reads ChangeOrder::gcBlock(), which prefers the gc_* SNAPSHOT
      frozen at prepareDocument() and falls back to the live
      project_general_contractors row for a draft that has no snapshot yet. That
      is what stops a later edit to a GC record from rewriting a document already
      issued — the same guarantee the PO's ship_to_* block has.
      NOTE: this panel used to read the project's CLIENT, as a stand-in from
      before a GC entity existed. A client is the owner commissioning the
      project; a GC is who Ponos contracts under. They are different parties.
    - There are NO line items. A change order carries a single `value` decimal by
      design (no breakdown, no markup, no schedule impact) — so where the PO has
      an items table and a totals block, this has a scope narrative and one
      amount band.
    - The authorization chain PRINTS. On an internal document the chain stays on
      screen, but this sheet is what a GC receives and countersigns, so it has to
      carry its own evidence of who authorised the change and when.
--}}
@php
    // DISPLAY ONLY. change_orders.value stays decimal(16,2) with full precision;
    // this rounds to whole dollars purely for print, so $12,500.00 reads $12,500.
    //
    // CAUTION: this ROUNDS rather than truncates, so a stored 12,500.75 prints as
    // $12,501 — a figure the General Contractor signs against that is not the
    // stored amount to the penny. Every change order raised so far carries a whole
    // value, so nothing is currently affected. To print cents whenever they exist
    // instead, change the 0 below to: (fmod((float) $v, 1) === 0.0 ? 0 : 2).
    $usd = fn ($v) => '$'.number_format((float) $v, 0, '.', ',');
    $dash  = '—';

    $statusCode = $co->status->code ?? 'draft';

    // Until the GC approves, this is not a live authorisation and must not read
    // as one. Deliberately short strings: the watermark is set at 60pt with 8pt
    // tracking, and a long status label ("Pending Counter-Sign") would run off
    // the sheet.
    $watermark = match ($statusCode) {
        'active' => null,
        'rejected_internal', 'gc_rejected' => 'REJECTED',
        'cancelled' => 'CANCELLED',
        default => 'DRAFT',
    };

    // Snapshot-first; null when no GC has been selected at all.
    $gc = $co->gcBlock();

    $inclusions = $co->inclusionList();
    $exclusions = $co->exclusionList();

    // Scope of Work table rows, already in sort_order via the relation.
    $scopeItems = $co->scopeItems;

    // Payment terms / changes / acceptance, read from this change order's frozen
    // snapshot. Empty arrays before the document has been prepared.
    $terms = $co->termsParagraphs();

    // On-site (emergency) signature images, keyed by signature id. Normally
    // supplied by ChangeOrderPdfService::render(); defaults to empty so the
    // template still works rendered directly (tests, preview harnesses).
    $signatureImages = $signatureImages ?? [];

    // The on-site signature, where one was captured. Its details FILL THE
    // ACCEPTANCE PANEL at the foot of the sheet: on an emergency change order
    // the person who signed on the device is the GC's acceptance, so the panel
    // states them — with their real title and the moment they signed — rather
    // than printing the GC record's generic contact over blank rules for
    // someone to complete by hand. A Normal change order has no such signature
    // and keeps the blank-rule fallback.
    $onsite = $co->signatures->first();
    $onsiteSigSrc = $onsite ? ($signatureImages[$onsite->id] ?? null) : null;

    // device_info is free-form jsonb, so only scalar values are printed and the
    // keys are dropped — "iOS / iPad 9th gen" reads better on a document than
    // "platform: iOS, model: iPad 9th gen", and a nested structure cannot break
    // the render.
    $deviceLine = function ($info) {
        if (! is_array($info)) {
            return null;
        }

        $parts = array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : null,
            array_values($info),
        ), fn ($v) => filled($v));

        return count($parts) ? implode(' / ', $parts) : null;
    };

    $name = fn ($u) => $u ? trim("{$u->first_name} {$u->last_name}") : null;

    $originatorName   = $name($co->originator);
    $counterSignerName = $name($co->counterSignedBy);

    // Normally supplied by ChangeOrderPdfService::render(). Resolved here as a
    // fallback so the template still shows the mark when rendered directly
    // (tests, preview harnesses).
    $logoSrc = $logoSrc ?? \App\Support\BrandLogo::dataUri();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Change Order {{ $co->change_order_no }}</title>
<style>
/* PAGE MARGINS — deliberately on <body>, not @page. This dompdf build IGNORES
   @page margins outright; margin on <body> is honoured and repeats on every
   page. @page keeps only the sheet size. See pdf/purchase-order.blade.php. */
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

/* Running footer — repeats on every page in dompdf. The insets are NOT
   decorative: dompdf positions a fixed box against the PAGE box, not the @page
   content box, so left/right:0 would run the rule edge-to-edge across the paper
   regardless of the 12mm body margin. They must restate the margin explicitly. */
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
/* NOTE: the right-hand "… Page X of Y" string is NOT here. CSS counter(pages)
   resolves to 0 in dompdf, so it is drawn on the canvas instead — see
   ChangeOrderPdfService::stampPageNumbers(). Keep this footer's geometry and
   the FOOTER_* constants in that class in step. */

/* MASTHEAD */
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

/* TITLE BAND */
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

.cobox { width: 100%; border-collapse: collapse;
         border: 0.5pt solid #D9D4C7; border-top: 2pt solid #AF8D2B; }
/* Explicit .last rather than tr:last-child — dompdf's selector support for
   structural pseudo-classes is unreliable. */
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

.status-pill {
  display: inline-block;
  border: 0.75pt solid #AF8D2B;
  background: #FBF7EC;
  padding: 0.8mm 2.5mm;
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1pt; text-transform: uppercase;
  color: #8A6E1F;
}
.status-draft, .status-pending_pm, .status-pending_admin, .status-sent_back,
.status-pending_document, .status-pending_counter_sign {
  border-color: #6B665C; color: #6B665C; background: #F5F4F1;
}
.status-active { border-color: #2F6B3A; color: #2F6B3A; background: #F0F6F1; }
.status-rejected_internal, .status-gc_rejected, .status-cancelled {
  border-color: #8A3B2A; color: #8A3B2A; background: #FAF1EF;
}

.type-pill {
  display: inline-block;
  border: 0.75pt solid #8A3B2A;
  background: #FAF1EF;
  padding: 0.8mm 2.5mm;
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1pt; text-transform: uppercase;
  color: #8A3B2A;
}

.watermark {
  /* Same fixed-position caveat as .doc-footer — inset explicitly. */
  position: fixed;
  top: 120mm; left: 12mm; right: 12mm;
  text-align: center;
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 60pt; font-weight: bold; letter-spacing: 8pt;
  color: #EDE8DA;
}

/* PARTIES — border on the td so both panels match height exactly. A real spacer
   COLUMN separates them rather than border-spacing plus a negative outer
   margin: that combination pulled the whole table outside the page content box
   in dompdf, which is what put the panels against the paper edge. */
.parties { width: 100%; border-collapse: collapse; margin-top: 4mm; }
.parties > tbody > tr > td.panel {
  width: 48%;
  vertical-align: top;
  padding: 0;
  border: 0.5pt solid #D9D4C7;
}
.parties > tbody > tr > td.gap { width: 4%; border: 0; padding: 0; }
/* Single addressee panel spanning the sheet; the issuer half is gone — see the
   block comment above the panel markup. Deliberately does not name the removed
   heading: CSS comments survive into the rendered HTML (Blade comments do not),
   so the phrase would defeat any test asserting the panel no longer prints. */
.parties > tbody > tr > td.panel-wide { width: 100%; }

.addressee { width: 100%; border-collapse: collapse; }
.addressee td { vertical-align: top; padding: 0; }
.addressee .ad-left  { width: 62%; }
.addressee .ad-right { width: 38%; }

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

/* REFERENCE STRIP */
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

/* NARRATIVE — the change order's substance. Where the PO has line items.
   7mm rather than 5mm above each block, so the eye can find the section breaks
   when scanning rather than reading for them. */
.section { margin-top: 7mm; page-break-inside: avoid; }

/* Sections that MAY break across a page. `page-break-inside: avoid` is right for
   a short block but wrong for the long ones — inclusions, exclusions and the
   three terms blocks. Forced to stay whole, a block taller than the remaining
   space jumps to the next page and leaves a gap, and one taller than a full page
   overflows the margin entirely. These flow instead. */
.section-flow { page-break-inside: auto; }

/* ============================================================================
   THE body-section heading. Defined ONCE and used by every section, so they
   cannot drift apart — adjust here and the whole document follows.

   SIZE IS THE FIX. This was 6.5pt against 9pt body text, so every heading
   printed SMALLER than the paragraph beneath it and read as a caption rather
   than a heading. Now 10pt: clearly above the body without shouting, which is
   the register the purchase-order and material-request documents keep.

   Near-black rather than the old muted grey, and over a gold hairline instead of
   a grey one — the same accent as the rule under the masthead and the total-cost
   band, so the headings read as part of the document's structure.
   ============================================================================ */
.section-head {
  font-size: 10pt;
  font-weight: bold;
  letter-spacing: 0.9pt;      /* looser tracking than 6.5pt needed; 10pt carries itself */
  text-transform: uppercase;
  color: #1C1B18;
  border-bottom: 0.75pt solid #AF8D2B;
  padding-bottom: 1.6mm;
  margin: 0 0 3mm 0;
  /* Never strand a heading alone at the foot of a page. dompdf honours this only
     partially — it is respected for short following content, less reliably for a
     block that must itself break — so the long sections additionally keep their
     first lines with the heading by virtue of .section-flow's ordering. */
  page-break-after: avoid;
}

.co-title { font-size: 12pt; font-weight: bold; margin-bottom: 2mm; }
.prose { font-size: 9pt; color: #3B3931; line-height: 1.55; }
.prose + .prose { margin-top: 3mm; }
.prose-empty { color: #8A8578; font-style: italic; }

/* SCOPE OF WORK TABLE — quantities, never prices.
   Column header repeats on every page via table-header-group; rows stay whole. */
.scope-items { width: 100%; border-collapse: collapse; margin-top: 2mm; }
.scope-items thead { display: table-header-group; }
.scope-items tr { page-break-inside: avoid; }

.scope-items .colhead th {
  background: #1C1B18; color: #FFFFFF;
  font-size: 6.6pt; letter-spacing: 0.7pt; text-transform: uppercase;
  font-weight: bold;
  padding: 2.2mm 3mm; text-align: left;
  /* Without this the tracked-out labels wrap and the band doubles in height on
     EVERY page — same trap as the purchase-order items table. */
  white-space: nowrap;
}
.scope-items td {
  padding: 1.9mm 3mm;
  border-bottom: 0.5pt solid #E7E3D8;
  vertical-align: top;
  font-size: 8.5pt;
}
.scope-items .si-qty  { width: 14%; text-align: right; white-space: nowrap; }
.scope-items .si-unit { width: 14%; text-align: left;  white-space: nowrap; }
th.si-qty { text-align: right; }

/* A division band. Tinted rather than the reference's blue, to stay in palette. */
.scope-items tr.si-section td {
  background: #F0EDE4;
  border-bottom: 0.5pt solid #D9D4C7;
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1.2pt; text-transform: uppercase;
  color: #3B3931;
}
/* A heading within a division — gold accent instead of the reference's blue. */
.scope-items tr.si-group td {
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1.1pt; text-transform: uppercase;
  color: #8A6E1F;
  border-bottom: 0.5pt solid #E7E3D8;
}
/* A called-out category, which may carry its own quantity ("WALL  44  LF"). */
.scope-items tr.si-emphasis td { font-weight: bold; color: #1C1B18; }
/* An indented material breakdown under a line. */
.scope-items tr.si-subline td { font-size: 7.8pt; color: #6B665C; font-style: italic; }
/* Deliberate blank row — no rule, so it reads as a gap rather than an empty row. */
.scope-items tr.si-spacer td { border-bottom: 0; padding: 1mm 0; }
/* Closing row. Labelled, NOT summed — the column mixes SF, LF, EA and GAL. */
.scope-items tr.si-subtotal td {
  border-top: 0.75pt solid #AF8D2B;
  border-bottom: 0;
  font-weight: bold;
  font-size: 8.5pt;
}

/* INCLUSIONS / EXCLUSIONS — stacked, one headed list each. */
.ie-list { margin: 1.5mm 0 0 0; padding-left: 5mm; font-size: 8.5pt; color: #3B3931;
           line-height: 1.5; }
.ie-list li { margin-bottom: 1.4mm; }
/* The excluded half reads as a caveat, not an offer — muted, and marked by a
   red-brown rule matching the .status-* treatment used for negative outcomes. */
.ie-list-excl { color: #6B665C; }

/* TOTAL COST — the focal point of the sheet. This is the one number the GC reads
   before anything else, so it gets a framed callout rather than the flat band it
   used to share with the purchase order's grand total.

   Palette is entirely existing tokens: the 2.5pt gold top rule matches the
   .cobox and the masthead accent, and gold-on-cream (#8A6E1F on #FBF7EC) is the
   same pairing .status-pill already uses. Serif numerals match .v-po, so a large
   figure reads as part of the document rather than a bolt-on. */
.value-wrap { width: 100%; border-collapse: collapse; margin-top: 5mm;
              page-break-inside: avoid; }
.value-wrap > tbody > tr > td { vertical-align: top; padding: 0; }
.value-left  { width: 52%; padding-right: 7mm !important; }
.value-right { width: 48%; }

.value-note { border-left: 2pt solid #AF8D2B; padding: 0.5mm 0 0.5mm 3mm;
              font-size: 8pt; color: #6B665C; line-height: 1.5; }

/* The box must never be split by a page break — half a figure is worse than a
   figure pushed to the next page. Belt and braces: the wrapper table above and
   the enclosing .section both carry the same rule. */
.value-box {
  border: 0.75pt solid #D9D4C7;
  border-top: 2.5pt solid #AF8D2B;
  background: #FBF7EC;
  padding: 4mm 4.5mm;
  text-align: right;
  page-break-inside: avoid;
}
.value-box .vb-label {
  display: block;
  font-size: 7pt; font-weight: bold;
  letter-spacing: 1.3pt; text-transform: uppercase;
  color: #6B665C;
  margin-bottom: 2mm;
}
.value-box .vb-amount {
  display: block;
  font-family: "DejaVu Serif", Georgia, serif;
  font-size: 22pt; font-weight: bold;
  letter-spacing: 0.3pt;
  color: #8A6E1F;
  line-height: 1.1;
}
/* An unpriced change must not look like a settled figure — muted, sans, and
   nowhere near the size of a real amount. */
.value-box.value-tbd { border-top-color: #6B665C; background: #F5F4F1; }
.value-box .vb-tbd {
  display: block;
  font-family: "DejaVu Sans", Helvetica, sans-serif;
  font-size: 11pt; font-weight: bold;
  letter-spacing: 0.4pt;
  color: #6B665C;
}
/* NOTE: the internal authorization-chain table and its .chain/.ch-* rules used
   to sit here. The chain no longer prints — this sheet goes to a General
   Contractor, and who approved it internally is not their record. It remains
   available in the API (data.authorization_chain) and in change_order_approvals. */

/* ON-SITE SIGNATURE (emergency change orders only) */
.onsite { margin-top: 5mm; border: 0.5pt solid #D9D4C7; page-break-inside: avoid; }
.onsite-head {
  background: #FAF1EF; border-bottom: 0.5pt solid #D9D4C7;
  padding: 2mm 3mm;
  font-size: 6.5pt; letter-spacing: 1.3pt; text-transform: uppercase;
  color: #8A3B2A; font-weight: bold;
}
.onsite-body { padding: 2.6mm 3mm; font-size: 8.5pt; }
.onsite-name { font-size: 10pt; font-weight: bold; }
.onsite-meta { font-size: 7.8pt; color: #6B665C; margin-top: 1.2mm; line-height: 1.5; }

/* SIGNATURES — spacer column rather than border-spacing, see .parties. */
.signatures { width: 100%; border-collapse: collapse;
              margin-top: 12mm; page-break-inside: avoid; }
.signatures td.sig { width: 47%; vertical-align: bottom; padding: 0; }
.signatures td.gap { width: 6%; padding: 0; }
.sig-line { border-bottom: 0.5pt solid #1C1B18; height: 16mm; }
/* The captured signature mark, sitting ON the acceptance line rather than in a
   panel of its own. max-height (not height) so a wide, shallow signature keeps
   its aspect ratio; the top margin drops it toward the rule so it reads as
   signed rather than floating above it. */
.sig-img { display: block; max-height: 14mm; max-width: 60mm; margin-top: 2mm; }
.sig-name { font-size: 9pt; font-weight: bold; margin-top: 1.8mm; }
.sig-meta { font-size: 7.5pt; color: #6B665C; line-height: 1.5; }

.closing { margin-top: 8mm; padding-top: 4mm; border-top: 0.5pt solid #D9D4C7;
           font-size: 7.8pt; color: #4A473F; line-height: 1.6;
           page-break-inside: avoid; }
/* NOTE: .closing carries no heading of its own — the return instruction is a
   single closing line, not a section. The old small inline sub-label rule was
   removed with it; every heading now goes through .section-head, so there is
   exactly one place to change their treatment.
   Class names of removed rules are deliberately not spelled out here: CSS
   comments survive into the rendered HTML (Blade comments do not), so naming one
   would defeat any test asserting it no longer appears. */
</style>
</head>
<body>

{{-- ===================== RUNNING FOOTER ===================== --}}
<div class="doc-footer">
  <table>
    <tr>
      <td>
        {{ $company['name'] }}
        &nbsp;&middot;&nbsp; {{ $co->change_order_no }}
        @if($co->project?->code)
          &nbsp;&middot;&nbsp; {{ $co->project->code }}
        @endif
      </td>
      {{-- Right-hand cell intentionally empty: the "This document is
           computer-generated. Page X of Y" string is drawn on the canvas so the
           page total is correct. --}}
      <td></td>
    </tr>
  </table>
</div>

@if($watermark)
  <div class="watermark">{{ $watermark }}</div>
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
      <div class="doc-title">CHANGE ORDER</div>
      <div class="doc-tagline">
        This change order describes work outside the original scope. It takes effect
        only when signed by both parties, and supersedes no other term of the contract.
      </div>
    </td>

    <td class="t-right">
      <table class="cobox">
        <tr>
          <td class="k">CO Number</td>
          <td class="v v-co">{{ $co->change_order_no }}</td>
        </tr>
        <tr>
          <td class="k">Date</td>
          {{-- counter_signed_at is null until the document is finalised;
               created_at always exists. --}}
          <td class="v">{{ optional($co->counter_signed_at ?? $co->created_at)->format('d M Y') }}</td>
        </tr>
        <tr>
          <td class="k">Type</td>
          <td class="v">
            @if(($co->type->code ?? null) === 'emergency')
              <span class="type-pill">{{ $co->type->label ?? 'Emergency' }}</span>
            @else
              {{ $co->type->label ?? 'Normal' }}
            @endif
          </td>
        </tr>
        <tr class="last">
          <td class="k">Status</td>
          <td class="v">
            <span class="status-pill status-{{ $statusCode }}">
              {{ $co->status->label ?? 'Draft' }}
            </span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

{{-- ===================== ADDRESSEE =====================
     ONE full-width panel, not two. There was an "Issued by" panel here carrying
     the company's own name, address, phone and email — every one of which is
     already printed in the masthead top-right, so half the block was spent
     restating the letterhead. The reference sheet has a single addressee block
     for the same reason: the issuer IS the letterhead.

     The General Contractor is not a system user — their decision arrives out of
     band and is recorded internally — which is why this binds to a
     project_general_contractors row rather than to any account. Values come from
     ChangeOrder::gcBlock(), snapshot-first with a live fallback, so an issued
     document keeps naming the counterparty it was issued to. --}}
<table class="parties">
  <tr>
    <td class="panel panel-wide">
      <div class="phead">To — General Contractor</div>
      <div class="pbody">
        @if($gc)
          {{-- Company and address left, contact right, so a full-width panel
               reads as a block rather than a sparse column. --}}
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
     instead of leaving blank boxes, and the surviving cells always divide the
     width evenly. --}}
@php
    $refs = array_filter([
        'Project' => $co->project->code ?? null,
        'Cost code' => $co->costCode->code ?? null,
        'Urgency' => $co->urgency->label ?? null,
        'Raised by' => $originatorName,
        'Raised on' => optional($co->created_at)->format('d M Y'),
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

{{-- ===================== THE CHANGE =====================
     Title and description sit under one heading; scope and location each get
     their own, because they are sections a reader looks FOR rather than reads
     past. All four use the single .section-head style. --}}
<div class="section">
  <div class="section-head">Description of change</div>
  <div class="co-title">{{ $co->title }}</div>

  @if($co->description)
    <div class="prose">{!! nl2br(e($co->description)) !!}</div>
  @endif
</div>

{{-- Scope of work: the free-text lead-in and the quantity table live under ONE
     heading, since they describe the same thing. .section-flow because the table
     may run long and must be allowed to paginate. --}}
<div class="section section-flow">
  <div class="section-head">Scope of work</div>

  @if($co->scope)
    <div class="prose">{!! nl2br(e($co->scope)) !!}</div>
  @elseif(! count($scopeItems))
    <div class="prose"><span class="prose-empty">Not specified.</span></div>
  @endif

{{-- ===================== SCOPE OF WORK TABLE =====================
     The structured quantity breakdown, where the free-text `scope` above is the
     lead-in. Rows come from change_order_scope_items in sort_order; the whole
     block collapses when there are none, so a change order raised before this
     existed prints exactly as it did.

     Quantities only — never prices. A change order's money is the single
     `value` decimal with no breakdown by design, which is why there is no
     amount column and why the subtotal row prints a label rather than a sum:
     totalling a column that mixes SF, LF, EA and GAL would be meaningless.

     Styled in OUR palette rather than the reference's blue/red banding, per the
     rest of this document set. Sits INSIDE the Scope of work section, under that
     one heading — the column header repeats per page via table-header-group. --}}
@if(count($scopeItems))
  <table class="scope-items">
    <thead>
      <tr class="colhead">
        <th class="si-desc">Description</th>
        <th class="si-qty">Qty</th>
        <th class="si-unit">Unit</th>
      </tr>
    </thead>
    <tbody>
      @foreach($scopeItems as $row)
        @php
            // Indent drives left padding on the description cell; the row type
            // drives everything else. Kept separate so a caller can nest without
            // inventing new types.
            $pad = 3 + ($row->indent * 4);
        @endphp

        @if($row->row_type === 'spacer')
          <tr class="si-spacer"><td colspan="3">&nbsp;</td></tr>

        @elseif($row->row_type === 'section')
          <tr class="si-section">
            <td colspan="3" style="padding-left: {{ $pad }}mm">{{ $row->label }}</td>
          </tr>

        @elseif($row->row_type === 'group')
          <tr class="si-group">
            <td colspan="3" style="padding-left: {{ $pad }}mm">{{ $row->label }}</td>
          </tr>

        @elseif($row->row_type === 'subtotal')
          <tr class="si-subtotal">
            <td style="padding-left: {{ $pad }}mm">{{ $row->label }}</td>
            <td class="si-qty">{{ $row->printedQuantity() ?? $dash }}</td>
            <td class="si-unit">{{ $row->printedUnit() ?? $dash }}</td>
          </tr>

        @else
          {{-- emphasis | line | subline — the same three cells, different weight. --}}
          <tr class="si-{{ $row->row_type }}">
            <td style="padding-left: {{ $pad }}mm">{{ $row->label }}</td>
            <td class="si-qty">{{ $row->printedQuantity() }}</td>
            <td class="si-unit">{{ $row->printedUnit() }}</td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>
@endif
</div>{{-- /section: Scope of work --}}

@if($co->location)
  <div class="section">
    <div class="section-head">Location</div>
    <div class="prose">{{ $co->location }}</div>
  </div>
@endif

{{-- ===================== TOTAL COST =====================
     One figure, and it sits directly under the scope — the reference sheet reads
     scope, then cost, then what that cost does and does not buy.

     change_orders.value is a single decimal by design: no line-item breakdown, no
     labour/material split, no markup or O&P, and no schedule-impact field
     anywhere in the schema. The reference's DIVISION 09 quantity table therefore
     has no equivalent here, and the scope narrative carries that detail instead.

     Carries the shared .section-head like every other section. The gold band
     below keeps its own inner label — the two are not duplicates: the heading
     names the SECTION, the band labels the FIGURE, exactly as the reference
     prints "TOTAL COST" over "Total Cost: $ 10,910". --}}
<div class="section">
  <div class="section-head">Total cost</div>
<table class="value-wrap">
  <tr>
    <td class="value-left">
      <div class="value-note">
        The amount stated is the full and final adjustment to the contract sum for
        the work described above, inclusive of all labour, materials, plant and
        overhead. No adjustment to the contract period is made by this change order
        unless stated in the scope above.
      </div>
    </td>

    <td class="value-right">
      @if($co->value !== null)
        <div class="value-box">
          <span class="vb-label">Total cost</span>
          <span class="vb-amount">{{ $usd($co->value) }}</span>
        </div>
      @else
        <div class="value-box value-tbd">
          <span class="vb-label">Total cost</span>
          <span class="vb-tbd">To be determined</span>
        </div>
      @endif
    </td>
  </tr>
</table>
</div>{{-- /section: Total cost --}}

{{-- ===================== INCLUSIONS / EXCLUSIONS =====================
     What the change covers and what it explicitly does not. Stored as two text
     columns and split one-entry-per-line by ChangeOrder::inclusionList() /
     exclusionList(), which delegate to the same PurchaseOrderTerm::splitClauses()
     the purchase order's frozen terms use.

     STACKED rather than side by side: the reference prints them as two
     consecutive headed lists, and a two-column table forces both halves to the
     height of the taller one and cannot break across a page. Each is
     .section-flow so a long list flows onto the next page instead of overflowing.

     Each collapses out entirely when empty rather than printing a heading over
     nothing, so a change order raised before these fields existed prints exactly
     as it did before. --}}
@if(count($inclusions))
<div class="section section-flow">
  <div class="section-head">Inclusions</div>
  <ol class="ie-list">
    @foreach($inclusions as $entry)
      <li>{{ $entry }}</li>
    @endforeach
  </ol>
</div>
@endif

@if(count($exclusions))
<div class="section section-flow">
  <div class="section-head">Exclusions</div>
  <ol class="ie-list ie-list-excl">
    @foreach($exclusions as $entry)
      <li>{{ $entry }}</li>
    @endforeach
  </ol>
</div>
@endif

{{-- ===================== PAYMENT TERMS / CHANGES / ACCEPTANCE =====================
     The standing contractual text, read from the change order's own frozen
     SNAPSHOT (payment_terms_body / changes_body / acceptance_body) — never from
     the live change_order_terms row, which is what keeps an issued sheet's
     wording fixed after the company revises its standard text.

     Each is .section-flow: these are the longest blocks on the document and must
     be allowed to break across a page rather than overflow or leave a gap.

     Each collapses entirely when its body is empty. A change order prepared
     before this text was configured therefore prints without them, rather than
     with three empty headings. --}}
@if(count($terms['payment_terms']))
<div class="section section-flow">
  <div class="section-head">Payment terms</div>
  @foreach($terms['payment_terms'] as $para)
    <div class="prose">{{ $para }}</div>
  @endforeach
</div>
@endif

@if(count($terms['changes']))
<div class="section section-flow">
  <div class="section-head">Changes</div>
  @foreach($terms['changes'] as $para)
    <div class="prose">{{ $para }}</div>
  @endforeach
</div>
@endif

@if(count($terms['acceptance']))
<div class="section section-flow">
  <div class="section-head">Acceptance</div>
  @foreach($terms['acceptance'] as $para)
    <div class="prose">{{ $para }}</div>
  @endforeach
</div>
@endif

{{-- ===================== ON-SITE CAPTURE EVIDENCE (EMERGENCY) =====================
     An emergency change order is authorised by the GC's representative signing
     on site, not by the internal chain. WHO signed, their title, when, and the
     signature mark itself all print in the acceptance panel below — that is the
     contractual half, and it belongs on the signature line.

     What stays here is the half that has no place on a signature line: where the
     device was when it captured the mark, which of our people held it, and what
     it was running. That is provenance for a disputed signature, not part of the
     acceptance itself. Printing the name and title in both places is what this
     block used to do, and it read as a duplicate rather than as evidence. --}}
@foreach($co->signatures as $sig)
<div class="onsite">
  <div class="onsite-head">On-site authorization — capture record</div>
  <div class="onsite-body">
    <div class="onsite-meta">
      Signed {{ optional($sig->signed_at)->format('d M Y H:i') }}
      @if($sig->location_note) at {{ $sig->location_note }}@endif
      @if($sig->signed_lat !== null && $sig->signed_lng !== null)
        <br>Captured at {{ $sig->signed_lat }}, {{ $sig->signed_lng }}
      @endif
      @if($name($sig->capturedBy))<br>Recorded by {{ $name($sig->capturedBy) }}@endif
      @php($device = $deviceLine($sig->device_info))
      @if($device)<br>Device: {{ $device }}@endif
    </div>
  </div>
</div>
@endforeach

{{-- ===================== SIGNATURES ===================== --}}
<table class="signatures">
  <tr>
    <td class="sig">
      <div class="sig-line"></div>
      {{-- Same escaping rule as the acceptance block below. --}}
      <div class="sig-name">@if($counterSignerName){{ $counterSignerName }}@else&nbsp;@endif</div>
      <div class="sig-meta">Authorised by, for and on behalf of {{ $company['name'] }}</div>
      <div class="sig-meta">Date: {{ $co->counter_signed_at?->format('d M Y') ?: '____________________' }}</div>
    </td>
    <td class="gap"></td>
    <td class="sig">
      {{-- The captured mark sits INSIDE the rule's box, so it prints on the
           signature line exactly as a wet signature would. --}}
      <div class="sig-line">
        @if($onsiteSigSrc)
          <img class="sig-img" src="{{ $onsiteSigSrc }}" alt="Signature">
        @endif
      </div>

      @if($onsite)
        {{-- Signed on-site: every field below is real, so nothing is left as a
             blank rule for someone to complete after the fact. The date carries
             the TIME as well — an emergency change order is authorised at a
             moment on site, and the hour is part of the record. --}}
        <div class="sig-name">{{ $onsite->signer_name }}</div>
        <div class="sig-meta">
          Accepted by, for and on behalf of
          {{ $onsite->signer_company ?: ($gc['name'] ?? 'the General Contractor') }}
        </div>
        <div class="sig-meta">Title: {{ $onsite->signer_title ?: '____________________' }}</div>
        <div class="sig-meta">Date: {{ optional($onsite->signed_at)->format('d M Y H:i') ?: '____________________' }}</div>
      @else
        {{-- No signature captured (every Normal change order): the sheet goes out
             for the GC to sign by hand, so this stays a blank block.

             The name is ESCAPED and the blank-line spacer is emitted separately.
             Writing `{{ $name ?? '&nbsp;' }}` would print a literal "&nbsp;" when
             empty, and `{!! !!}` would inject an unescaped GC contact name — which
             is user input — straight into HTML that dompdf then renders. --}}
        <div class="sig-name">@if($gc && $gc['contact_name']){{ $gc['contact_name'] }}@else&nbsp;@endif</div>
        <div class="sig-meta">
          Accepted by, for and on behalf of {{ $gc['name'] ?? 'the General Contractor' }}
        </div>
        {{-- The GC's signer TITLE has no backing anywhere in the schema, so it is a
             blank rule for them to complete by hand — as on the reference sheet.
             A blank signature line is not invented data. --}}
        <div class="sig-meta">Title: ____________________</div>
        <div class="sig-meta">Date: ____________________</div>
      @endif
    </td>
  </tr>
</table>

{{-- The "Acceptance" prose that used to sit here was hardcoded into this
     template with nothing backing it. It now comes from change_order_terms via
     the frozen snapshot and prints as a proper section above, alongside Payment
     Terms and Changes. Only the return instruction — which is about THIS
     document, not standing contractual text — stays here. --}}
<div class="closing">
  Please return one signed copy quoting {{ $co->change_order_no }}.
</div>

</body>
</html>
