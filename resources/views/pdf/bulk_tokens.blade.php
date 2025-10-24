<!DOCTYPE html>
<html>
<head>
    <style>
        @page {
            margin: 25px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #333;
        }

        h2, h4 {
            text-align: center;
            margin: 0;
            padding: 0;
        }

        h2 {
            font-size: 18px;
            font-weight: bold;
        }

        h4 {
            font-size: 13px;
            margin-bottom: 10px;
            color: #555;
        }

        /* ✅ Only break between pages, not after the last one */
        .page {
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto !important;
        }

        .logo {
            display: block;
            margin: 0 auto 8px;
            width: 60px;
            height: auto;
        }

        .table-container {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
        }

        .qr-cell {
            width: 33.33%;
            text-align: center;
            vertical-align: top;
            padding: 10px;
        }

        .qr-box {
            border: 1px solid #aaa;
            border-radius: 6px;
            padding: 6px;
            background: #fff;
            display: inline-block;
        }

        .qr-box img {
            width: 120px;
            height: 120px;
        }

        .qr-number {
            font-weight: bold;
            font-size: 10px;
            margin-top: 4px;
        }

        .qr-token {
            font-size: 9px;
            color: #555;
            word-wrap: break-word;
            margin-top: 3px;
        }

        .footer {
            text-align: center;
            font-size: 9px;
            color: #777;
            margin-top: 10px;
        }
    </style>
</head>
<body>

@php
    $organizationName = Auth::user()->employee->organization->name ?? 'Organization';
    $chunks = array_chunk($tokens, 12); // 12 tokens per page
@endphp

@foreach($chunks as $pageIndex => $pageTokens)
    <div class="page">
        <!-- Header -->
        <div style="text-align:center;">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="logo">
            <h2>{{ $organizationName }}</h2>
            <h4>Bulk QR Tokens ({{ now()->format('d M Y, H:i') }})</h4>
        </div>

        <!-- Table-based grid -->
        <table class="table-container">
            @foreach(array_chunk($pageTokens, 3) as $rowTokens)
                <tr>
                    @foreach($rowTokens as $index => $t)
                        @php
                            $globalNumber = ($pageIndex * 12) + (($loop->parent->index * 3) + $index + 1);
                        @endphp
                        <td class="qr-cell">
                            <div class="qr-box">
                                <img src="data:image/png;base64,{{ $t['qr'] }}" alt="QR Code">
                                <div style="margin-top:10px;" class="qr-token">
                                    Token #{{ $globalNumber }}<br>
                                </div>
                            </div>
                        </td>
                    @endforeach

                    {{-- fill remaining cells if last row has less than 3 --}}
                    @for ($i = count($rowTokens); $i < 3; $i++)
                        <td class="qr-cell"></td>
                    @endfor
                </tr>
            @endforeach
        </table>

        <div class="footer">
            Page {{ $loop->iteration }} — 3×4 Layout (12 per page)
        </div>
    </div>
@endforeach

</body>
</html>
