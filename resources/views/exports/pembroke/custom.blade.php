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
        .meta-badge { display: inline-block; background: #f1f5f9; border-radius: 6px; padding: 4px 10px; font-size: 10px; color: #475569; font-weight: 600; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        thead tr { background: #0f172a; color: #fff; }
        thead th { padding: 8px 10px; text-align: left; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 7px 10px; color: #334155; }
        tbody tr.total-row { background: #f1f5f9 !important; font-weight: 700; border-top: 2px solid #e2e8f0; }
        tbody tr.total-row td { color: #0f172a; }
        .badge-grade { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 600; }
        .rate-good { background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .rate-warn { background: #fef9c3; color: #a16207; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .rate-bad  { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 9px; color: #94a3b8; }
        .color-green { color: #16a34a; font-weight: 600; }
        .color-amber { color: #d97706; font-weight: 600; }
        .color-red   { color: #dc2626; font-weight: 600; }
        .color-grey  { color: #64748b; }
    </style>
</head>
<body>
<div class="header">
    <div class="header-left">
        <div class="org">{{ $data['orgName'] ?? 'School' }}</div>
        <div class="report-title">Custom Date Range Report</div>
    </div>
    <div class="header-right">
        Generated: {{ now()->format('d M Y, H:i') }}<br>
        Range: {{ $data['rangeLabel'] }}
    </div>
</div>

<div class="meta-badge">
    {{ $data['rangeLabel'] }} &nbsp;·&nbsp; {{ $data['totalDays'] }} school days
</div>

<table>
    <thead>
    <tr>
        <th>Grade</th>
        <th>Days In School</th>
        <th>Days Left School</th>
        <th>Days Not Reported</th>
        <th>Enrolled</th>
        <th>School Days</th>
        <th>Rate</th>
    </tr>
    </thead>
    <tbody>
    @foreach($data['rows'] as $row)
        <tr>
            <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
            <td class="color-green">{{ $row['present'] }}</td>
            <td class="color-amber">{{ $row['departed'] }}</td>
            <td class="color-red">{{ $row['notIn'] }}</td>
            <td class="color-grey">{{ $row['total'] }}</td>
            <td class="color-grey">{{ $data['totalDays'] }}</td>
            <td>
                        <span class="{{ $row['rate'] >= 80 ? 'rate-good' : ($row['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                            {{ $row['rate'] }}%
                        </span>
            </td>
        </tr>
    @endforeach
    <tr class="total-row">
        <td>TOTAL</td>
        <td class="color-green">{{ $data['totals']['present'] }}</td>
        <td class="color-amber">{{ $data['totals']['departed'] }}</td>
        <td class="color-red">{{ $data['totals']['notIn'] }}</td>
        <td>{{ $data['totals']['total'] }}</td>
        <td>{{ $data['totalDays'] }}</td>
        <td>
                    <span class="{{ $data['totals']['rate'] >= 80 ? 'rate-good' : ($data['totals']['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                        {{ $data['totals']['rate'] }}%
                    </span>
        </td>
    </tr>
    </tbody>
</table>

<div class="footer">
    <span>{{ $data['orgName'] ?? '' }} — Confidential · {{ count($data['rows']) }} grades</span>
    <span>Printed {{ now()->format('d M Y H:i') }}</span>
</div>
</body>
</html>
