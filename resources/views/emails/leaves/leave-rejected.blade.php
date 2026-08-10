<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update on Your Leave Request</title>
</head>

<body style="margin: 0; padding: 0; background: #ffffff; color: #111827;">

    {{-- Email Subject:
        Update on Your Leave Request
    --}}

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background: #ffffff; margin: 0; padding: 0;">
        <tr>
            <td align="left" style="padding: 24px;">

                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width: 650px; margin: 0 auto;">

                    {{-- Greeting --}}
                    <tr>
                        <td style="
                            font-family: Arial, Helvetica, sans-serif;
                            font-size: 14px;
                            line-height: 1.6;
                            color: #111827;
                            padding-bottom: 20px;
                        ">
                            Hi {{ $employeeName }},
                        </td>
                    </tr>

                    {{-- Intro --}}
                    <tr>
                        <td style="
                            font-family: Arial, Helvetica, sans-serif;
                            font-size: 14px;
                            line-height: 1.6;
                            color: #111827;
                            padding-bottom: 24px;
                        ">
                            Your leave request ahs been reviewed and, unfortunately, was not approved at tis time.
                        </td>
                    </tr>

                    {{-- Leave Details Heading --}}
                    <tr>
                        <td style="
                            font-family: Arial, Helvetica, sans-serif;
                            font-size: 14px;
                            line-height: 1.6;
                            color: #111827;
                            padding-bottom: 8px;
                        ">
                            Leave Details
                        </td>
                    </tr>

                    {{-- Divider --}}
                    <tr>
                        <td style="
                            font-family: monospace;
                            font-size: 13px;
                            line-height: 1;
                            color: #374151;
                            padding-bottom: 14px;
                        ">
                            ----------------------------
                        </td>
                    </tr>

                    {{-- Leave Details --}}
                    <tr>
                        <td style="
                            font-family: monospace;
                            font-size: 13px;
                            line-height: 1.8;
                            color: #111827;
                            padding-bottom: 22px;
                        ">

                            <table cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        Leave Type:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $leaveTypeName }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        Start Date:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $startDate }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        End Date:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $endDate }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        Total Days:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $totalDays }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        Reviewed By:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $reviewerName ?? 'N/A' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family: monospace; font-size: 13px; padding-right: 8px;">
                                        Date Reviewed:
                                    </td>
                                    <td style="font-family: monospace; font-size: 13px;">
                                        {{ $reviewDate ?? 'N/A' }}
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>

                    {{-- Rejection reason --}}
                    @if($rejectionReason)
                        <tr>
                            <td style="
                                font-family: monospace;
                                font-size: 13px;
                                line-height: 1.7;
                                color: #111827;
                                padding-bottom: 24px;
                            ">
                                <div>
                                    Reason: "{{ $rejectionReason }}"
                                </div>
                            </td>
                        </tr>
                    @endif

                    {{-- Instructions --}}
                    <tr>
                        <td style="
                            font-family: monospace;
                            font-size: 13px;
                            line-height: 1.7;
                            color: #111827;
                            padding-bottom: 22px;
                        ">
                            If you would like to discuss this decision or submit a revised request, please contact your line manager
                            or HR.
                        </td>
                    </tr>

                    {{-- View full status --}}
                    <tr>
                        <td style="
                            font-family: monospace;
                            font-size: 13px;
                            line-height: 1.7;
                            color: #111827;
                            padding-bottom: 22px;
                        ">
                            You can view the full status of this request on your SOJA T&amp;A dashboard.
                        </td>
                    </tr>

                    {{-- Signature --}}
                    <tr>
                        <td style="
                            font-family: monospace;
                            font-size: 13px;
                            line-height: 1.7;
                            color: #111827;
                        ">
                            — SOJA T&amp;A<br>
                            {{ $orgName }}
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>