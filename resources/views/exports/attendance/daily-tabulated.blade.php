<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; margin: 20px; font-size: 11px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 10px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; white-space: nowrap; }

        .th-date     { background: #2c3e50; color: #fff; text-align: center; font-weight: bold; }
        .th-sub      { background: #34495e; color: #fff; text-align: center; font-size: 9px; font-weight: normal; }
        .th-employee { background: #2c3e50; color: #fff; text-align: left; font-weight: bold; }

        tr:nth-child(even) td { background: #f9f9f9; }

        .cell-present { color: #27ae60; text-align: center; }
        .cell-absent  { color: #e74c3c; text-align: center; }
        .cell-stillin { color: #27ae60; font-weight: bold; text-align: center; }
        .cell-empty   { color: #bdc3c7; text-align: center; }
        .cell-hours   { text-align: center; }
        .cell-name    { font-weight: bold; min-width: 130px; }
    </style>
</head>
<body>
<table>
    <thead>

    {{-- Title row --}}
    <tr>
        <td colspan="{{ 1 + count($dates) * 3 }}"
            style="font-size:14px; font-weight:bold; color:#2c3e50; padding: 10px 7px;">
            {{ $title }}
        </td>
    </tr>

    {{-- Meta row --}}
    <tr>
        <td colspan="{{ 1 + count($dates) * 3 }}"
            style="color:#7f8c8d; font-style:italic; font-size:10px;">
            Generated on {{ now()->format('d M Y, H:i') }}
            @if(!empty($startDate) && !empty($endDate))
            | Period: {{ \Carbon\Carbon::parse($startDate)->format('d M Y') }}
            – {{ \Carbon\Carbon::parse($endDate)->format('d M Y') }}
            @endif
        </td>
    </tr>

    {{-- Date group headers --}}
    <tr>
        <th class="th-employee" rowspan="2">{{ auth()->user()->employee?->organization?->is_student_record  ? "Student" : "Employee" }}</th>
        @foreach($dates as $date)
        <th class="th-date" colspan="3">
            {{ \Carbon\Carbon::parse($date)->format('M d, Y') }}
        </th>
        @endforeach
    </tr>

    {{-- Sub-column headers --}}
    <tr>
        @foreach($dates as $date)
        <th class="th-sub">Clock In</th>
        <th class="th-sub">Clock Out</th>
        <th class="th-sub">{{ auth()->user()->employee?->organization?->is_student_record  ? "School (hours)" : "Worked (hours)"}}</th>
        @endforeach
    </tr>

    </thead>
    <tbody>
    @foreach($pivotMap as $employeeId => $row)
    @php $employee = $row['employee']; @endphp
    <tr>
        <td class="cell-name">{{ $employee->name ?? '-' }}</td>

        @foreach($dates as $date)
        @php $att = $row['days'][$date] ?? null; @endphp

        @if($att)
        @php
        $isPresent = in_array($att->status, ['clocked_in', 'clocked_out']);
        $stillIn   = $att->status === 'clocked_in' && !$att->check_out_time;
        @endphp

        <td class="{{ $isPresent ? 'cell-present' : 'cell-absent' }}">
            {{ $att->check_in_time ? \Carbon\Carbon::parse($att->check_in_time)->format('g:i A') : '-' }}
        </td>

        <td class="{{ $stillIn ? 'cell-stillin' : ($att->check_out_time ? 'cell-present' : 'cell-absent') }}">
            @if($stillIn)
            Still In
            @else
            {{ $att->check_out_time ? \Carbon\Carbon::parse($att->check_out_time)->format('g:i A') : '-' }}
            @endif
        </td>

        <td class="cell-hours">{{ number_format($att->worked_hours ?? 0, 2) }}</td>

        @else
        <td class="cell-empty">-</td>
        <td class="cell-empty">-</td>
        <td class="cell-hours">0</td>
        @endif

        @endforeach
    </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
