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
        /* Container acts like a table to force children into one row */
        .summary-row {
            display: table;
            width: 100%;
            margin-bottom: 18px;
            border-spacing: 10px 0; /* Creates the 'gap' between cards */
            margin-left: -10px;    /* Offsets the border-spacing on the far left */
        }

        /* Children act like table cells */
        .summary-card {
            display: table-cell;
            width: 20%;             /* 5 cards = 20% each */
            border-radius: 8px;
            padding: 12px 10px;
            vertical-align: top;
            text-align: center;     /* Centers the text inside the card */
        }

        .summary-card .num {
            font-size: 18px;
            font-weight: 800;
            display: block;
        }

        .summary-card .lbl {
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 4px;
            display: block;
        }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        thead tr { background: #0f172a; color: #fff; }
        thead th { padding: 8px 10px; text-align: left; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 7px 10px; color: #334155; }
        .badge-grade { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 99px; font-size: 9.5px; font-weight: 600; }
        .status-present { background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 6px; font-size: 9.5px; font-weight: 600; }
        .status-left { background: #e0f2fe; color: #0284c7; padding: 2px 8px; border-radius: 6px; font-size: 9.5px; font-weight: 600; }
        .status-not-reported { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 6px; font-size: 9.5px; font-weight: 600; }
        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="header">
    <div class="header-left">
        <div class="org">{{ $data['orgName'] ?? 'School' }}</div>
        <div class="report-title">Current Student Status Report</div>
    </div>
    <div class="header-right">
        Generated: {{ now()->format('d M Y, H:i') }}<br>
        Live status — based on last attendance record
    </div>
</div>

<div class="summary-row">
    <div class="summary-card" style="background:#dcfce7;">
        <div class="num" style="color:#16a34a;">{{ $data['totalPresent'] }}</div>
        <div class="lbl" style="color:#16a34a;">Present</div>
    </div>
    <div class="summary-card" style="background:#e0f2fe;">
        <div class="num" style="color:#0284c7;">{{ $data['totalLeft'] }}</div>
        <div class="lbl" style="color:#0284c7;">Left School</div>
    </div>
    <div class="summary-card" style="background:#fee2e2;">
        <div class="num" style="color:#dc2626;">{{ $data['totalNotReported'] }}</div>
        <div class="lbl" style="color:#dc2626;">Not Reported</div>
    </div>
    <div class="summary-card" style="background:#f1f5f9;">
        <div class="num" style="color:#475569;">{{ $data['totalEnrolled'] }}</div>
        <div class="lbl" style="color:#475569;">Total Enrolled</div>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Student Name</th>
        <th>Grade</th>
        <th>Status</th>
        <th>Time</th>
        <th>Date of Last Record</th>
    </tr>
    </thead>
    <tbody>
    @forelse($data['rows'] as $i => $row)
        <tr>
            <td style="color:#94a3b8;">{{ $i + 1 }}</td>
            <td style="font-weight:600; color:#0f172a;">{{ $row['name'] }}</td>
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
            <td style="color:#64748b;">{{ $row['time'] ?? '—' }}</td>
            <td style="color:#64748b;">{{ $row['date'] ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No records found.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="footer">
    <span>{{ $data['orgName'] ?? '' }} — Confidential · {{ count($data['rows']) }} students listed</span>
    <span>Printed {{ now()->format('d M Y H:i') }}</span>
</div>
</body>
</html>
