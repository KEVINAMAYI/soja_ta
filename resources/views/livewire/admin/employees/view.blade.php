<?php

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Livewire\Attributes\On;

use Livewire\Volt\Component;

new class extends Component {

    public $employee;
    public $stats = [];
    public $timeline = [];
    public $timesheets = [];
    public $workLocation;
    public $search = '';
    public $status = 'all';
    public $week = 'current'; // current or last
    public $activeTab = 'overview';

    public function mount($employeeId)
    {
        // Load employee
        $this->employee = Employee::with('department')
            ->where('organization_id', auth()->user()->employee->organization_id)
            ->findOrFail($employeeId);

        $this->workLocation = $this->employee->currentAssignment->first()?->location;

        // Example stats
        $this->stats = [
            'todayHours' => Attendance::where('employee_id', $employeeId)
                ->whereDate('date', today())
                ->sum('worked_hours'),
            'weekHours' => Attendance::where('employee_id', $employeeId)
                ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
                ->sum('worked_hours'),
            'locations' => Attendance::where('employee_id', $employeeId)
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->whereNotNull('work_location_id')
                ->distinct('work_location_id')
                ->count('work_location_id'),
            'checkins' => Attendance::where('employee_id', $employeeId)
                ->whereDate('date', today())
                ->where('status', 'clocked_in')
                ->count(),
        ];

        $this->loadTimeline($this->employee->id);

        // Timesheets (this week)
        $this->timesheets = Attendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->get();

        $this->loadTimesheets();

    }


    public function loadTimeline($employeeId)
    {
        $todayAttendances = Attendance::with('location') // eager load the attendance location
        ->where('employee_id', $employeeId)
            ->whereDate('date', today())
            ->get();

        $timeline = collect();

        foreach ($todayAttendances as $attendance) {
            $locationName = $attendance->location?->name ?? 'Not Scheduled'; // use attendance location

            if ($attendance->check_in_time) {
                $timeline->push([
                    'time' => Carbon::parse($attendance->check_in_time),
                    'title' => 'Clocked In',
                    'location' => $locationName,
                    'type' => 'clocked-in'
                ]);
            }

            if ($attendance->check_out_time) {
                $timeline->push([
                    'time' => Carbon::parse($attendance->check_out_time),
                    'title' => 'Clocked Out',
                    'location' => $locationName,
                    'type' => 'clocked-out'
                ]);
            }
        }

        // Sort chronologically ascending
        $this->timeline = $timeline->sortBy('time')->values();
    }


    public function loadTimesheets()
    {
        $dateRange = $this->week === 'last'
            ? [Carbon::now()->subWeek()->startOfWeek(), Carbon::now()->subWeek()->endOfWeek()]
            : [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];

        $query = Attendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('date', $dateRange);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->search) {
            $query->whereHas('employee.user', fn($q) => $q->where('name', 'like', "%{$this->search}%")
            );
        }

        $this->timesheets = $query->get();
    }

    #[On('refresh-timesheets')]
    public function refreshTimesheets()
    {
        $this->loadTimesheets();
    }

}; ?>

