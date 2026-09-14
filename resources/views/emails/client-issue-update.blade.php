<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support issue update</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <h2>{{ $issue->organization->name }} support update</h2>
    <p>Your support issue has been received or updated by the SOJA TA support team.</p>
    <p><strong>Reference:</strong> {{ $issue->reference }}</p>
    <p><strong>Subject:</strong> {{ $issue->subject }}</p>
    <p><strong>Status:</strong> {{ str_replace('_', ' ', ucfirst($update->status)) }}</p>
    <p><strong>Priority:</strong> {{ ucfirst($issue->priority) }}</p>
    @if ($update->note)
        <p><strong>Progress note:</strong><br>{{ $update->note }}</p>
    @endif
    <p>We will continue to keep you informed as this issue progresses.</p>
    <p>Regards,<br>SOJA TA Support</p>
</body>
</html>
