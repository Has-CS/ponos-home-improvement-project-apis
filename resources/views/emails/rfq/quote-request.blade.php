<x-mail.layout preheader="{{ $company['name'] }} is requesting a quote for {{ $rfq->title }}.">

  <h1 style="margin:0 0 16px 0; font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; line-height:32px; color:#1F2D25; font-weight:400;" class="h1-mobile">
    Request for Quotation
  </h1>

  <p style="margin:0 0 20px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    @if($vendorContactName)Hi {{ $vendorContactName }},@else Hello,@endif
  </p>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    {{ $company['name'] }} would like to request a quote for <strong>{{ $rfq->title }}</strong>. The full list of items and quantities is in the attached PDF ({{ $pdfFileName }}).
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0; border:1px solid #BD9C72; border-radius:6px;">
    <tr>
      <td style="padding:20px 24px;">
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>RFQ number:</strong> {{ $rfq->rfq_no }}
        </p>
        @if($rfq->due_date)
        <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Please respond by:</strong> {{ $rfq->due_date->format('d M Y') }}
        </p>
        @endif
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    Please reply to this email with your pricing for the listed items. If you have any questions, just reply here and we'll get back to you.
  </p>

  <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; line-height:22px; color:#1F2D25;">
    Thank you,<br>
    {{ $company['name'] }}
  </p>

</x-mail.layout>
