<?php
$firstAttendance = collect($attendances)->first();
$organization = $firstAttendance->employee->organization ?? null;
$logoDataUri = null;
$initials = 'XX';

if ($organization) {
    $initials = strtoupper(substr($organization->name, 0, 2));

    if ($organization->logo_path && file_exists(storage_path('app/public/' . $organization->logo_path))) {
        $path = storage_path('app/public/' . $organization->logo_path);
        $type = pathinfo($path, PATHINFO_EXTENSION);

        $mime = 'image/' . ($type === 'svg' ? 'svg+xml' : $type);
        $data = file_get_contents($path);
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 20px;
        }

        .header {
            display: flex;
            flex-direction: column;
            align-items: center;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 25px;
            margin-bottom: 25px;
            text-align: center;
        }

        .header-logo {
            max-width: 120px;
            max-height: 120px;
            border-radius: 50%;
            margin-bottom: 10px;
        }

        .header-org-name {
            width: 100%;
            padding: 15px;
            color: #2c3e50;
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }

        .header-content h1 {
            font-size: 24px;
            margin: 0;
            color: #2c3e50;
        }

        .header-content .meta {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px 12px;
            font-size: 12px;
        }

        th {
            background: #2c3e50;
            color: #fff;
            text-align: left;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        .badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #fff;
        }

        .bg-success {
            background-color: #27ae60;
        }

        .bg-danger {
            background-color: #e74c3c;
        }

        .bg-warning {
            background-color: #f1c40f;
            color: #2c3e50;
        }
    </style>
</head>
<body>
<div class="header">
    @if(empty($isExcel))
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" class="header-logo" alt="Organization Logo">
        @else
            <div class="header-org-name">
                {{ $organization?->name ?? 'Organization' }}
            </div>
        @endif
    @endif

    <div class="header-content">
        <h1>{{ $title }}</h1>
        <div class="meta">Generated on {{ now()->format('d M Y, H:i') }}</div>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>Month</th>
        <th>Employee</th>
        <th>Present</th>
        <th>Absent</th>
        <th>Leave</th>
        <th>Total Days</th>
        <th>Working Hours</th>
        <th>OT Hours</th>
    </tr>
    </thead>
    <tbody>
    @foreach($attendances as $attendance)
        <tr>
            <td>{{ \Carbon\Carbon::createFromFormat('Y-m', $attendance->attendance_month)->format('F Y') }}</td>
            <td>{{ $attendance->employee->name ?? 'N/A' }}</td>
            <td><span class="badge bg-success">{{ $attendance->present_days }}</span></td>
            <td><span class="badge bg-danger">{{ $attendance->absent_days }}</span></td>
            <td><span class="badge bg-warning">{{ $attendance->leave_days }}</span></td>
            <td>{{ $attendance->total_days }}</td>
            <td>{{ number_format($attendance->total_worked_hours, 2) }}</td>
            <td>{{ number_format($attendance->total_ot_hours, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
