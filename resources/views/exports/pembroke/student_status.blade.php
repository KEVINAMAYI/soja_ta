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

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1e293b;
            background: #ffffff;
        }

        /* ── TOP BAR ── */
        .top-bar {
            width: 100%;
            height: 6px;
            background-color: #0f172a;
        }

        /* ── HEADER ── */
        .header-table {
            width: 100%;
            background-color: #f8fafc;
            padding: 18px 28px;
            border-bottom: 2px solid #e2e8f0;
        }

        .header-table td {
            vertical-align: middle;
            padding: 0;
        }

        .header-logo {
            width: 110px;
        }

        .header-logo img {
            max-height: 70px;
            max-width: 100px;
            object-fit: contain;
        }

        .logo-circle {
            width: 52px;
            height: 52px;
            border-radius: 26px;
            background-color: #0f172a;
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            line-height: 52px;
        }

        .header-org {
            padding-left: 14px;
        }

        .org-name {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
        }

        .org-subtitle {
            font-size: 10px;
            color: #64748b;
            margin-top: 3px;
        }

        .header-meta {
            text-align: right;
            width: 190px;
        }

        .meta-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
        }

        .meta-date {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 2px;
        }

        .meta-time {
            font-size: 9px;
            color: #64748b;
            margin-top: 2px;
        }

        .live-pill {
            display: inline-block;
            margin-top: 6px;
            background-color: #dcfce7;
            color: #15803d;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 99px;
        }

        /* ── BODY ── */
        .body {
            padding: 20px 28px 0 28px;
        }

        .section-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        /* ── CARDS ── */
        .cards-table {
            width: 100%;
            margin-bottom: 22px;
        }

        .cards-table td {
            width: 25%;
            padding-right: 8px;
            vertical-align: top;
        }

        .cards-table td:last-child {
            padding-right: 0;
        }

        .card-box {
            padding: 14px 16px;
            border-radius: 8px;
        }

        .card-num {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
        }

        .card-lbl {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 5px;
        }

        /* ── DATA TABLE ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .data-table thead tr {
            background-color: #0f172a;
        }

        .data-table thead th {
            color: #ffffff;
            padding: 9px 12px;
            text-align: left;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
        }

        .data-table tbody tr.even-row {
            background-color: #f8fafc;
        }

        .data-table tbody td {
            padding: 9px 12px;
            color: #334155;
            vertical-align: middle;
        }

        .td-num   { color: #cbd5e1; font-size: 9px; width: 28px; }
        .td-name  { font-weight: 700; color: #0f172a; }
        .td-muted { color: #94a3b8; }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            font-size: 8.5px;
            font-weight: 700;
        }

        .badge-grade   { background-color: #f1f5f9; color: #475569; border-radius: 99px; }
        .badge-present { background-color: #dcfce7; color: #15803d; border-radius: 5px; }
        .badge-left    { background-color: #e0f2fe; color: #0369a1; border-radius: 5px; }
        .badge-nr      { background-color: #fee2e2; color: #dc2626; border-radius: 5px; }

        /* ── FOOTER ── */
        .footer-table {
            width: 100%;
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 10px 28px;
            margin-top: 20px;
        }

        .footer-table td {
            vertical-align: middle;
            font-size: 8.5px;
            color: #94a3b8;
            padding: 0;
        }

        .footer-org  { font-weight: 700; color: #64748b; }
        .footer-right { text-align: right; }

        .bottom-bar {
            width: 100%;
            height: 4px;
            background-color: #0f172a;
            margin-top: 14px;
        }
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
            <div class="org-subtitle">Current Student Status Report</div>
        </td>
        <td class="header-meta">
            <div class="meta-label">Report Generated</div>
            <div class="meta-date">{{ now()->format('d F Y') }}</div>
            <div class="meta-time">{{ now()->format('H:i') }} ({{ now()->timezoneName }})</div>
        </td>
    </tr>
</table>

{{-- BODY --}}
<div class="body">

    {{-- Summary Cards --}}
    <div class="section-label" style="margin-top:18px;">Overview</div>
    <table class="cards-table" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <div class="card-box" style="background-color:#dcfce7;">
                    <div class="card-num" style="color:#15803d;">{{ $data['totalPresent'] }}</div>
                    <div class="card-lbl" style="color:#15803d;">Present</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#e0f2fe;">
                    <div class="card-num" style="color:#0369a1;">{{ $data['totalLeft'] }}</div>
                    <div class="card-lbl" style="color:#0369a1;">Left School</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#fee2e2;">
                    <div class="card-num" style="color:#dc2626;">{{ $data['totalNotReported'] }}</div>
                    <div class="card-lbl" style="color:#dc2626;">Not Reported</div>
                </div>
            </td>
            <td>
                <div class="card-box" style="background-color:#f1f5f9;">
                    <div class="card-num" style="color:#475569;">{{ $data['totalEnrolled'] }}</div>
                    <div class="card-lbl" style="color:#475569;">Total Enrolled</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Student Records --}}
    <div class="section-label">Student Records</div>
    <table class="data-table" cellspacing="0" cellpadding="0">
        <thead>
        <tr>
            <th style="width:28px;">#</th>
            <th>Student Name</th>
            <th>Grade</th>
            <th>Status</th>
            <th>Time</th>
            <th>Date of Last Record</th>
        </tr>
        </thead>
        <tbody>
        @forelse($data['rows'] as $i => $row)
            <tr class="{{ $i % 2 === 1 ? 'even-row' : '' }}">
                <td class="td-num">{{ $i + 1 }}</td>
                <td class="td-name">{{ $row['name'] }}</td>
                <td><span class="badge badge-grade">{{ $row['grade'] }}</span></td>
                <td>
                    @if($row['status'] === 'present')
                        <span class="badge badge-present">&#10003; Present</span>
                    @elseif($row['status'] === 'left')
                        <span class="badge badge-left">&#8617; Left School</span>
                    @else
                        <span class="badge badge-nr">&#10005; Not Reported</span>
                    @endif
                </td>
                <td class="td-muted">{{ $row['time'] ?? '—' }}</td>
                <td class="td-muted">{{ $row['date'] ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" style="text-align:center; color:#94a3b8; padding:24px 0;">
                    No records found.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

</div>

{{-- FOOTER --}}
<table class="footer-table" cellspacing="0" cellpadding="0">
    <tr>
        <td>
            <span class="footer-org">{{ $orgName }}</span>
            &nbsp;&middot;&nbsp; Confidential
            &nbsp;&middot;&nbsp; {{ count($data['rows']) }} student(s) listed
        </td>
        <td class="footer-right">
            Printed {{ now()->format('d M Y, H:i') }}
            &nbsp;&middot;&nbsp; Based on last attendance record
        </td>
    </tr>
</table>

<div class="bottom-bar"></div>

</body>
</html>
