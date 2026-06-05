<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Request — {{ $employeeName }}</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0"
                   style="background: #ffffff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.07); overflow: hidden;">

                {{-- Header --}}
                <tr>
                    <td style="background: #e14326; padding: 28px 32px; text-align: center;">
                        <h1 style="margin: 0; font-size: 22px; color: #ffffff; font-weight: 700; letter-spacing: -0.3px;">
                            Leave Request Submitted
                        </h1>
                        <p style="margin: 6px 0 0; color: white; font-size: 13px;">
                            {{ $orgName }}
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding: 32px;">

                        <p style="margin: 0 0 6px; color: #6b7280; font-size: 14px;">Hello,</p>
                        <p style="margin: 0 0 24px; color: #111827; font-size: 15px; line-height: 1.6;">
                            <strong>{{ $employeeName }}</strong> has submitted a leave request that requires your attention.
                        </p>

                        {{-- Details Card --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #fff8f6; border: 1px solid #f5c4b3; border-radius: 8px;
                                      margin-bottom: 24px; overflow: hidden;">
                            <tr>
                                <td style="padding: 16px 20px; border-bottom: 1px solid #f5c4b3;">
                                    <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #6b7280;">Leave Type</p>
                                    <p style="margin: 4px 0 0; font-size: 16px; font-weight: 600; color: #e14326;">
                                        {{ $leaveType }}
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td width="50%" style="padding: 14px 20px; border-right: 1px solid #dbeafe;
                                                                    border-bottom: 1px solid #dbeafe;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">Start Date</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #111827;">{{ $startDate }}</p>
                                            </td>
                                            <td width="50%" style="padding: 14px 20px;
                                                                    border-bottom: 1px solid #dbeafe;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">End Date</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #111827;">{{ $endDate }}</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="padding: 14px 20px;">
                                                <p style="margin: 0; font-size: 11px; text-transform: uppercase;
                                                          letter-spacing: 0.8px; color: #6b7280;">Expected Resumption</p>
                                                <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600;
                                                          color: #059669;">{{ $resumption }}</p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        {{-- Reason --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
                                      margin-bottom: 24px;">
                            <tr>
                                <td style="padding: 16px 20px;">
                                    <p style="margin: 0 0 6px; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #92400e;">Reason</p>
                                    <p style="margin: 0; font-size: 14px; color: #78350f; line-height: 1.6;">
                                        {{ $reason }}
                                    </p>
                                </td>
                            </tr>
                        </table>

                        {{-- Handover (conditional) --}}
                        @if ($handoverName)
                            <table width="100%" cellpadding="0" cellspacing="0"
                                   style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
                                      margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 14px 20px;">
                                        <p style="margin: 0 0 4px; font-size: 11px; text-transform: uppercase;
                                              letter-spacing: 0.8px; color: #15803d;">Handover To</p>
                                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: #14532d;">
                                            {{ $handoverName }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        {{-- Status badge --}}
                        <p style="margin: 0 0 24px; font-size: 14px; color: #374151;">
                            Status:&nbsp;
                            <span style="display: inline-block; background: #fef9c3; color: #854d0e;
                                         border: 1px solid #fde047; border-radius: 20px;
                                         padding: 2px 12px; font-size: 12px; font-weight: 600;">
                                Pending Review
                            </span>
                        </p>

                        <p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.6;">
                            Please review this request and take the appropriate action in the system.
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
