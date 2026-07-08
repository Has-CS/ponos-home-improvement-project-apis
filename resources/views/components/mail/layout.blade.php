@props(['preheader' => ''])
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Ponos</title>
<style>
  body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
  img { -ms-interpolation-mode: bicubic; border: 0; line-height: 100%; }
  body { margin: 0; padding: 0; width: 100% !important; background-color: #FAF8F5; }

  @media only screen and (max-width: 600px) {
    .email-container { width: 100% !important; }
    .mobile-px { padding-left: 20px !important; padding-right: 20px !important; }
    .mobile-py { padding-top: 24px !important; padding-bottom: 24px !important; }
    .stack-column { display: block !important; width: 100% !important; max-width: 100% !important; padding: 0 0 16px 0 !important; }
    .h1-mobile { font-size: 22px !important; line-height: 28px !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:#FAF8F5;">

  <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#FAF8F5;">
    {{ $preheader }}
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#FAF8F5;">
    <tr>
      <td align="center" style="padding: 32px 16px;">

        <table role="presentation" width="600" class="email-container" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#FFFFFF; border-collapse:collapse;">

          {{-- Header band --}}
          <tr>
            <td align="center" bgcolor="#172A21" style="background-color:#172A21; padding:32px 24px;">
              <span style="font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; letter-spacing:6px; color:#BD9C72; text-transform:uppercase;">
                Ponos
              </span>
            </td>
          </tr>

          {{-- Body content --}}
          <tr>
            <td class="mobile-px mobile-py" style="padding:40px 40px; background-color:#FAF8F5;">
              {{ $slot }}
            </td>
          </tr>

          {{-- Footer band --}}
          <tr>
            <td align="center" bgcolor="#172A21" style="background-color:#172A21; padding:28px 24px;">
              <p style="margin:0 0 4px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; color:#D8CFC3;">
                &copy; {{ date('Y') }} Ponos Home Improvement. All rights reserved.
              </p>
              <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:12px; color:#D8CFC3;">
                This is an automated message &mdash; please don&rsquo;t reply directly to this email.
              </p>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
