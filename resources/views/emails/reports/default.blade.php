<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ ucwords(str_replace('_', ' ', $reportType)) }} Report</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f9fafb; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 30px;">
                <tr>
                    <td style="text-align: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 20px;">
                        <h2 style="margin: 0; font-size: 24px; color: #111827;">
                            {{ ucwords(str_replace('_', ' ', $reportType)) }} Report
                        </h2>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 20px; color: #374151; font-size: 15px;">
                        <p>Hello,</p>

                        <p>
                            Please find attached your
                            <strong style="color: #111827;">
                                {{ ucwords(str_replace('_', ' ', $reportType)) }}
                            </strong> report.
                        </p>

                        <p>
                            This report is generated on a
                            <strong style="color: #111827;">
                                {{ ucfirst($frequency) }}
                            </strong> basis.
                        </p>

                        <p style="margin-top: 30px;">
                            Regards,<br>
                            <strong>{{ config('app.name') }}</strong> Team
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 30px; font-size: 12px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb;">
                        <p style="margin: 0;">You’re receiving this email because you subscribed to reports from {{ config('app.name') }}.</p>
                        <p style="margin: 5px 0 0;">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
