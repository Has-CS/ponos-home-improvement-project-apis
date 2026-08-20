Request for Quotation

@if($vendorContactName)Hi {{ $vendorContactName }},@else Hello,@endif

{{ $company['name'] }} would like to request a quote for {{ $rfq->title }}. The full list of items and quantities is in the attached PDF ({{ $pdfFileName }}).

RFQ number: {{ $rfq->rfq_no }}
@if($rfq->due_date)Please respond by: {{ $rfq->due_date->format('d M Y') }}
@endif
Please reply to this email with your pricing for the listed items. If you have any questions, just reply here and we'll get back to you.

Thank you,
{{ $company['name'] }}

© {{ date('Y') }} {{ $company['name'] }}. All rights reserved.
