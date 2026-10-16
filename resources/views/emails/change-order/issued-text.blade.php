{{-- Plain-text twin of issued.blade.php. --}}
@if($gcContactName)Dear {{ $gcContactName }},@else Dear Sir or Madam,@endif

{{ $company['name'] }} has issued change order {{ $changeOrder->change_order_no }}@if($projectLine) for {{ $projectLine }}@endif. The change order, together with the signed authorization for the work, is in the attached PDF ({{ $pdfFileName }}).
@if($signerName)

This change covers work authorised on site by {{ $signerName }}@if($authorizedOn) on {{ $authorizedOn->format('d M Y') }} at {{ $authorizedOn->format('H:i') }}@endif. The signed Work Authorization is included in the attachment as the second document, after the change order itself.
@endif

Change order number: {{ $changeOrder->change_order_no }}
Description: {{ $changeOrder->title }}
Change to contract sum: @if($changeOrder->value !== null)${{ number_format((float) $changeOrder->value, 2) }}@else To be determined @endif


This change order requires your written authorisation for the amount stated. Please return one signed copy quoting {{ $changeOrder->change_order_no }}.

Kind regards,
@php
    // Assembled in a @php block rather than chained directives: Blade will not
    // parse several of those on a single line. Same approach as the purchase
    // order's plain-text twin.
    $signOff = array_values(array_filter([
        $senderName,
        $company['name'],
        trim(implode(' - ', array_filter([$senderEmail, $senderMobile]))) ?: null,
    ]));
@endphp
{!! implode("\n", $signOff) !!}
