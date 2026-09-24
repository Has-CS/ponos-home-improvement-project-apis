Purchase Order

@if($vendorContactName)Hi {{ $vendorContactName }},@else Hello,@endif

{{ $company['name'] }} has issued purchase order {{ $purchaseOrder->po_number }}. The full order - items, quantities, prices and our terms - is in the attached PDF ({{ $pdfFileName }}).

PO number: {{ $purchaseOrder->po_number }}
Order total: ${{ number_format((float) $purchaseOrder->total_amount, 2) }}
@if($purchaseOrder->expected_delivery_date)Requested delivery date: {{ $purchaseOrder->expected_delivery_date->format('d M Y') }}
@endif
Please confirm receipt of this order, along with the delivery date you can meet. Quote the PO number on all invoices, delivery notes and correspondence. If anything on the order needs to change, reply here before shipping.

Thank you,
@php
    // Assembled in one place rather than chained @if/@endif directives: Blade
    // will not parse several of those on a single line, and building the list
    // here also drops empty lines instead of leaving gaps in a plain-text mail.
    // Same approach as the RFQ text template.
    $signOff = array_values(array_filter([
        $issuerName,
        $company['name'],
        trim(implode(' - ', array_filter([$issuerEmail, $issuerMobile]))) ?: null,
    ]));
@endphp
{!! implode("\n", $signOff) !!}

© {{ date('Y') }} {{ $company['name'] }}. All rights reserved.
