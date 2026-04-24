<?php
// Resolve logo as base64 data URI — works reliably with DomPDF/wkhtmltopdf
$logoDataUri = null;
$orgLogoPath = $data['logoPath'] ?? null;  // pass e.g. $data['logoPath'] = $org->logo_path

if ($orgLogoPath) {
    $absPath = storage_path('app/public/' . $orgLogoPath);
    if (file_exists($absPath)) {
        $ext         = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime        = 'image/' . ($ext === 'svg' ? 'svg+xml' : $ext);
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absPath));
    }
}
?>
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1e293b;
            background: #fff;
            padding: 0;
        }

        /* ─── TOP ACCENT BAR ─── */
        .top-bar {
            background: #0f172a;
            height: 5px;
            width: 100%;
        }

        /* ─── HEADER ─── */
        .header {
            display: table;
            width: 100%;
            padding: 20px 28px 16px 28px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .header-logo-col {
            display: table-cell;
            vertical-align: middle;
            width: 80px;
        }

        .header-logo-col img {
            height: {{ $logoHeight ?? 48 }}px;
            width: {{ $logoWidth ?? 48 }}px;
            object-fit: contain;
        }

        /* Fallback circle when no logo */
        .logo-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            font-weight: 800;
        }

        .header-info-col {
            display: table-cell;
            vertical-align: middle;
            padding-left: 14px;
        }

        .org-name {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        .report-subtitle {
            font-size: 10.5px;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }

        .header-meta-col {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
        }

        .report-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            margin-bottom: 4px;
        }

        .report-date {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .report-time {
            font-size: 10px;
            color: #64748b;
            margin-top: 1px;
        }

        .live-badge {
            display: inline-block;
            margin-top: 5px;
            background: #dcfce7;
            color: #16a34a;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 99px;
        }

        /* ─── BODY AREA ─── */
        .body-wrap { padding: 20px 28px; }

        /* ─── SECTION TITLE ─── */
        .section-title {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        /* ─── SUMMARY CARDS ─── */
        .summary-row {
            display: table;
            width: 100%;
            margin-bottom: 22px;
        }

        .summary-card {
            display: table-cell;
            width: 25%;
            padding: 14px 10px;
            border-radius: 10px;
            vertical-align: middle;
        }

        /* Spacing between cards */
        .summary-card + .summary-card {
            padding-left: 20px;
        }

        .card-inner {
            display: table;
            width: 100%;
        }

        .card-icon-col {
            display: table-cell;
            vertical-align: middle;
            width: 36px;
        }

        .card-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            font-size: 15px;
            line-height: 32px;
            text-align: center;
        }

        .card-text-col {
            display: table-cell;
            vertical-align: middle;
            padding-left: 10px;
        }

        .card-num {
            font-size: 20px;
            font-weight: 800;
            line-height: 1;
        }

        .card-lbl {
            font-size: 8.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 2px;
        }

        /* ─── TABLE ─── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        thead tr {
            background: #0f172a;
            color: #fff;
        }

        thead th {
            padding: 9px 12px;
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 8px 12px; color: #334155; vertical-align: middle; }

        .num-cell { color: #cbd5e1; font-size: 10px; }

        .student-name { font-weight: 700; color: #0f172a; font-size: 11px; }

        .badge-grade {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-present {
            background: #dcfce7;
            color: #15803d;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-left {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-not-reported {
            background: #fee2e2;
            color: #dc2626;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 700;
        }

        .time-cell { color: #64748b; font-size: 10px; }
        .date-cell { color: #64748b; font-size: 10px; }

        /* ─── FOOTER ─── */
        .footer {
            margin-top: 24px;
            padding: 12px 28px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: table;
            width: 100%;
        }

        .footer-left {
            display: table-cell;
            vertical-align: middle;
            font-size: 9px;
            color: #94a3b8;
        }

        .footer-right {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
            font-size: 9px;
            color: #94a3b8;
        }

        .footer-org {
            font-weight: 700;
            color: #64748b;
        }

        .bottom-bar {
            background: linear-gradient(90deg, #0f172a 0%, #1e40af 50%, #0f172a 100%);
            height: 4px;
            width: 100%;
        }
    </style>
</head>
<body>

{{-- TOP ACCENT --}}
<div class="top-bar"></div>

{{-- ─── HEADER ─── --}}
<div class="header">
    {{-- Logo --}}
    <div class="header-logo-col">
        @if($logoDataUri)
            <img
                src="{{ $logoDataUri }}"
                alt="{{ $data['orgName'] ?? 'Logo' }}"
                style="height: {{ $data['logoHeight'] ?? 48 }}px; width: {{ $data['logoWidth'] ?? 48 }}px; object-fit: contain;"
            >
        @else
            <div class="logo-placeholder">
                {{ strtoupper(substr($data['orgName'] ?? 'S', 0, 1)) }}
            </div>
        @endif
    </div>

    {{-- Org name & report title --}}
    <div class="header-info-col">
        <div class="org-name">{{ $data['orgName'] ?? 'School' }}</div>
        <div class="report-subtitle">Current Student Status Report</div>
    </div>

    {{-- Date & meta --}}
    <div class="header-meta-col">
        <div class="report-label">Report Generated</div>
        <div class="report-date">{{ now()->format('d F Y') }}</div>
        <div class="report-time">{{ now()->format('H:i') }} ({{ now()->timezoneName }})</div>
        <span class="live-badge">● Live Status</span>
    </div>
</div>

{{-- ─── BODY ─── --}}
<div class="body-wrap">

    {{-- Summary Cards --}}
    <div class="section-title">Overview</div>
    <div class="summary-row">

        <div class="summary-card" style="background:#dcfce7;">
            <div class="card-inner">
                <div class="card-icon-col">
                    <div class="card-icon" style="background:#bbf7d0; color:#15803d;">✓</div>
                </div>
                <div class="card-text-col">
                    <div class="card-num" style="color:#15803d;">{{ $data['totalPresent'] }}</div>
                    <div class="card-lbl" style="color:#15803d;">Present</div>
                </div>
            </div>
        </div>

        <div class="summary-card" style="background:#e0f2fe;">
            <div class="card-inner">
                <div class="card-icon-col">
                    <div class="card-icon" style="background:#bae6fd; color:#0369a1;">↩</div>
                </div>
                <div class="card-text-col">
                    <div class="card-num" style="color:#0369a1;">{{ $data['totalLeft'] }}</div>
                    <div class="card-lbl" style="color:#0369a1;">Left School</div>
                </div>
            </div>
        </div>

        <div class="summary-card" style="background:#fee2e2;">
            <div class="card-inner">
                <div class="card-icon-col">
                    <div class="card-icon" style="background:#fecaca; color:#dc2626;">✕</div>
                </div>
                <div class="card-text-col">
                    <div class="card-num" style="color:#dc2626;">{{ $data['totalNotReported'] }}</div>
                    <div class="card-lbl" style="color:#dc2626;">Not Reported</div>
                </div>
            </div>
        </div>

        <div class="summary-card" style="background:#f1f5f9;">
            <div class="card-inner">
                <div class="card-icon-col">
                    <div class="card-icon" style="background:#e2e8f0; color:#475569;">#</div>
                </div>
                <div class="card-text-col">
                    <div class="card-num" style="color:#475569;">{{ $data['totalEnrolled'] }}</div>
                    <div class="card-lbl" style="color:#475569;">Total Enrolled</div>
                </div>
            </div>
        </div>

    </div>

    {{-- Student Table --}}
    <div class="section-title">Student Records</div>
    <table>
        <thead>
        <tr>
            <th style="width:32px;">#</th>
            <th>Student Name</th>
            <th>Year Group</th>
            <th>Status</th>
            <th>Time</th>
            <th>Date of Last Record</th>
        </tr>
        </thead>
        <tbody>
        @forelse($data['rows'] as $i => $row)
            <tr>
                <td class="num-cell">{{ $i + 1 }}</td>
                <td class="student-name">{{ $row['name'] }}</td>
                <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
                <td>
                    @if($row['status'] === 'present')
                        <span class="status-present">✓ Present</span>
                    @elseif($row['status'] === 'left')
                        <span class="status-left">↩ Left School</span>
                    @else
                        <span class="status-not-reported">✕ Not Reported</span>
                    @endif
                </td>
                <td class="time-cell">{{ $row['time'] ?? '—' }}</td>
                <td class="date-cell">{{ $row['date'] ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">
                    No records found.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

</div>

{{-- ─── FOOTER ─── --}}
<div class="footer">
    <div class="footer-left">
        <span class="footer-org">{{ $data['orgName'] ?? '' }}</span>
        &nbsp;·&nbsp; Confidential
        &nbsp;·&nbsp; {{ count($data['rows']) }} student(s) listed
    </div>
    <div class="footer-right">
        Printed {{ now()->format('d M Y, H:i') }}
        &nbsp;·&nbsp; Based on last attendance record
    </div>
</div>

<div class="bottom-bar"></div>

</body>
</html>
