<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; margin: 20px; }
        h2   { font-size: 13px; font-weight: 800; margin: 0 0 2px; }
        p    { font-size: 9px; color: #64748b; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th   { background: #0f172a; color: #fff; font-size: 8px; text-transform: uppercase;
            letter-spacing: 0.4px; padding: 6px 8px; text-align: left; }
        td   { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
<h2>Attendance Records</h2>
<p>{{ \Carbon\Carbon::parse($data['startDate'])->format('d M Y') }}
    @if($data['startDate'] !== $data['endDate'])
        – {{ \Carbon\Carbon::parse($data['endDate'])->format('d M Y') }}
    @endif
</p>

<table>
    <thead>
    <tr>
        @foreach($data['headers'] as $h)
            <th>{{ $h }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse($data['rows'] as $row)
        <tr>
            @foreach($row as $cell)
                <td>{{ $cell }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($data['headers']) }}"
                style="text-align:center; color:#94a3b8; padding:16px;">
                No records found.
            </td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
