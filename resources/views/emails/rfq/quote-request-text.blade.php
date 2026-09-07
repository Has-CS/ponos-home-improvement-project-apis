Request for Quotation

@if($vendorContactName)Hi {{ $vendorContactName }},@else Hello,@endif

{{ $company['name'] }} would like to request a quote for {{ $rfq->title }}. The full list of items and quantities is in the attached PDF ({{ $pdfFileName }}).

RFQ number: {{ $rfq->rfq_no }}
@if($rfq->due_date)Please respond by: {{ $rfq->due_date->format('d M Y') }}
@endif
Please reply to this email with your pricing for the listed items. If you have any questions, just reply here and we'll get back to you.

Thank you,
@php
    // Assembled in one place rather than chained @if/@endif directives: Blade
    // will not parse several of those on a single line, and building the list
    // here also drops empty lines instead of leaving gaps in a plain-text mail.
    $signOff = array_values(array_filter([
        $authorName,
        $company['name'],
        trim(implode(' - ', array_filter([$authorEmail, $authorMobile]))) ?: null,
    ]));
@endphp
{!! implode("\n", $signOff) !!}

© {{ date('Y') }} {{ $company['name'] }}. All rights reserved.
