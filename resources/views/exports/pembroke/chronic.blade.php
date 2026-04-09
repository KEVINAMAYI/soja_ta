<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; background: #fff; padding: 24px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #0f172a; padding-bottom: 12px; }
        .header-left .org { font-size: 17px; font-weight: 700; color: #0f172a; }
        .header-left .report-title { font-size: 13px; color: #475569; margin-top: 2px; }
        .header-right { text-align: right; font-size: 10px; color: #94a3b8; }
        .alert-banner { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 10.5px; color: #92400e; font-weight: 600; }
        .meta-badge { display: inline-block; background: #f1f5f9; border-radius: 6px; padding: 4px 10px; font-size: 10px; color: #475569; font-weight: 600; margin-bottom: 14px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        thead tr { background: #0f172a; color: #fff; }
        thead th { padding: 8px 10px; text-align: left; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 7px 10px; color: #334155; }
        .badge-grade { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 600; }
        .rate-good { background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .rate-warn { background: #fef9c3; color: #a16207; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .rate-bad  { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 9px; color: #94a3b8; }
        .color-green { color: #16a34a; font-weight: 600; }
        .color-amber { color: #d97706; font-weight: 600; }
        .color-red   { color: #dc2626; font-weight: 600; }
        .color-grey  { color: #64748b; }
        .student-name { font-weight: 600; color: #0f172a; }
    </style>
</head>
<body>
<div class="header">
    <div class="header-left">
        <div class="org">{{ $data['orgName'] ?? 'School' }}</div>
        <div class="report-title">Low Presence Report — Students Below {{ $data['threshold'] }}%</div>
    </div>
    <div class="header-right">
        Generated: {{ now()->format('d M Y, H:i') }}<br>
        Period: {{ \Carbon\Carbon::parse($data['termStart'])->format('d M') }} – {{ \Carbon\Carbon::parse($data['termEnd'])->format('d M Y') }}
    </div>
</div>

<div class="alert-banner">
    ⚠ {{ $data['total'] }} student{{ $data['total'] !== 1 ? 's' : '' }} flagged with attendance below {{ $data['threshold'] }}%
    &nbsp;·&nbsp; Period: {{ \Carbon\Carbon::parse($data['termStart'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($data['termEnd'])->format('d M Y') }}
    &nbsp;·&nbsp; {{ $data['totalDays'] }} school days
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Student Name</th>
        <th>Grade</th>
        <th>Days In School</th>
        <th>Days Left School</th>
        <th>Days Not Reported</th>
        <th>Attendance Rate</th>
    </tr>
    </thead>
    <tbody>
    @forelse($data['rows'] as $i => $row)
        <tr>
            <td class="color-grey">{{ $i + 1 }}</td>
            <td class="student-name">{{ $row['name'] }}</td>
            <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
            <td class="color-green">{{ $row['present'] }}</td>
            <td class="color-amber">{{ $row['departed'] }}</td>
            <td class="color-red">{{ $row['notIn'] }}</td>
            <td>
                        <span class="{{ $row['rate'] >= 80 ? 'rate-good' : ($row['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                            {{ $row['rate'] }}%
                        </span>
            </td>
        </tr>
    @empty
        <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">No students flagged.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="footer">
    <span>{{ $data['orgName'] ?? '' }} — Confidential · {{ $data['total'] }} students flagged</span>
    <span>Printed {{ now()->format('d M Y H:i') }}</span>
</div>
</body>
</html>
