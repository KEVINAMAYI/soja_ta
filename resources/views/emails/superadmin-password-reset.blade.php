<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your password</title>
</head>

<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background-color:#f4f6f8;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0"
                    style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#072639;padding:24px;color:#ffffff;font-size:20px;font-weight:bold;">
                            SOJA TA
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px;font-size:16px;">Hello {{ $name }},</p>

                            <p style="margin:0 0 16px;font-size:15px;line-height:22px;">
                                We received a request to reset the password for your super admin account.
                                Click the button below to choose a new password.
                            </p>

                            <p style="margin:0 0 24px;text-align:center;">
                                <a href="{{ $resetUrl }}"
                                    style="display:inline-block;background-color:#072639;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:6px;font-size:15px;font-weight:bold;">
                                    Reset password
                                </a>
                            </p>

                            <p style="margin:0 0 16px;font-size:14px;line-height:22px;color:#4b5563;">
                                This link expires in {{ $expiresInHours }} hours and can only be used once.
                                Requesting a new reset link immediately cancels this one.
                            </p>

                            <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">
                                If the button does not work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px;font-size:13px;word-break:break-all;color:#2563eb;">
                                {{ $resetUrl }}
                            </p>

                            <p style="margin:0;font-size:13px;color:#6b7280;">
                                If you did not request a password reset, you can safely ignore this email &mdash;
                                your password will remain unchanged.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#f9fafb;padding:16px 24px;font-size:12px;color:#9ca3af;">
                            This is an automated message, please do not reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
