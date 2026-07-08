<x-mail.layout preheader="Reset your Ponos password — this link expires in {{ $ttlMinutes }} minutes.">

  <h1 style="margin:0 0 16px 0; font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; line-height:32px; color:#1F2D25; font-weight:400;" class="h1-mobile">
    Reset your password
  </h1>

  <p style="margin:0 0 8px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    Hi {{ $firstName }}, we received a request to reset the password on your Ponos account.
  </p>

  <p style="margin:0 0 28px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    Click below to choose a new one. This link will expire in <strong>{{ $ttlMinutes }} minutes</strong>.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
    <tr>
      <td style="border-radius:4px; background-color:#1A3127;" bgcolor="#1A3127" align="center">
        <a href="{{ $resetUrl }}" target="_blank" style="display:inline-block; padding:14px 36px; font-family: Georgia, 'Times New Roman', Times, serif; font-size:16px; font-weight:bold; color:#FAF8F5; text-decoration:none; border-radius:4px;">
          Reset Password
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:28px 0 0 0; padding:16px 20px; border-left:3px solid #BD9C72; font-family: Helvetica, Arial, sans-serif; font-size:13px; line-height:20px; color:#5B6A62; background-color:#FFFFFF;">
    If you didn&rsquo;t request a password reset, you can safely ignore this email &mdash; your password will remain unchanged.
  </p>

  <p style="margin:28px 0 0 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; line-height:22px; color:#1F2D25;">
    The Ponos Team
  </p>

</x-mail.layout>
