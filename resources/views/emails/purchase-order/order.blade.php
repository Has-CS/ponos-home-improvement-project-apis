<x-mail.layout preheader="{{ $company['name'] }} has issued purchase order {{ $purchaseOrder->po_number }}.">

  <h1 style="margin:0 0 16px 0; font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; line-height:32px; color:#1F2D25; font-weight:400;" class="h1-mobile">
    Purchase Order
  </h1>

  <p style="margin:0 0 20px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    @if($vendorContactName)Hi {{ $vendorContactName }},@else Hello,@endif
  </p>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    {{ $company['name'] }} has issued purchase order <strong>{{ $purchaseOrder->po_number }}</strong>. The full order — items, quantities, prices and our terms — is in the attached PDF ({{ $pdfFileName }}).
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0; border:1px solid #BD9C72; border-radius:6px;">
    <tr>
      <td style="padding:20px 24px;">
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>PO number:</strong> {{ $purchaseOrder->po_number }}
        </p>
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Order total:</strong> ${{ number_format((float) $purchaseOrder->total_amount, 2) }}
        </p>
        @if($purchaseOrder->expected_delivery_date)
        <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Requested delivery date:</strong> {{ $purchaseOrder->expected_delivery_date->format('d M Y') }}
        </p>
        @endif
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    Please confirm receipt of this order, along with the delivery date you can meet. Quote the PO number on all invoices, delivery notes and correspondence. If anything on the order needs to change, reply here before shipping.
  </p>

  {{-- Signed by the buyer who issued the order, matching the signature block on
       the attached PDF, so the vendor has one consistent contact. Each line is
       conditional: a buyer may have no mobile on file, and a legacy order may
       have no issuer at all — in which case this degrades to the plain company
       sign-off. Same structure as the RFQ mail. --}}
  <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; line-height:22px; color:#1F2D25;">
    Thank you,<br>
    @if($issuerName){{ $issuerName }}<br>@endif
    {{ $company['name'] }}
    @if($issuerEmail || $issuerMobile)
      <br>
      <span style="font-size:13px; color:#5B6A62;">
        @if($issuerEmail)<a href="mailto:{{ $issuerEmail }}" style="color:#5B6A62; text-decoration:none;">{{ $issuerEmail }}</a>@endif
        @if($issuerEmail && $issuerMobile) &middot; @endif
        @if($issuerMobile){{ $issuerMobile }}@endif
      </span>
    @endif
  </p>

</x-mail.layout>
