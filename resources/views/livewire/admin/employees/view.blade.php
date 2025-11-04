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
            'locations' => $this->employee->currentAssignment ? 1 : 0,
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
            $locationName = $attendance->location?->name ?? 'Unknown'; // use attendance location

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
            border-radius: 10px;
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

        /* Cards */
        .stat-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            background: white;
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
            justify-content: space-between; /* distribute space */
            align-items: center; /* vertical center */
            gap: 1.5rem;
        }

        .summary-info .summary-left {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }


        .profile-header {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }

        .profile-initials {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background-color: #0d6efd; /* bootstrap primary */
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

        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }

        .profile-info p {
            margin: 0;
            color: #6c757d;
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
            color: #e14326;
            padding: 30px;
            border-radius: 16px;
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
            background-color: #198754; /* default green */
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

    </style>
@endpush

<div class="container py-4">

    <!-- Profile Header -->
    <div class="header-gradient mb-4">
        <a href="#" class="btn btn-light btn-sm mb-3">← Back to Employees List</a>
        <h2 style="color:#e14326;">{{ $employee->name }}</h2>
        <p class="mb-0">Comprehensive activity and location tracking</p>
    </div>

    <!-- Stats -->
    <div class="row text-center mb-4">
        <div class="col-md-3">
            <div class="card-stat bg-white"><h3>{{ $stats['todayHours'] }}</h3>
                <p class="text-muted">Hours Today</p></div>
        </div>
        <div class="col-md-3">
            <div class="card-stat bg-white"><h3>{{ $stats['weekHours'] }}</h3>
                <p class="text-muted">Hours This Week</p></div>
        </div>
        <div class="col-md-3">
            <div class="card-stat bg-white"><h3>{{ $stats['locations'] }}</h3>
                <p class="text-muted">Locations Visited</p></div>
        </div>
        <div class="col-md-3">
            <div class="card-stat bg-white"><h3>{{ $stats['checkins'] }}</h3>
                <p class="text-muted">Check-ins Today</p></div>
        </div>
    </div>

    <div class="row g-4 align-items-stretch">
        <!-- Employee Info -->
        <div class="col-md-6 d-flex">
            <div class="card-section bg-white w-100 d-flex flex-column">
                <div class="section-title mb-3">
                    <iconify-icon icon="mdi:account-badge" class="me-2" style="color: #2563eb;" width="20"
                                  height="20"></iconify-icon>
                    Employee Information
                </div>
                <div class="mb-2"><strong>Full Name:</strong> {{ $employee->name  }}</div>
                <div class="mb-2"><strong>Employee ID:</strong> {{ $employee->id_number }}</div>
                <div class="mb-2"><strong>Department:</strong> <span
                        class="fw-bold">{{ $employee->department->name  }}</span>
                </div>
                <div class="mb-2">
                    <strong>Role:</strong>
                    {{ $employee->user?->roles->pluck('name')
                        ->map(fn($r) => ucwords(str_replace('-', ' ', $r)))
                        ->join(', ') ?? 'N/A' }}
                </div>
                @php
                    $status = $employee->latestAttendance?->status ?? null;

                    $displayStatus = match ($status) {
                        'unchecked_in', 'absent' => 'Absent',
                        'clocked_in' => 'Clocked In',
                        'clocked_out' => 'Clocked Out',
                        default => 'Unknown',
                    };

                    $badgeClass = match ($displayStatus) {
                        'Clocked In' => 'bg-success',
                        'Clocked Out' => 'bg-warning',
                        'Absent' => 'bg-danger',
                        default => 'bg-secondary',
                    };
                @endphp

                <div class="mb-2">
                    <strong>Current Status:</strong>
                    <span class="badge {{ $badgeClass }} status-badge">{{ $displayStatus }}</span>
                </div>

                <div class="mb-2">
                    <strong>Shift:</strong>
                    @if($employee->shift)
                        <span class="badge bg-primary me-1">{{ $employee->shift->name }}</span>
                        <span class="text-muted"> {{ \Carbon\Carbon::parse($employee->shift->start_time)->format('g:i A') }}
                            &ndash;  {{ \Carbon\Carbon::parse($employee->shift->end_time)->format('g:i A') }}
                        </span>
                    @else
                        <span class="text-muted">No shift assigned</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Current Location -->
        <div class="col-md-6 d-flex">
            <div class="card-section bg-white w-100 d-flex flex-column p-4 rounded shadow-sm">
                <div class="section-title mb-3 d-flex align-items-center">
                    <iconify-icon icon="mdi:map-marker" class="me-2" style="color: #22c55e;" width="20"
                                  height="20"></iconify-icon>
                    <h6 class="mb-0">Current Location</h6>
                </div>

                @if ($workLocation)
                    <div class="mb-2">
                        <strong>Name:</strong>
                        {{ $workLocation ? ucwords(str_replace('_', ' ', $workLocation->name)) : '' }}
                    </div>

                    <div class="mb-2">
                        <strong>Building:</strong>
                        {{ $workLocation ? ucwords(str_replace('_', ' ', $workLocation->type)) : '' }}
                    </div>

                    <div class="mb-2">
                        <strong>Address:</strong>
                        {{ $workLocation ? ucwords(str_replace('_', ' ', $workLocation->address)) : '' }}
                    </div>

                    <div class="mb-2 d-flex align-items-center">
                        <strong class="me-2">Active:</strong>
                        @if ($workLocation->active)
                            <iconify-icon icon="mdi:check-circle" style="color: #22c55e;" width="18"
                                          height="18"></iconify-icon>
                            <span class="ms-1 text-success">Active</span>
                        @else
                            <iconify-icon icon="mdi:close-circle" style="color: #dc2626;" width="18"
                                          height="18"></iconify-icon>
                            <span class="ms-1 text-danger">Inactive</span>
                        @endif
                    </div>
                    <div class="mb-2">
                        <strong>GPS Coordinates:</strong> {{ $workLocation->latitude }} , {{ $workLocation->longitude }}
                    </div>

                    <div class="mt-3 text-muted small d-flex align-items-center">
                        <iconify-icon icon="mdi:information-outline" class="me-1" width="18" height="18"></iconify-icon>
                        Live location Map view is available in the mobile app.
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
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'attendance' ? 'active' : '' }}"
               href="#"
               wire:click.prevent="$set('activeTab', 'attendance')">
                Timesheets
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

        <!-- Attendance Tab -->
        <div class="tab-pane fade {{ $activeTab === 'attendance' ? 'show active' : '' }}" id="attendance">
            <div>
                <!-- Filter Bar -->
                <div class="filter-bar d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <input type="text"
                           wire:model="search"
                           wire:keyup.debounce.500ms="$dispatch('refresh-timesheets')"
                           class="form-control w-50"
                           placeholder="🔍 Search employees...">

                    <div class="d-flex gap-2">
                        <select wire:model="status"
                                wire:change="$dispatch('refresh-timesheets')"
                                class="form-select" style="width: 160px;">
                            <option value="all">All Statuses</option>
                            <option value="clocked_in">Clocked In</option>
                            <option value="clocked_out">Clocked Out</option>
                            <option value="absent">Absent</option>
                        </select>

                        <select wire:model="week"
                                wire:change="$dispatch('refresh-timesheets')"
                                class="form-select" style="width: 160px;">
                            <option value="current">Current Week</option>
                            <option value="last">Last Week</option>
                        </select>
                    </div>
                </div>

                <!-- Table -->
                <!-- Table -->
                <table class="table table-hover table-bordered align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Worked Hours</th>
                        <th>Overtime Hours</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($timesheets as $sheet)
                        <tr>
                            <!-- Date -->
                            <td>
                                {{ $sheet->date ? \Carbon\Carbon::parse($sheet->date)->format('M d, Y') : '-' }}
                            </td>

                            <!-- Employee -->
                            <td>{{ $sheet->employee->user->name ?? 'N/A' }}</td>

                            <!-- Worked Hours -->
                            <td>{{ $sheet->worked_hours ?? '0h' }}</td>

                            <!-- Overtime Hours -->
                            <td class="{{ ($sheet->overtime_hours ?? 0) >= 8 ? 'overtime-high' : (($sheet->overtime_hours ?? 0) > 0 ? 'overtime-mid' : '') }}">
                                {{ $sheet->overtime_hours ?? '0h' }}
                            </td>


                            <!-- Status -->
                            <td>
                                @php
                                    $statusClass = match($sheet->status) {
                                        'clocked_in'  => 'clocked-in',
                                        'clocked_out' => 'clocked-out',
                                        'absent'      => 'absent',
                                        default       => 'absent'
                                    };
                                    $statusLabel = match($sheet->status) {
                                        'clocked_in'   => 'Clocked In',
                                        'clocked_out'  => 'Clocked Out',
                                        'absent'       => 'Absent',
                                        'unchecked_in' => 'Absent'
                                    };
                                @endphp
                                <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">No attendance records found</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>

            </div>
        </div>

    </div>
</div>

@push('scripts')
    <script src="https://code.iconify.design/iconify-icon/1.0.7/iconify-icon.min.js"></script>
    <script src="../assets/js/apex-chart/apex.line.init.js"></script>
@endpush
