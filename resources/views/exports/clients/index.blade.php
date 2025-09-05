<?php
$organization = $organizations->first(); // Get the first organization
$logoDataUri = null;
$initials = 'XX'; // Default initials

if ($organization) {
    $initials = strtoupper(substr($organization->name, 0, 2));

    // Check if a logo path exists and the file is readable
    if (!empty($organization->logo_path) && file_exists(storage_path('app/public/' . $organization->logo_path))) {
        $path = storage_path('app/public/' . $organization->logo_path);
        $type = pathinfo($path, PATHINFO_EXTENSION);

        // Handle common image types
        $mime = 'image/' . ($type === 'svg' ? 'svg+xml' : $type);

        $data = file_get_contents($path);
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
?>

<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 20px;
        }

        .header {
            display: flex;
            flex-direction: column; /* stack logo + content */
            align-items: center; /* center horizontally */
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 25px; /* more space below */
            margin-bottom: 25px;
            text-align: center;
        }

        .header-logo {
            max-width: 120px;
            max-height: 120px;
            width: auto;
            height: auto;
            object-fit: cover;
            border-radius: 50%;
            margin-bottom: 10px;
        }


        .header-content h1 {
            font-size: 24px;
            margin: 0;
            color: #2c3e50;
        }

        .header-content .meta {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
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

        .header-org-name {
            width: 100%;
            padding: 15px;
            color: #2c3e50;
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }

    </style>
</head>
<body>
<div class="header">
    @if(empty($isExcel))
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" class="header-logo" alt="Organization Logo">
        @else
            <div class="header-org-name">
                {{ $organization?->name ?? 'Organization' }}
            </div>
        @endif
    @endif

    <div class="header-content">
        <h1>{{ $title }}</h1>
        <div class="meta">Generated on {{ now()->format('d M Y, H:i') }}</div>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Client Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Address</th>
        <th>Created At</th>
    </tr>
    </thead>
    <tbody>
    @foreach($organizations as $index => $org)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $org->name }}</td>
            <td>{{ $org->email ?? '-' }}</td>
            <td>{{ $org->phone_number ?? '-' }}</td>
            <td>{{ $org->location ?? '-' }}</td>
            <td>{{ $org->created_at?->format('d M Y') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
