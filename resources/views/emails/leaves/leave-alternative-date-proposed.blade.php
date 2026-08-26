<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update on Your Leave Request – Proposed Alternative Dates</title>
</head>
<body style="margin:0; padding:0; background-color:#f2f2f2; font-family:Arial, Helvetica, sans-serif; color:#243b53;">

    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
        Your leave request has been reviewed and alternative dates have been proposed.
    </div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f2f2f2; margin:0; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:720px; background:#ffffff; border:1px solid #e7e7e7; border-radius:10px; overflow:hidden;">

                    <tr>
                        <td style="padding:0 32px 0 32px; background:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
                                <tr>
                                    <td style="font-size:14px; line-height:1.6; color:#1f2d3d; font-weight:600; padding-bottom:10px;">
                                        Hi {{ $employeeName ?? '{employee_first_name}' }},
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:6px;">
                                        Thank you for submitting your leave request for {{ $originalStartDate ?? '{original_start_date}' }} to {{ $originalEndDate ?? '{original_end_date}' }}.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:18px;">
                                        After reviewing your request, your supervisor is unable to approve the leave for the original dates due to operational requirements and/or staffing needs.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:18px;">
                                        However, your supervisor has suggested the following alternative dates:
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px; background:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#efe3c2; border:1px solid #e3d1a1; border-radius:8px;">
                                <tr>
                                    <td style="padding:18px 20px 14px 20px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td valign="top" width="34" style="padding-right:12px;">
                                                    <div style="width:28px; height:28px; line-height:28px; text-align:center; font-size:18px; background:#f5d48f; border:1px solid #d8b05a; border-radius:6px; color:#8f5b00;">📅</div>
                                                </td>
                                                <td valign="top">
                                                    <div style="font-size:18px; line-height:1.3; color:#1f2d3d; font-weight:700; margin-bottom:10px;">
                                                        Proposed Alternative Leave Details
                                                    </div>
                                                    <div style="font-size:14px; line-height:1.8; color:#1f2d3d;">
                                                        <div><span style="font-weight:700;">Leave Type:</span> {{ $leaveTypeName ?? '{leave_type}' }}</div>
                                                        <div><span style="font-weight:700;">Proposed Start Date:</span> {{ $newStartDate ?? '{new_start_date}' }}</div>
                                                        <div><span style="font-weight:700;">Proposed End Date:</span> {{ $newEndDate ?? '{new_end_date}' }}</div>
                                                        <div><span style="font-weight:700;">Total Days:</span> {{ $newNumberOfDays ?? '{new_number_of_days}' }}</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px 0 32px; background:#ffffff;">
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; margin-bottom:16px;">
                                Please let us know if the proposed dates work for you by selecting one of the options below.
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px; background:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:14px;">
                                <tr>
                                    <td width="50%" style="padding:0 8px 0 0;">
                                        <a href="{{ $acceptUrl ?? '#' }}" style="display:block; background:#1f9d66; color:#ffffff; text-decoration:none; border-radius:8px; text-align:center; padding:20px 16px; font-size:18px; font-weight:700; line-height:1.2;">
                                            <span style="font-size:28px; line-height:1; vertical-align:middle; display:inline-block; margin-right:8px;">✓</span>Accept Proposed Dates
                                        </a>
                                    </td>
                                    <td width="50%" style="padding:0 0 0 8px;">
                                        <a href="{{ $rejectUrl ?? '#' }}" style="display:block; background:#e35c4f; color:#ffffff; text-decoration:none; border-radius:8px; text-align:center; padding:20px 16px; font-size:18px; font-weight:700; line-height:1.2;">
                                            <span style="font-size:28px; line-height:1; vertical-align:middle; display:inline-block; margin-right:8px;">✕</span>Reject Proposed Dates
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 32px 0 32px; background:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#dfeef8; border:1px solid #b7d8ec; border-radius:8px; margin-top:8px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <div style="font-size:18px; line-height:1.4; color:#1f2d3d; font-weight:700; margin-bottom:8px;">
                                            <span style="display:inline-block; width:22px; height:22px; line-height:22px; text-align:center; border-radius:50%; background:#4b96c5; color:#ffffff; font-size:14px; margin-right:8px;">i</span>What happens if you reject?
                                        </div>
                                        <div style="font-size:14px; line-height:1.7; color:#1f2d3d;">
                                            If you reject the proposed dates, your response will be sent to your supervisor. They will review your feedback and get back to you with next steps, which may include discussing other possible dates.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 32px 0 32px; background:#ffffff;">
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:12px;">
                                Once you confirm, we will proceed with the necessary leave approval and processing.
                            </div>
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:8px;">
                                If you have any questions, feel free to reach out to your line manager or HR.
                            </div>
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:12px;">
                                Thank you for your understanding and cooperation.
                            </div>
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:6px;">
                                Best regards,
                            </div>
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; font-weight:700; padding-bottom:0;">
                                — SOJA T&amp;A
                            </div>
                            <div style="font-size:14px; line-height:1.7; color:#1f2d3d; padding-bottom:10px;">
                                {{ $companyName ?? '{company_name}' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
