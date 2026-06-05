<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set Up Your {{ $companyName }} Account</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .header {
            background: #004085;
            color: #ffffff;
            text-align: center;
            padding: 20px 30px;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        .content {
            padding: 30px;
            line-height: 1.6;
        }
        .content h2 {
            margin-top: 0;
            font-size: 18px;
            font-weight: 600;
            color: #004085;
        }
        .button {
            display: inline-block;
            margin: 20px 0;
            padding: 12px 24px;
            background: #e14326; /* Custom brand color */
            color: #ffffff !important;
            text-decoration: none;
            font-weight: bold;
            border-radius: 4px;
        }
        .footer {
            background: #f1f1f1;
            text-align: center;
            font-size: 12px;
            color: #666;
            padding: 15px 30px;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <h1>SOJA Time & Attendance</h1>
    </div>

    <!-- Content -->
    <div class="content">
        <h2>Hello {{ $user->name }},</h2>

        <p>We have created an account for you in our <strong>{{ $companyName }} Human Resources Time and Attendance System (SOJA TA)</strong>.</p>

        <p>To get started, you need to set a password for your account. Please click the secure link below:</p>

        <p style="text-align: center;">
            <a href="{{ $url }}" class="button">Create Your Password</a>
        </p>

        <p><strong>Important:</strong> For your security, this link is unique to you and will expire in 24 hours from the time this email was sent.</p>

        <p>If the link has expired or if you encounter any issues, please contact our support team and we will be happy to assist you.</p>

        <p>Best regards,<br>
            <strong>{{ $companyName }} Team</strong></p>
    </div>

    <!-- Footer -->
    <div class="footer">
        This is an automated email. Please do not reply directly.<br>
        &copy; {{ date('Y') }} {{ $companyName }}. All rights reserved.
    </div>
</div>
</body>
</html>
