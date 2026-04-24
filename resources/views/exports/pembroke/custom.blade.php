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
        .header-meta { text-align: right; width: 210px; }
        .meta-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; }
        .meta-date { font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .meta-sub { font-size: 9px; color: #64748b; margin-top: 2px; }

        /* BODY */
        .body { padding: 20px 28px 0 28px; }
        .section-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 10px; }

        /* CARDS */
        .cards-table { width: 100%; margin-bottom: 22px; }
        .cards-table td { width: 20%; padding-right: 8px; vertical-align: top; }
        .cards-table td:last-child { padding-right: 0; }
        .card-box { padding: 14px 16px; border-radius: 8px; }
        .card-num { font-size: 26px; font-weight: 700; line-height: 1; }
        .card-lbl { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; }

        /* DATA TABLE */
        .data-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .data-table thead tr { background-color: #0f172a; }
        .data-table thead th { color: #fff; padding: 9px 12px; text-align: left; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .data-table tbody tr.even-row { background-color: #f8fafc; }
        .data-table tbody td { padding: 9px 12px; color: #334155; vertical-align: middle; }
        .data-table tbody tr.total-row { background-color: #f1f5f9 !important; border-top: 2px solid #e2e8f0; }
        .data-table tbody tr.total-row td { font-weight: 700; color: #0f172a; }

        .badge { display: inline-block; padding: 3px 9px; font-size: 8.5px; font-weight: 700; }
        .badge-grade { background-color: #f1f5f9; color: #475569; border-radius: 99px; }
        .rate-good   { background-color: #dcfce7; color: #15803d; border-radius: 99px; }
        .rate-warn   { background-color: #fef9c3; color: #a16207; border-radius: 99px; }
        .rate-bad    { background-color: #fee2e2; color: #dc2626; border-radius: 99px; }
        .c-green { color: #16a34a; font-weight: 600; }
        .c-amber { color: #d97706; font-weight: 600; }
        .c-red   { color: #dc2626; font-weight: 600; }
        .c-grey  { color: #64748b; }

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
            <div class="org-subtitle">Custom Date Range Report</div>
        </td>
        <td class="header-meta">
            <div class="meta-label">Date Range</div>
            <div class="meta-date" style="font-size:12px;">{{ $data['rangeLabel'] }}</div>
            <div class="meta-sub">{{ $data['totalDays'] }} school day(s)</div>
            <div class="meta-sub" style="margin-top:4px; color:#94a3b8;">Generated {{ now()->format('H:i') }}</div>
        </td>
    </tr>
</table>

{{-- BODY --}}
<div class="body">

    <div class="section-label" style="margin-top:18px;">Overview</div>
    <table class="cards-table" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <div class="card-box" style="background-color:#dcfce7;">
                    <div class="card-num" style="color:#15803d;">{{ $data['totals']['present'] }}</div>
                    <div class="card-lbl" style="color:#15803d;">In School</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#fef9c3;">
                    <div class="card-num" style="color:#d97706;">{{ $data['totals']['departed'] }}</div>
                    <div class="card-lbl" style="color:#d97706;">Left School</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#fee2e2;">
                    <div class="card-num" style="color:#dc2626;">{{ $data['totals']['notIn'] }}</div>
                    <div class="card-lbl" style="color:#dc2626;">Not Reported</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#f1f5f9;">
                    <div class="card-num" style="color:#475569;">{{ $data['totals']['total'] }}</div>
                    <div class="card-lbl" style="color:#475569;">Total Enrolled</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#ede9fe;">
                    <div class="card-num" style="color:#7c3aed;">{{ $data['totals']['rate'] }}%</div>
                    <div class="card-lbl" style="color:#7c3aed;">Attendance Rate</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section-label">Grade Breakdown</div>
    <table class="data-table" cellspacing="0" cellpadding="0">
        <thead>
        <tr>
            <th>Year Group</th>
            <th>Days In School</th>
            <th>Days Left School</th>
            <th>Days Not Reported</th>
            <th>Enrolled</th>
            <th>School Days</th>
            <th>Rate</th>
        </tr>
        </thead>
        <tbody>
        @foreach($data['rows'] as $i => $row)
            <tr class="{{ $i % 2 === 1 ? 'even-row' : '' }}">
                <td><span class="badge badge-grade">{{ $row['grade'] }}</span></td>
                <td class="c-green">{{ $row['present'] }}</td>
                <td class="c-amber">{{ $row['departed'] }}</td>
                <td class="c-red">{{ $row['notIn'] }}</td>
                <td class="c-grey">{{ $row['total'] }}</td>
                <td class="c-grey">{{ $data['totalDays'] }}</td>
                <td>
                    <span class="badge {{ $row['rate'] >= 80 ? 'rate-good' : ($row['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                        {{ $row['rate'] }}%
                    </span>
                </td>
            </tr>
        @endforeach
        <tr class="total-row">
            <td>TOTAL</td>
            <td class="c-green">{{ $data['totals']['present'] }}</td>
            <td class="c-amber">{{ $data['totals']['departed'] }}</td>
            <td class="c-red">{{ $data['totals']['notIn'] }}</td>
            <td>{{ $data['totals']['total'] }}</td>
            <td>{{ $data['totalDays'] }}</td>
            <td>
                <span class="badge {{ $data['totals']['rate'] >= 80 ? 'rate-good' : ($data['totals']['rate'] >= 60 ? 'rate-warn' : 'rate-bad') }}">
                    {{ $data['totals']['rate'] }}%
                </span>
            </td>
        </tr>
        </tbody>
    </table>

</div>

{{-- FOOTER --}}
<table class="footer-table" cellspacing="0" cellpadding="0">
    <tr>
        <td>
            <span class="footer-org">{{ $orgName }}</span>
            &nbsp;&middot;&nbsp; Confidential
            &nbsp;&middot;&nbsp; {{ count($data['rows']) }} grade(s)
            &nbsp;&middot;&nbsp; {{ $data['rangeLabel'] }}
        </td>
        <td class="footer-right">
            Printed {{ now()->format('d M Y, H:i') }}
        </td>
    </tr>
</table>

<div class="bottom-bar"></div>

</body>
</html>
