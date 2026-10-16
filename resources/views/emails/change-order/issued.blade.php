{{--
  The change order as it reaches a General Contractor.

  Written for an EXTERNAL reader who is being asked to authorise payment for work
  that, on the emergency path, is already built. So the mail leads with the
  authorisation rather than the amount: naming the person who signed on site, and
  when, is what turns this from an unexpected bill into a bill against something the
  recipient's own representative asked for.

  $signerName / $authorizedOn are null on a Normal change order, and the
  authorisation paragraph collapses out — the attached PDF is then simply a change
  order awaiting signature, which is what the Normal flow has always sent by hand.
--}}
<x-mail.layout preheader="{{ $company['name'] }} has issued change order {{ $changeOrder->change_order_no }} for your approval.">

  <h1 style="margin:0 0 16px 0; font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; line-height:32px; color:#1F2D25; font-weight:400;" class="h1-mobile">
    Change Order
  </h1>

  <p style="margin:0 0 20px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    @if($gcContactName)Dear {{ $gcContactName }},@else Dear Sir or Madam,@endif
  </p>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    {{ $company['name'] }} has issued change order <strong>{{ $changeOrder->change_order_no }}</strong>@if($projectLine) for {{ $projectLine }}@endif. The change order, together with the signed authorization for the work, is in the attached PDF ({{ $pdfFileName }}).
  </p>

  @if($signerName)
    <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
      This change covers work authorised on site by <strong>{{ $signerName }}</strong>@if($authorizedOn) on {{ $authorizedOn->format('d M Y') }} at {{ $authorizedOn->format('H:i') }}@endif. The signed Work Authorization is included in the attachment as the second document, after the change order itself.
    </p>
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0; border:1px solid #BD9C72; border-radius:6px;">
    <tr>
      <td style="padding:20px 24px;">
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Change order number:</strong> {{ $changeOrder->change_order_no }}
        </p>
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Description:</strong> {{ $changeOrder->title }}
        </p>
        <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Change to contract sum:</strong>
          @if($changeOrder->value !== null)
            ${{ number_format((float) $changeOrder->value, 2) }}
          @else
            To be determined
          @endif
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    This change order requires your written authorisation for the amount stated. Please return one signed copy quoting <strong>{{ $changeOrder->change_order_no }}</strong>.
  </p>

  @if($senderName)
    <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
      Kind regards,<br>
      {{ $senderName }}<br>
      {{ $company['name'] }}<br>
      @if($senderEmail)<a href="mailto:{{ $senderEmail }}" style="color:#8A6E1F;">{{ $senderEmail }}</a>@endif
      @if($senderEmail && $senderMobile) &middot; @endif
      @if($senderMobile){{ $senderMobile }}@endif
    </p>
  @else
    <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
      Kind regards,<br>
      {{ $company['name'] }}
    </p>
  @endif

</x-mail.layout>
