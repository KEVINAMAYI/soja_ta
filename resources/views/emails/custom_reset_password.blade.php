<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Up Your {{ $companyName }} Account</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 0;">

{{-- Preheader: hidden preview text shown by inbox clients next to the subject line --}}
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;opacity:0;">
    Set up your password for {{ $companyName }} on SOJA Time &amp; Attendance — this link expires in 24 hours.
</div>

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0"
                   style="width: 600px; max-width: 100%; background: #ffffff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.07); overflow: hidden;">

                {{-- Header --}}
                <tr>
                    <td style="background: {{ $brandColor }}; padding: 28px 32px; text-align: center;">
                        <p style="margin: 0 0 6px; color: #ffffff; font-size: 12px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; opacity: 0.75;">
                            SOJA Time &amp; Attendance
                        </p>
                        <h1 style="margin: 0; font-size: 22px; color: #ffffff; font-weight: 700; letter-spacing: -0.3px;">
                            Set Up Your Account
                        </h1>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding: 32px;">

                        {{-- Greeting --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
                            <tr>
                                <td width="48" valign="top">
                                    <table cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td width="44" height="44" align="center" valign="middle"
                                                style="width: 44px; height: 44px; border-radius: 50%; background: {{ $brandColor }};
                                                       color: #ffffff; font-size: 15px; font-weight: 700; text-align: center;">
                                                {{ $userInitials }}
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td valign="top" style="padding-left: 14px;">
                                    <p style="margin: 0; color: #111827; font-size: 16px; line-height: 1.5;">
                                        Hi <strong>{{ $user->name }}</strong>,
                                    </p>
                                    <p style="margin: 4px 0 0; color: #6b7280; font-size: 13px;">
                                        An account has been created for you at <strong style="color:#374151;">{{ $companyName }}</strong>.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 0 0 24px; color: #111827; font-size: 15px; line-height: 1.6;">
                            To get started, set a password for your account by clicking the button below.
                        </p>

                        {{-- CTA --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ $url }}"
                                       style="display: inline-block; background: {{ $brandColor }}; color: #ffffff;
                                              text-decoration: none; font-size: 14px; font-weight: 600;
                                              padding: 13px 36px; border-radius: 8px;">
                                        Create Your Password
                                    </a>
                                </td>
                            </tr>
                        </table>

                        {{-- Security note --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
                                      margin-bottom: 24px;">
                            <tr>
                                <td style="padding: 16px 20px;">
                                    <p style="margin: 0 0 6px; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #92400e;">Security Note</p>
                                    <p style="margin: 0; font-size: 14px; color: #78350f; line-height: 1.6;">
                                        This link is unique to you and will expire in <strong>24 hours</strong> from
                                        the time this email was sent. If it has expired, or you run into any
                                        trouble, please contact our support team for help.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 0; font-size: 13px; color: #9ca3af; word-break: break-all;">
                            Or paste this link into your browser: {{ $url }}
                        </p>

                        <p style="margin: 24px 0 0; font-size: 14px; color: #374151;">
                            Best regards,<br>
                            <strong>{{ $companyName }} Team</strong>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background: {{ $brandColor }}; height: 3px; line-height: 3px; font-size: 0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="background: #f9fafb; padding: 18px 32px; text-align: center;">
                        <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                            This is an automated email from {{ $companyName }}. Please do not reply directly.
                        </p>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #d1d5db;">
                            &copy; {{ date('Y') }} {{ $companyName }}. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
