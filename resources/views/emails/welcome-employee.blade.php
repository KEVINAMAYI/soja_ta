<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ $orgName }}</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f9fafb; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 30px;">
                <tr>
                    <td style="text-align: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 20px;">
                        <h2 style="margin: 0; font-size: 24px; color: #111827;">Welcome to {{ $orgName }}</h2>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 20px; color: #374151; font-size: 15px;">
                        <p>Hi {{ $name }},</p>

                        <p>
                            An account has been created for you on <strong style="color: #111827;">{{ $orgName }}</strong>'s
                            Soja TA workspace. You can use the credentials below to sign in:
                        </p>

                        <table cellpadding="0" cellspacing="0" style="width: 100%; margin: 20px 0; background: #f9fafb; border-radius: 6px; padding: 15px;">
                            <tr>
                                <td style="padding: 6px 0; color: #6b7280; font-size: 14px;">Email</td>
                                <td style="padding: 6px 0; color: #111827; font-size: 14px; font-weight: bold;">{{ $email }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 6px 0; color: #6b7280; font-size: 14px;">Temporary password</td>
                                <td style="padding: 6px 0; color: #111827; font-size: 14px; font-weight: bold;">{{ $password }}</td>
                            </tr>
                        </table>

                        <p>For your security, please log in and change this password as soon as possible.</p>

                        <p style="margin-top: 30px;">
                            Regards,<br>
                            <strong>Soja TA</strong> Team
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 30px; font-size: 12px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb;">
                        <p style="margin: 0;">You're receiving this email because an account was created for you on Soja TA.</p>
                        <p style="margin: 5px 0 0;">&copy; {{ date('Y') }} Soja TA. All rights reserved.</p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
