<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Check-in Approval Required — {{ $employeeName }}</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0"
                   style="background: #ffffff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.07); overflow: hidden;">

                {{-- Header --}}
                <tr>
                    <td style="background: {{ $brandColor }}; padding: 28px 32px; text-align: center;">
                        <h1 style="margin: 0; font-size: 22px; color: #ffffff; font-weight: 700; letter-spacing: -0.3px;">
                            ⏰ Check-in Approval Required
                        </h1>
                        <p style="margin: 6px 0 0; color: #ffffff; font-size: 13px; opacity: 0.85;">
                            {{ $orgName }}
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding: 32px;">

                        <p style="margin: 0 0 6px; color: #6b7280; font-size: 14px;">Hello,</p>
                        <p style="margin: 0 0 24px; color: #111827; font-size: 15px; line-height: 1.6;">
                            <strong>{{ $employeeName }}</strong> checked in
                            <strong style="color: #d97706;">{{ $minutesLate }} minute(s) late</strong>
                            on {{ $date }} and requires your approval
                            @if($approverRole)
                                as <strong>{{ $approverRole }}</strong>
                            @endif
                            .
                        </p>

                        {{-- Details Card --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
                                      margin-bottom: 24px; overflow: hidden;">
                            <tr>
                                <td width="50%" style="padding: 14px 20px; border-right: 1px solid #fde68a;">
                                    <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #92400e;">Date</p>
                                    <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                              color: #111827;">{{ $date }}</p>
                                </td>
                                <td width="50%" style="padding: 14px 20px;">
                                    <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #92400e;">Minutes Late</p>
                                    <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                              color: #d97706;">{{ $minutesLate }} min</p>
                                </td>
                            </tr>
                        </table>

                        {{-- CTA --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 8px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ $reviewUrl }}"
                                       style="display: inline-block; background: {{ $brandColor }}; color: #ffffff;
                                              text-decoration: none; font-size: 14px; font-weight: 600;
                                              padding: 12px 32px; border-radius: 8px;">
                                        Review Request
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280; line-height: 1.6;">
                            If no action is taken before the timeout, this request will be escalated or
                            auto-resolved according to your organization's approval policy.
                        </p>

                        <p style="margin: 24px 0 0; font-size: 14px; color: #374151;">
                            Regards,<br>
                            <strong>{{ $orgName }}</strong>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background: #f9fafb; border-top: 1px solid #e5e7eb;
                                padding: 18px 32px; text-align: center;">
                        <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                            This is an automated notification from {{ $orgName }}.
                        </p>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #d1d5db;">
                            &copy; {{ date('Y') }} {{ $orgName }}. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