@push('styles')
    <style>
        /* Profile Header */
        .profile-header {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }

        .profile-photo {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 3px solid #dee2e6;
            object-fit: cover;
            background: white;
        }

        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }

        .profile-info p {
            margin: 0;
            color: #6c757d;
        }

        /* Updated Stats Cards to Match Image */
        .stat-card-new {
            background: white;
            border-left: 4px solid #0d6efd;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            height: 100%;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 0 0 0.5rem 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
        }

        .nav-tabs .nav-link {
            color: #495057;
            border: none;
            padding: 0.75rem 1.25rem;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
            border-radius: 0px;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }

        /* Information Cards */
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            height: 100%;
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }

        .info-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .info-card-icon.blue {
            background-color: #dbeafe;
            color: #2563eb;
        }

        .info-card-icon.green {
            background-color: #d1fae5;
            color: #10b981;
        }

        .info-card-icon.orange {
            background-color: #fed7aa;
            color: #f97316;
        }

        .info-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.875rem;
            color: #1f2937;
            font-weight: 600;
            text-align: right;
        }

        /* Table styling */
        .table thead {
            background-color: #f8f9fa;
        }

        .table tbody tr:hover {
            background-color: #f1f3f5;
        }

        /* Badges */
        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-onleave {
            background-color: #0dcaf0;
            color: #212529;
        }

        .summary-info {
            margin-bottom: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .summary-info .summary-left {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        .profile-initials {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 48px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            user-select: none;
            box-shadow: 0 0 10px rgba(13, 110, 253, 0.4);
        }

        .card-stat {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.05);
        }

        .card-section {
            border-radius: 12px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
        }

        .section-title {
            font-weight: bold;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            font-size: 0.9rem;
        }

        .header-gradient {
            background: white;
            color: #2c3e50;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header-gradient h2 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .header-gradient p {
            color: #6b7280;
            margin-bottom: 0;
        }

        .map-placeholder {
            background: #f5f5f5;
            border: 2px dashed #ccc;
            text-align: center;
            padding: 30px;
            border-radius: 8px;
            color: #888;
        }

        .timeline {
            border-left: 3px solid #0d6efd;
            margin: 20px;
            padding-left: 20px;
            position: relative;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }

        .timeline-item::before {
            content: '';
            width: 14px;
            height: 14px;
            background-color: #198754;
            border-radius: 50%;
            position: absolute;
            left: -30px;
            top: 0;
        }

        .timeline-item.red::before {
            background-color: #dc3545;
        }

        .timeline-item.orange::before {
            background-color: #fd7e14;
        }

        .timeline-time {
            font-weight: bold;
            font-size: 0.9rem;
        }

        .timeline-title {
            font-weight: 600;
            font-size: 1rem;
        }

        .timeline-location {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .status-badge {
            padding: 0.35em 0.6em;
            border-radius: 0.375rem;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .clocked-in {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .clocked-out {
            background-color: #f8d7da;
            color: #842029;
        }

        .absent {
            background-color: #e2e3e5;
            color: #41464b;
        }

        .table > :not(caption) > * > * {
            vertical-align: middle;
        }

        .overtime-high {
            color: #dc3545;
        }

        .overtime-mid {
            color: #fd7e14;
        }

        :root {
            --main-accent: #e14326;
            --main-accent-light: #f9e6e3;
        }

        .filter-bar {
            background-color: white;
            border: 1px solid #dee2e6;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.04);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--main-accent);
            box-shadow: 0 0 0 0.2rem rgba(225, 67, 38, 0.25);
        }

        .form-select,
        .form-control {
            transition: all 0.3s ease-in-out;
        }

        .form-select:hover,
        .form-control:hover {
            border-color: var(--main-accent);
        }

        .filter-label {
            font-weight: 500;
            color: #495057;
        }

        .highlight-accent {
            color: var(--main-accent);
        }

        .btn-accent {
            background-color: var(--main-accent);
            color: white;
            border: none;
        }

        .btn-accent:hover {
            background-color: #c9381f;
        }

        /* QR Code Section Styles */
        .qr-code-container {
            text-align: center;
            padding: 2rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .qr-code-wrapper {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
        }

        .qr-code-wrapper img,
        .qr-code-wrapper svg {
            display: block;
            max-width: 200px;
            width: 200px;
            height: auto;
            margin: 0 auto;
        }

        #qrcode-canvas {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        #qrcode-canvas svg {
            width: 200px !important;
            height: 200px !important;
        }

        .btn-download-qr {
            background-color: #f97316;
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-download-qr:hover {
            background-color: #ea580c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }

        .qr-info-text {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 1rem;
        }

    </style>
@endpush

<div class="container py-4">

    <!-- Profile Header -->
    <div class="header-gradient mb-4">
        <a href="{{ route('employees.index') }}" class="btn btn-light btn-sm mb-3">← Back to Employees List</a>
        <h2>{{ $employee->name }}</h2>
        <p>Comprehensive activity and location tracking</p>
    </div>

    <!-- Stats -->
    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="stat-card-new">
                <h2 class="stat-value">{{ $stats['todayHours'] }}</h2>
                <p class="stat-label">HOURS TODAY</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card-new">
                <h2 class="stat-value">{{ $stats['weekHours'] }}</h2>
                <p class="stat-label">HOURS THIS WEEK</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card-new">
                <h2 class="stat-value">{{ $stats['locations'] }}</h2>
                <p class="stat-label">LOCATIONS VISITED</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card-new">
                <h2 class="stat-value">{{ $stats['checkins'] }}</h2>
                <p class="stat-label">CHECK-INS TODAY</p>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-stretch">
        <!-- Employee Info -->
        <div class="col-md-4 d-flex">
            <div class="info-card w-100">
                <div class="info-card-header">
                    <div class="info-card-icon blue">
                        <iconify-icon icon="mdi:account-badge" width="24" height="24"></iconify-icon>
                    </div>
                    <h3 class="info-card-title">Employee Information</h3>
                </div>

                <div class="info-row">
                    <span class="info-label">Full Name</span>
                    <span class="info-value">{{ $employee->name }}</span>
                </div>

                <div class="info-row">
                    <span class="info-label">Employee ID</span>
                    <span class="info-value">{{ $employee->id_number }}</span>
                </div>

                <div class="info-row">
                    <span class="info-label">Department</span>
                    <span class="info-value">{{ $employee->department->name }}</span>
                </div>

                <div class="info-row">
                    <span class="info-label">Role</span>
                    <span class="info-value">
                        {{ $employee->user?->roles->pluck('name')
                            ->map(fn($r) => ucwords(str_replace('-', ' ', $r)))
                            ->join(', ') ?? 'N/A' }}
                    </span>
                </div>

                @php
                    $status = $employee->latestAttendance?->status ?? null;

                   $displayStatus = match ($status) {
                                             'unchecked_in', 'absent' => 'Absent',
                                             'clocked_in' => 'Clocked In',
                                             'clocked_out' => 'Clocked Out',
                                             'on_leave' => 'On Leave',
                                             'off_shift' => 'Off Shift',
                                             'sick_off' => 'Sick Off',
                                             'not_scheduled' => 'Not Scheduled',
                                             default => 'Not Scheduled',
                                            };

                        $badgeClass = match ($displayStatus) {
                                                'Clocked In' => 'bg-success',
                                                'Clocked Out' => 'bg-warning',
                                                'Absent' => 'bg-danger',
                                                'On Leave' => 'bg-info',
                                                'Sick Off' => 'bg-info',
                                                'Off Shift' => 'bg-dark',
                                                'Not Scheduled' => 'bg-danger',
                                                default => 'bg-secondary',
                                            };

                @endphp

                <div class="info-row">
                    <span class="info-label">Current Status</span>
                    <span class="info-value">
                        <span class="badge {{ $badgeClass }} status-badge">{{ $displayStatus }}</span>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Shift</span>
                    <span class="info-value">
                        @if($employee->shift)
                            <span class="badge bg-warning text-dark me-1">{{ $employee->shift->name }}</span>
                            {{ \Carbon\Carbon::parse($employee->shift->start_time)->format('g:i A') }}
                            - {{ \Carbon\Carbon::parse($employee->shift->end_time)->format('g:i A') }}
                        @else
                            <span class="text-muted">No shift assigned</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <!-- Current Location -->
        <div class="col-md-4 d-flex">
            <div class="info-card w-100">
                <div class="info-card-header">
                    <div class="info-card-icon green">
                        <iconify-icon icon="mdi:map-marker" width="24" height="24"></iconify-icon>
                    </div>
                    <h3 class="info-card-title">Current Location</h3>
                </div>

                @if ($workLocation)
                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-value">{{ ucwords(str_replace('_', ' ', $workLocation->name)) }}</span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Building</span>
                        <span class="info-value">{{ ucwords(str_replace('_', ' ', $workLocation->type)) }}</span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Address</span>
                        <span class="info-value"
                              style="max-width: 200px;">{{ ucwords(str_replace('_', ' ', $workLocation->address)) }}</span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            @if ($workLocation->active)
                                <span class="badge bg-success">
                                    <iconify-icon icon="mdi:check-circle" width="14" height="14"></iconify-icon>
                                    Active
                                </span>
                            @else
                                <span class="badge bg-danger">
                                    <iconify-icon icon="mdi:close-circle" width="14" height="14"></iconify-icon>
                                    Inactive
                                </span>
                            @endif
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">GPS Coordinates</span>
                        <span class="info-value"
                              style="font-size: 0.75rem;">{{ $workLocation->latitude }}, {{ $workLocation->longitude }}</span>
                    </div>

                    <div class="mt-3 p-2 bg-light rounded text-center">
                        <iconify-icon icon="mdi:information-outline" class="me-1" width="16" height="16"
                                      style="color: #6c757d;"></iconify-icon>
                        <small class="text-muted">Live location Map view is available in the mobile app.</small>
                    </div>

                @else
                    <div class="text-center py-4 px-3 border rounded bg-light">
                        <iconify-icon icon="mdi:map-marker-off" width="48" height="48"
                                      style="color:#adb5bd;"></iconify-icon>
                        <h6 class="mt-3 mb-1 text-secondary fw-bold">No Work Location Assigned</h6>
                        <p class="text-muted mb-0 small">
                            This employee is not currently assigned to any work location.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        <!-- QR Code Section -->
        <div class="col-md-4 d-flex">
            <div class="info-card w-100">
                <div class="info-card-header">
                    <div class="info-card-icon orange">
                        <iconify-icon icon="mdi:qrcode" width="24" height="24"></iconify-icon>
                    </div>
                    <h3 class="info-card-title">Employee QR Code</h3>
                </div>

                <div class="qr-code-container">
                    <div class="qr-code-wrapper">
                        <div id="qrcode-canvas"></div>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-download-qr" onclick="downloadQR()">
                            <iconify-icon icon="mdi:download" width="18" height="18"></iconify-icon>
                            Download PNG
                        </button>
                    </div>

                    <p class="qr-info-text mb-0">
                        Encoded ID: {{ $employee->qr_code }}
                    </p>
                </div>
            </div>
        </div>

    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4 mt-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'overview' ? 'active' : '' }}"
               href="#"
               wire:click.prevent="$set('activeTab', 'overview')">
                Activity Timeline - Today
            </a>
        </li>
    </ul>


    <div class="tab-content">

        <!-- Overview Tab -->
        <div class="tab-pane fade {{ $activeTab === 'overview' ? 'show active' : '' }}" id="overview">
            <div class="container mt-5">
                <div class="timeline">
                    @forelse($timeline as $item)
                        <div
                            class="timeline-item {{ $item['type'] === 'clocked-out' ? 'red' : ($item['type'] === 'clocked-in' ? '' : 'absent') }}">
                            <div class="timeline-time">
                                {{ $item['time']->format('g:i A') }}
                            </div>
                            <div class="timeline-title">
                                {{ $item['title'] }}
                            </div>
                            <div class="timeline-location">
                                {{ $item['location'] }}
                            </div>
                        </div>
                    @empty
                        <div class="p-3 timeline-item absent">
                            <div class="timeline-time">{{ now()->format('g:i A') }}</div>
                            <div class="timeline-title">Absent</div>
                            <div class="timeline-location">No attendance records</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="https://code.iconify.design/iconify-icon/1.0.7/iconify-icon.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // 1. Initialize and Generate QR Code
        const qrData = "{{ $employee->qr_code }}"; // Data to encode
        const qrContainer = document.getElementById("qrcode-canvas");

        const qrcode = new QRCode(qrContainer, {
            text: qrData,
            width: 200,
            height: 200,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });

        // 2. Download Function
        function downloadQR() {
            // The library creates an <img> or <canvas> inside our div
            const img = qrContainer.querySelector('img');
            const canvas = qrContainer.querySelector('canvas');

            let imageSrc;

            if (img && img.src) {
                imageSrc = img.src;
            } else if (canvas) {
                imageSrc = canvas.toDataURL("image/png");
            }

            if (imageSrc) {
                const link = document.createElement('a');
                link.href = imageSrc;
                link.download = `QR_Code_{{ str_replace(' ', '_', $employee->name) }}.png`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert("QR Code not ready for download yet.");
            }
        }
    </script>
@endpush
