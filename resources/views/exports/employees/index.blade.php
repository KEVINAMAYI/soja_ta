<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employee Report</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 20px;
        }

        .header {
            display: flex;
            align-items: center;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .logo {
            width: 60px;
            margin-right: 15px;
        }

        h1 {
            font-size: 20px;
            margin: 0;
            color: #2c3e50;
        }

        .meta {
            font-size: 12px;
            color: #7f8c8d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px 12px;
            font-size: 12px;
        }

        th {
            background: #2c3e50;
            color: #fff;
            text-align: left;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" class="logo">
    <div>
        <h1>Employee Report</h1>
        <div class="meta">Generated on {{ now()->format('d M Y, H:i') }}</div>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>ID Number</th>
        <th>Department</th>
        <th>Shift</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    @foreach($employees as $employee)
        <tr>
            <td>{{ $employee->name ?? '' }}</td>
            <td>{{ $employee->user->email ?? '' }}</td>
            <td>{{ $employee->phone ?? '' }}</td>
            <td>{{ $employee->id_number ?? '' }}</td>
            <td>{{ optional($employee->department)->name ?? '' }}</td>
            <td>{{ optional($employee->shift)->name ?? '' }}</td>
            <td>{{ $employee->active ? 'Active' : 'Inactive' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
