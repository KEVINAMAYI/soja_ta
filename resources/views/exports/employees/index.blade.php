<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Arial, sans-serif; font-size:11px; color:#1e293b; background:#fff; }
        .header { padding:16px 24px; background:#f8fafc; border-bottom:2px solid #0f172a; }
        .org-name { font-size:16px; font-weight:800; color:#0f172a; }
        .report-sub { font-size:10px; color:#64748b; margin-top:2px; }
        .meta { font-size:10px; color:#64748b; margin-top:6px; }
        .body-wrap { padding:16px 24px; }
        table { width:100%; border-collapse:collapse; font-size:10px; }
        thead tr { background:#0f172a; color:#fff; }
        thead th { padding:8px 10px; text-align:left; font-size:9px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
        tbody tr { border-bottom:1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background:#f8fafc; }
        tbody td { padding:7px 10px; color:#334155; vertical-align:middle; }
        .name { font-weight:700; color:#0f172a; }
        .sub { color:#64748b; font-size:9px; }
        .badge { padding:2px 8px; border-radius:99px; font-size:9px; font-weight:700; }
        .badge-active { background:#dcfce7; color:#15803d; }
        .badge-inactive { background:#fee2e2; color:#dc2626; }
        .pin { background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:5px; font-size:9px; font-weight:700; }
        .muted { color:#94a3b8; }
        .footer { margin-top:20px; padding:10px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; font-size:9px; color:#94a3b8; }
    </style>
</head>
<body>

@php $isExcel = $isExcel ?? false; @endphp

<div class="header">
    <div class="org-name">{{ $orgName }}</div>
    <div class="report-sub">Employee Register &mdash; {{ $date }}</div>
    <div class="meta">Total: {{ $total }} &nbsp;|&nbsp; Active: {{ $totalActive }} &nbsp;|&nbsp; Inactive: {{ $totalInactive }}</div>
</div>

<div class="body-wrap">
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            @if($isExcel)
                <th>Email</th>
                <th>Phone</th>
            @else
                <th>Email / Phone</th>
            @endif
            <th>Emp Number</th>
            <th>Title</th>
            @if($isExcel)
                <th>Department</th>
                <th>Division</th>
                <th>Section</th>
                <th>Shift</th>
            @else
                <th>Dept / Division / Section / Shift</th>
            @endif
            <th>Device PIN</th>
            <th>Active</th>
        </tr>
        </thead>
        <tbody>
        @forelse($employees as $i => $emp)
            <tr>
                <td class="muted">{{ $i + 1 }}</td>
                <td class="name">{{ $emp->name }}</td>

                {{-- Email / Phone --}}
                @if($isExcel)
                    <td>{{ $emp->email }}</td>
                    <td>{{ $emp->phone ?? '—' }}</td>
                @else
                    <td>
                        {{ $emp->email }}
                        @if($emp->phone)
                            <br><span class="sub">{{ $emp->phone }}</span>
                        @endif
                    </td>
                @endif

                <td>{{ $emp->ad_employee_id ?? '—' }}</td>
                <td>{{ $emp->employee_title ?? '—' }}</td>

                {{-- Dept / Division / Section / Shift --}}
                @if($isExcel)
                    <td>{{ $emp->department?->name ?? '—' }}</td>
                    <td>{{ $emp->division ?? '—' }}</td>
                    <td>{{ $emp->section ?? '—' }}</td>
                    <td>{{ $emp->shift?->name ?? '—' }}</td>
                @else
                    <td>
                        @if($emp->department?->name)
                            <span>{{ $emp->department->name }}</span>
                        @endif
                        @if($emp->division)
                            <br><span class="sub">{{ $emp->division }}</span>
                        @endif
                        @if($emp->section)
                            <br><span class="sub">{{ $emp->section }}</span>
                        @endif
                        @if($emp->shift?->name)
                            <br><span class="sub" style="color:#0369a1;">{{ $emp->shift->name }}</span>
                        @endif
                        @if(!$emp->department?->name && !$emp->division && !$emp->section && !$emp->shift?->name)
                            <span class="muted">—</span>
                        @endif
                    </td>
                @endif

                {{-- Device PIN --}}
                <td>
                    @if($emp->zkbio_pin)
                        <span class="pin">{{ $emp->zkbio_pin }}</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>

                {{-- Active --}}
                <td>
                    @if($emp->active)
                        <span class="badge badge-active">Active</span>
                    @else
                        <span class="badge badge-inactive">Inactive</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="13" style="text-align:center; color:#94a3b8; padding:20px;">No employees found.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="footer">
    {{ $orgName }} &nbsp;&middot;&nbsp; Confidential &nbsp;&middot;&nbsp; {{ $employees->count() }} employee(s) &nbsp;&middot;&nbsp; Printed {{ $date }}
</div>

</body>
</html>
