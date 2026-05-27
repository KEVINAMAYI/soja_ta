<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reportType }} Report</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f1f5f9; padding: 32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 600px; width: 100%; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);">

                <!-- Header band -->
                <tr>
                    <td style="background-color: #4f46e5; padding: 28px 32px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td style="vertical-align: middle;">
                                    <table cellpadding="0" cellspacing="0" role="presentation">
                                        <tr>
                                            <td style="width: 40px; height: 40px; background-color: rgba(255,255,255,0.15); border-radius: 8px; text-align: center; vertical-align: middle; font-size: 20px;">
                                                📊
                                            </td>
                                            <td style="padding-left: 12px; vertical-align: middle;">
                                                <span style="color: #ffffff; font-size: 16px; font-weight: 700; letter-spacing: .2px;">Soja TA</span>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td align="right" style="vertical-align: middle;">
                                    <span style="display: inline-block; background-color: rgba(255,255,255,0.15); color: #ffffff; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; padding: 5px 10px; border-radius: 999px;">
                                        {{ ucfirst($frequency) }}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Title -->
                <tr>
                    <td style="padding: 28px 32px 4px;">
                        <h1 style="margin: 0; font-size: 21px; line-height: 1.3; color: #0f172a;">
                            {{ $reportType }} Report
                        </h1>
                        <p style="margin: 6px 0 0; font-size: 14px; color: #64748b;">
                            for {{ $organization }}
                        </p>
                    </td>
                </tr>

                <!-- Body copy -->
                <tr>
                    <td style="padding: 16px 32px 0; color: #334155; font-size: 15px; line-height: 1.6;">
                        <p style="margin: 0 0 16px;">Hello,</p>
                        <p style="margin: 0;">
                            Your scheduled <strong style="color: #0f172a;">{{ $reportType }}</strong> report is ready.
                            It's attached to this email — details below.
                        </p>
                    </td>
                </tr>

                <!-- Details card -->
                <tr>
                    <td style="padding: 20px 32px 0;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
                            <tr>
                                <td style="padding: 16px 20px; font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0;">Report type</td>
                                <td style="padding: 16px 20px; font-size: 13px; color: #0f172a; font-weight: 600; text-align: right; border-bottom: 1px solid #e2e8f0;">{{ $reportType }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 16px 20px; font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0;">Frequency</td>
                                <td style="padding: 16px 20px; font-size: 13px; color: #0f172a; font-weight: 600; text-align: right; border-bottom: 1px solid #e2e8f0;">{{ ucfirst($frequency) }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 16px 20px; font-size: 13px; color: #64748b;">Generated</td>
                                <td style="padding: 16px 20px; font-size: 13px; color: #0f172a; font-weight: 600; text-align: right;">{{ $generatedAt }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Attachment chip -->
                <tr>
                    <td style="padding: 16px 32px 0;">
                        <table cellpadding="0" cellspacing="0" role="presentation" style="background-color: #eef2ff; border-radius: 8px;">
                            <tr>
                                <td style="padding: 12px 16px; vertical-align: middle; font-size: 18px;">📎</td>
                                <td style="padding: 12px 16px 12px 0; vertical-align: middle;">
                                    <span style="display: block; font-size: 13px; font-weight: 600; color: #1e1b4b;">{{ $fileName }}</span>
                                    <span style="display: block; font-size: 12px; color: #6366f1;">{{ $fileExt }} attachment</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 24px 32px 28px; color: #334155; font-size: 15px;">
                        <p style="margin: 0;">
                            Regards,<br>
                            <strong style="color: #0f172a;">Soja TA</strong> Team
                        </p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding: 20px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;">
                        <p style="margin: 0;">You're receiving this email because you subscribed to reports from Soja TA.</p>
                        <p style="margin: 6px 0 0;">&copy; {{ date('Y') }} Soja TA. All rights reserved.</p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
