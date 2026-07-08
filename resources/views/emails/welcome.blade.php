<x-mail.layout preheader="Your Ponos account is ready — here's everything you need to get started.">

  <h1 style="margin:0 0 16px 0; font-family: Georgia, 'Times New Roman', Times, serif; font-size:26px; line-height:32px; color:#1F2D25; font-weight:400;" class="h1-mobile">
    Welcome to Ponos, {{ $firstName }}.
  </h1>

  <p style="margin:0 0 28px 0; font-family: Helvetica, Arial, sans-serif; font-size:15px; line-height:24px; color:#1F2D25;">
    We&rsquo;re glad to have you on board. Your account has been created, and you&rsquo;re just one login away from a clearer way to run your projects &mdash; from the first walkthrough to the final punch list.
  </p>

  {{-- What you can do --}}
  <p style="margin:0 0 14px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#BD9C72; font-weight:bold;">
    Here&rsquo;s what you can do
  </p>

  <!--[if mso]>
  <table role="presentation" width="100%"><tr><td width="33%" valign="top">
  <![endif]-->
  <div class="stack-column" style="display:inline-block; width:100%; max-width:31%; vertical-align:top; padding-right:12px;">
    <p style="margin:0 0 4px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; font-weight:bold; letter-spacing:0.5px; color:#C4864B; text-transform:uppercase;">Plan</p>
    <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:13px; line-height:19px; color:#1F2D25;">Organize every project from first walkthrough to final close-out.</p>
  </div>
  <!--[if mso]>
  </td><td width="33%" valign="top">
  <![endif]-->
  <div class="stack-column" style="display:inline-block; width:100%; max-width:31%; vertical-align:top; padding-right:12px;">
    <p style="margin:0 0 4px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; font-weight:bold; letter-spacing:0.5px; color:#C4864B; text-transform:uppercase;">Track</p>
    <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:13px; line-height:19px; color:#1F2D25;">See schedules, progress, and status the moment they change.</p>
  </div>
  <!--[if mso]>
  </td><td width="33%" valign="top">
  <![endif]-->
  <div class="stack-column" style="display:inline-block; width:100%; max-width:31%; vertical-align:top;">
    <p style="margin:0 0 4px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; font-weight:bold; letter-spacing:0.5px; color:#C4864B; text-transform:uppercase;">Collaborate</p>
    <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:13px; line-height:19px; color:#1F2D25;">Keep your team, contractors, and stakeholders on the same page.</p>
  </div>
  <!--[if mso]>
  </td></tr></table>
  <![endif]-->

  {{-- Credentials card --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0 0; border:1px solid #BD9C72; border-radius:6px;">
    <tr>
      <td style="padding:20px 24px;">
        <p style="margin:0 0 12px 0; font-family: Helvetica, Arial, sans-serif; font-size:12px; letter-spacing:0.5px; text-transform:uppercase; color:#BD9C72; font-weight:bold;">
          Your login details
        </p>
        <p style="margin:0 0 6px 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Email:</strong> {{ $email }}
        </p>
        <p style="margin:0; font-family: Helvetica, Arial, sans-serif; font-size:14px; color:#1F2D25;">
          <strong>Password:</strong>
          <span style="font-family: 'Courier New', Courier, monospace; letter-spacing:0;">{{ $password }}</span>
        </p>
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px auto 0 auto;">
    <tr>
      <td style="border-radius:4px; background-color:#1A3127;" bgcolor="#1A3127" align="center">
        <a href="{{ $loginUrl }}" target="_blank" style="display:inline-block; padding:14px 36px; font-family: Georgia, 'Times New Roman', Times, serif; font-size:16px; font-weight:bold; color:#FAF8F5; text-decoration:none; border-radius:4px;">
          Log In to Ponos
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:24px 0 0 0; font-family: Helvetica, Arial, sans-serif; font-size:13px; line-height:20px; color:#5B6A62;">
    For your security, we recommend changing your password after you log in.
  </p>

  <p style="margin:28px 0 0 0; font-family: Helvetica, Arial, sans-serif; font-size:14px; line-height:22px; color:#1F2D25;">
    We&rsquo;re glad you&rsquo;re here &mdash; let&rsquo;s get building.<br>
    The Ponos Team
  </p>

</x-mail.layout>
