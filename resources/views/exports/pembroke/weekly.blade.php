<?php
$logoDataUri = $data['logoDataUri'] ?? null;
$orgName     = $data['orgName'] ?? 'School';
?>
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; background: #ffffff; }
        .top-bar { width: 100%; height: 6px; background-color: #0f172a; }

        /* HEADER */
        .header-table { width: 100%; background-color: #f8fafc; padding: 18px 28px; border-bottom: 2px solid #e2e8f0; }
        .header-table td { vertical-align: middle; padding: 0; }
        .header-logo { width: 110px; }
        .header-logo img { max-height: 70px; max-width: 100px; object-fit: contain; }
        .logo-circle { width: 52px; height: 52px; border-radius: 26px; background-color: #0f172a; color: #fff; font-size: 20px; font-weight: 700; text-align: center; line-height: 52px; }
        .header-org { padding-left: 14px; }
        .org-name { font-size: 17px; font-weight: 700; color: #0f172a; }
        .org-subtitle { font-size: 10px; color: #64748b; margin-top: 3px; }
        .header-meta { text-align: right; width: 200px; }
        .meta-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; }
        .meta-date { font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .meta-sub { font-size: 9px; color: #64748b; margin-top: 2px; }

        /* BODY */
        .body { padding: 20px 28px 0 28px; }
        .section-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 10px; }

        /* WEEK BADGE */
        .week-badge-table { width: 100%; margin-bottom: 20px; margin-top: 18px; }
        .week-badge-table td { vertical-align: middle; padding: 0; }
        .week-badge {
            display: inline-block;
            background-color: #f1f5f9;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
        }
        .week-badge-sub {
            font-size: 9px;
            font-weight: 400;
            color: #64748b;
            margin-top: 2px;
        }
        .enrolled-pill {
            display: inline-block;
            background-color: #ede9fe;
            color: #7c3aed;
            font-size: 9px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 99px;
        }

        /* DATA TABLE */
        .data-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .data-table thead tr { background-color: #0f172a; }
        .data-table thead th { color: #fff; padding: 9px 12px; text-align: left; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .data-table tbody tr.even-row { background-color: #f8fafc; }
        .data-table tbody td { padding: 10px 12px; color: #334155; vertical-align: middle; }

        .day-label { font-weight: 700; color: #0f172a; font-size: 11px; }
        .badge { display: inline-block; padding: 3px 9px; font-size: 8.5px; font-weight: 700; }
        .rate-good { background-color: #dcfce7; color: #15803d; border-radius: 99px; }
        .rate-warn { background-color: #fef9c3; color: #a16207; border-radius: 99px; }
        .rate-bad  { background-color: #fee2e2; color: #dc2626; border-radius: 99px; }
        .c-green { color: #16a34a; font-weight: 600; }
        .c-amber { color: #d97706; font-weight: 600; }
        .c-red   { color: #dc2626; font-weight: 600; }
        .c-grey  { color: #64748b; }

        /* RATE BAR */
        .bar-wrap { width: 60px; height: 5px; background-color: #f1f5f9; border-radius: 99px; display: inline-block; vertical-align: middle; margin-right: 6px; overflow: hidden; }
        .bar-fill  { height: 5px; border-radius: 99px; }

        /* FOOTER */
        .footer-table { width: 100%; background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 10px 28px; margin-top: 20px; }
        .footer-table td { vertical-align: middle; font-size: 8.5px; color: #94a3b8; padding: 0; }
        .footer-org { font-weight: 700; color: #64748b; }
        .footer-right { text-align: right; }
        .bottom-bar { width: 100%; height: 4px; background-color: #0f172a; margin-top: 14px; }
    </style>
</head>
<body>

<div class="top-bar"></div>

{{-- HEADER --}}
<table class="header-table" cellspacing="0" cellpadding="0">
    <tr>
        <td class="header-logo">
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="{{ $orgName }}">
            @else
                <div class="logo-circle">{{ strtoupper(substr($orgName, 0, 1)) }}</div>
            @endif
        </td>
        <td class="header-org">
            <div class="org-name">{{ $orgName }}</div>
            <div class="org-subtitle">Weekly Overview</div>
        </td>
        <td class="header-meta">
            <div class="meta-label">Week</div>
            <div class="meta-date">{{ $data['weekLabel'] }}</div>
            <div class="meta-sub" style="margin-top:4px; color:#94a3b8;">Generated {{ now()->format('d M Y, H:i') }}</div>
        </td>
    </tr>
</table>

{{-- BODY --}}
<div class="body">

    {{-- Week info badge --}}
    <table class="week-badge-table" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <div class="week-badge">
                    {{ $data['weekLabel'] }}
                    <div class="week-badge-sub">7-day attendance overview</div>
                </div>
            </td>
            <td style="text-align:right;">
                <span class="enrolled-pill">{{ $data['totalStudents'] }} students enrolled</span>
            </td>
        </tr>
    </table>

    {{-- Weekly Table --}}
    <div class="section-label">Day by Day</div>
    <table class="data-table" cellspacing="0" cellpadding="0">
        <thead>
        <tr>
            <th>Day</th>
            <th>Date</th>
            <th>In School</th>
            <th>Left School</th>
            <th>Not Reported</th>
            <th>Enrolled</th>
            <th>Rate</th>
        </tr>
        </thead>
        <tbody>
        @foreach($data['rows'] as $i => $row)
            <tr class="{{ $i % 2 === 1 ? 'even-row' : '' }}">
                <td class="day-label">{{ $row['day'] }}</td>
                <td class="c-grey">{{ $row['date'] }}</td>
                <td class="c-green">{{ $row['present'] }}</td>
                <td class="c-amber">{{ $row['departed'] }}</td>
                <td class="c-red">{{ $row['notIn'] }}</td>
                <td class="c-grey">{{ $row['total'] }}</td>
                <td>
                    @php $barColor = $row['rate'] >= 80 ? '#22c55e' : ($row['rate'] >= 60 ? '#f59e0b' : '#ef4444'); @endphp
                    <div class="bar-wrap"><div class="bar-fill" style="width:{{ $row['rate'] }}%; background-color:{{ $barColor }};"></div></div>
                    <span class="badge {{ $row['rate'] >= 80 ? 'rate-good' : ($row['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                            {{ $row['rate'] }}%
                        </span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

</div>

{{-- FOOTER --}}
<table class="footer-table" cellspacing="0" cellpadding="0">
    <tr>
        <td>
            <span class="footer-org">{{ $orgName }}</span>
            &nbsp;&middot;&nbsp; Confidential
            &nbsp;&middot;&nbsp; 7-day overview
        </td>
        <td class="footer-right">
            Printed {{ now()->format('d M Y, H:i') }}
        </td>
    </tr>
</table>

<div class="bottom-bar"></div>

</body>
</html>
