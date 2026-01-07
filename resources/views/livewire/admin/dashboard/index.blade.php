<?php

use App\Models\WorkLocation;
use Livewire\Volt\Component;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

new class extends Component {

    public $totalEmployees = 0;
    public $presentToday = 0;
    public $sickOffToday = 0;
    public $inactiveEmployees = 0;
    public $absentToday = 0;
    public $leaveToday = 0;
    public $OffShiftToday = 0;
    public $lateArrivals = 0;
    public $overtimeHours = 0;
    public $departmentStats = [];
    public $recentActivities = [];
    public $currentEmployeeStatus = [];
    public $employeeLocations = [];
    public $googleMapsApiKey;
    public $workLocations = [];
    public $statusData = [];
    public $shiftStats = [];


    public function mount()
    {
        $today = Carbon::today();
        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

        // Determine organization of logged-in user
        $employeeRecord = Employee::where('user_id', auth()->id())->first();
        $orgId = $employeeRecord->organization_id;

        $employees = Employee::where('organization_id', $orgId)->get();
        $this->totalEmployees = $employees->count();
        $employeeIds = $employees->pluck('id');

        // Attendances today
        $attendancesToday = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->get();

        $this->presentToday = $attendancesToday
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique()
            ->count();

        // Leave arrivals
        $this->absentToday = $attendancesToday
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->pluck('employee_id')
            ->unique()
            ->count();;

        // Leave arrivals
        $this->leaveToday = $attendancesToday
            ->where('status', 'on_leave')
            ->pluck('employee_id')
            ->unique()
            ->count();


        // sick_off
        $this->sickOffToday = $attendancesToday
            ->whereIn('status', ['sick_off'])
            ->pluck('employee_id')
            ->unique()
            ->count();;


        // Get count of inactive employees (active = 0)
        $this->inactiveEmployees = Employee::where('organization_id', $orgId)->where('active', 0)->count();

        //Off Shift
        $this->OffShiftToday = $attendancesToday
            ->where('status', 'off_shift')
            ->pluck('employee_id')
            ->unique()
            ->count();

        // Overtime hours this week
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $this->overtimeHours = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->sum('overtime_hours');

        // Department stats
        $this->departmentStats = $employees->groupBy('department_id')->map(function ($group) {
            $clockedIn = Attendance::whereIn('employee_id', $group->pluck('id'))
                ->whereDate('date', Carbon::today())
                ->whereNotNull('check_in_time')
                ->count();
            $total = $group->count();
            return [
                'name' => $group[0]->department->name ?? 'Unknown',
                'clocked_in' => $clockedIn,
                'total' => $total,
            ];
        });

        // Recent activities (today only, last 5)
        $this->recentActivities = $attendancesToday
            ->sortByDesc('created_at')
            ->take(5);

        // Current employee status
        $this->currentEmployeeStatus = Attendance::with('employee.department')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->orderBy('check_in_time', 'desc')
            ->take(5)
            ->get()
            ->map(fn($att) => [
                'name' => $att->employee->name,
                'department' => $att->employee->department->name ?? 'N/A',
                'status' => match ($att->status) {
                    'clocked_in' => 'Clocked In',
                    'clocked_out' => 'Clocked Out',
                    'absent', 'unchecked_in' => 'Absent',
                    default => ucfirst(str_replace('_', ' ', $att->status)), // fallback
                },
                'datetime' => $att->check_in_time ?? $att->check_out_time,
                'location' => $att->location->name ?? 'Unknown', // Optional if you track location
                'location_details' => $att->location_details ?? null,
                'view_link' => route('attendance.index'),
            ]);


        $this->employeeLocations = Attendance::with('employee.department', 'location')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByRaw("FIELD(status, 'clocked_in', 'clocked_out', 'late') ASC")
            ->orderBy('check_in_time', 'desc')
            ->get()
            ->groupBy('employee_id') // group by employee
            ->map(fn($group) => $group->first()) // pick the latest attendance per employee
            ->map(function ($att) {
                return [
                    'name' => $att->employee->name,
                    'department' => $att->employee->department->name ?? 'N/A',
                    'clock_in' => Carbon::parse($att->check_in_time)->format('h:i A'),
                    'lat' => $att->latitude,
                    'lng' => $att->longitude,
                    'work_location_id' => $att->work_location_id,
                ];
            })
            ->values()
            ->toArray();


        // Work locations with id
        $this->workLocations = WorkLocation::where('organization_id', $orgId)
            ->where('active', true)
            ->get()
            ->map(function ($loc) {
                return [
                    'id' => $loc->id, // 🔹 fix added
                    'name' => $loc->name,
                    'lat' => $loc->latitude,
                    'lng' => $loc->longitude,
                    'radius_m' => $loc->radius_m,
                    'address' => $loc->address,
                ];
            })
            ->toArray();

        $this->dailyAttendancePercentage();

        $this->shiftStats = $this->getShiftStats(); // Add this line


    }


    /**
     * Get today's shift coverage statistics
     */
    private function getShiftStats()
    {
        $organizationId = auth()->user()->employee->organization_id;
        $today = Carbon::today();
        $todayString = $today->toDateString();
        $dayOfWeek = $today->format('D'); // Mon, Tue, Wed, etc.

        // Get all active shifts
        $shifts = DB::table('shifts')
            ->where('status', 'active')
            ->where('organization_id', $organizationId)
            ->get();

        $allShifts = [];

        foreach ($shifts as $shift) {
            // Decode pattern_days from JSON
            $patternDays = json_decode($shift->pattern_days, true) ?? [];

            // Skip this shift if it doesn't run today
            if (!in_array($dayOfWeek, $patternDays)) {
                continue;
            }

            // Get employees assigned to this shift
            $employees = DB::table('employees')
                ->where('shift_id', $shift->id)
                ->where('organization_id', $organizationId)
                ->get(['id']);

            $totalEmployees = $employees->count();

            // If no employees assigned, mark as critical
            if ($totalEmployees === 0) {
                $allShifts[] = ['status' => 'critical'];
                continue;
            }

            // Get attendance for today
            $presentEmployeeIds = DB::table('attendances')
                ->whereDate('date', $todayString)
                ->whereIn('employee_id', $employees->pluck('id'))
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->pluck('employee_id')
                ->unique();

            $presentCount = $presentEmployeeIds->count();

            // Determine status
            if ($presentCount === $totalEmployees) {
                $status = 'full';
            } elseif ($presentCount > ($totalEmployees / 2)) {
                $status = 'partial';
            } else {
                $status = 'critical';
            }

            $allShifts[] = ['status' => $status];
        }

        // Calculate statistics
        $shiftsCollection = collect($allShifts);

        return [
            'total' => $shiftsCollection->count(),
            'full' => $shiftsCollection->where('status', 'full')->count(),
            'partial' => $shiftsCollection->where('status', 'partial')->count(),
            'critical' => $shiftsCollection->where('status', 'critical')->count(),
            'scheduled' => 0, // For future dates
        ];
    }


    public function dailyAttendancePercentage()
    {
        // Get organization_id from logged in user
        $organizationId = auth()->user()->employee->organization_id;

        // Count employees in this org
        $totalEmployees = Employee::where('organization_id', $organizationId)->count();

        // Count present employees today
        $present = Attendance::whereHas('employee', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })
            ->whereDate('date', now()->toDateString())
            ->whereIn('status', ['clocked_in', 'clocked_out']) // ✅ include both
            ->count();

        // Absent = everyone else not present
        $absent = max($totalEmployees - $present, 0);

        // Avoid divide by zero
        if ($totalEmployees > 0) {
            $presentPercent = round(($present / $totalEmployees) * 100, 2);
            $absentPercent = round(($absent / $totalEmployees) * 100, 2);
        } else {
            $presentPercent = $absentPercent = 0;
        }

        $this->statusData = [
            'Present' => $presentPercent,
            'Absent' => $absentPercent,
        ];

    }


}; ?>

@push('styles')
    <style>

        .department-overview-title {
            font-size: 18px;
            font-weight: bold;
            color: #000;
        }

        .department-overview-card,
        .recent-activity-card {
            border-radius: 12px;
        }

        .department-overview-title, .map-title, .quick-actions-title {
            font-weight: bold;
            font-size: 18px;
            color: #000; /* black */
        }

        .recent-activity-title, .map-title, .quick-actions-title {
            font-weight: bold;
            font-size: 14px;
            color: #000; /* black */
        }

        .card-action {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            color: #000;
            background: #fff;
            border: 2px dotted #333; /* dotted border on small cards */
            border-radius: 8px;
            padding: 15px;
            transition: all 0.2s ease-in-out;
            font-weight: 500;
            height: 100%;
            text-align: center;
        }

        .card-action:hover {
            background: #f5f5f5;
            transform: scale(1.05);
        }

        .recent-activity-card {
            border-radius: 12px;
        }

        .activity-item {
            background: linear-gradient(135deg, #f9fbff, #eef4ff);
            border-radius: 12px;
            padding: 12px;
            transition: 0.2s;
            box-shadow: 0 2px 6px rgba(74, 108, 247, 0.08);
        }

        .activity-item:hover {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            box-shadow: 0 4px 10px rgba(74, 108, 247, 0.15);
        }

        .icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dbe4ff, #edf2ff);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4a6cf7;
            font-size: 20px;
            box-shadow: 0 2px 5px rgba(74, 108, 247, 0.2);
        }

        .status {
            font-weight: 500;
            text-transform: lowercase;
        }

        .stat-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
        }

        .stat-text {
            text-align: left;
        }

        .stat-icon {
            font-size: 36px;
            padding: 12px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-blue {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .icon-green {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .icon-red {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .icon-orange {
            background: rgba(251, 146, 60, 0.1);
            color: #fb923c;
        }


        /* Clocked In Badge */
        .badge-clocked-in {
            background-color: #D1F2DC;
            color: #1E7F45;
            padding: 6px 14px;
            border-radius: 999px;
            font-weight: 500;
            font-size: 0.875rem;
        }

        /* Clocked Out Badge */
        .badge-clocked-out {
            background-color: #F8D7DA;
            color: #C82333;
            padding: 6px 14px;
            border-radius: 999px;
            font-weight: 500;
            font-size: 0.875rem;
        }

        /* View Details Button */
        .btn-view-details {
            background-color: #1677FF;
            color: #FFFFFF;
            font-size: 0.875rem;
            padding: 6px 16px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            transition: background-color 0.2s ease;
            text-decoration: none;
        }

        .btn-view-details:hover {
            background-color: #0F62D1;
            color: #fff;
        }


        .pulse-marker {
            position: absolute;
            width: 40px;
            height: 40px;
            background: rgba(255, 0, 0, 0.4);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
            pointer-events: none;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.8);
                opacity: 0.6;
            }
            50% {
                transform: scale(1.5);
                opacity: 0.3;
            }
            100% {
                transform: scale(0.8);
                opacity: 0.6;
            }
        }

    </style>
@endpush


<div class="row g-3">

    <div class="row g-3">

        <!-- First Column (Important Data) - Full Width on Large Screens -->
        <div class="col-lg-6 col-12">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">Present Today</h6>
                        <h3 class="fw-bold text-success">{{ $presentToday }}</h3>
                        <small class="text-muted">
                            {{ number_format(($presentToday / $totalEmployees) * 100, 2) }}%
                            (Total: {{ $totalEmployees }})
                        </small>
                    </div>
                    <div class="stat-icon icon-green fs-3">
                        <span class="iconify" data-icon="mdi:account-check" width="30"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Column (Important Data) - Full Width on Large Screens -->
        <div class="col-lg-6 col-12">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">Absent Today</h6>
                        <h3 class="fw-bold text-danger">{{ $absentToday }}</h3>
                        <small class="text-muted">
                            Out of {{ $totalEmployees }} Total
                        </small>
                    </div>
                    <div class="stat-icon icon-red fs-3">
                        <span class="iconify" data-icon="mdi:account-remove" width="30"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Row for Less Important Stats (Two Equal Columns) -->
    <div style="margin-top:-3px;" class="row g-3">
        <!-- Third Column (Sick Off Today) -->
        <div class="col-lg-3 col-6">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">Sick Off Today</h6>
                        <h3 class="fw-bold text-info">{{ $sickOffToday }}</h3>
                        <small class="text-muted">
                            Out of {{ $totalEmployees }} Total
                        </small>
                    </div>
                    <div class="stat-icon icon-info fs-3">
                        <span class="iconify" data-icon="mdi:medical-bag" width="30"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fourth Column (On Leave Today) -->
        <div class="col-lg-3 col-6">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">On Leave Today</h6>
                        <h3 class="fw-bold text-warning">{{ $leaveToday }}</h3>
                        <small class="text-muted">
                            Out of {{ $totalEmployees }} Total
                        </small>
                    </div>
                    <div class="stat-icon icon-orange fs-3">
                        <span class="iconify" data-icon="mdi:airplane-takeoff" width="30"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fifth Column (Off Shift Today) -->
        <div class="col-lg-3 col-6">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">Off Shift Today</h6>
                        <h3 class="fw-bold text-secondary">{{ $OffShiftToday }}</h3>
                        <small class="text-muted">
                            Out of {{ $totalEmployees }} Total
                        </small>
                    </div>
                    <div class="stat-icon bg-secondary fs-3">
                        <span class="iconify text-white" data-icon="mdi:clock-remove-outline" width="30"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sixth Column (Inactive Employees) -->
        <div class="col-lg-3 col-6">
            <div class="card shadow-sm h-100">
                <div class="stat-card p-3">
                    <div class="stat-text">
                        <h6 class="text-muted mb-1">Inactive Employees</h6>
                        <h3 class="fw-bold text-muted">{{ $inactiveEmployees }}</h3>
                        <small class="text-muted">
                            Out of {{ $totalEmployees }} Total
                        </small>
                    </div>
                    <div class="stat-icon icon-gray fs-3">
                        <span class="iconify" data-icon="mdi:account-off" width="30"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Live Map + Quick Actions -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header map-title fw-semibold">Live Map</div>
            <div class="card-body p-0">
                <div id="map" wire:ignore style="height: 300px; width:100%;"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Attendance Snapshot</h4>
                <div id="daily-attendance"></div>
            </div>
        </div>
    </div>

    <!-- Department Overview -->
    <div class="col-lg-8 d-flex">
        <div class="card shadow-sm flex-fill">
            <div class="card-header department-overview-title fw-semibold">
                Department Overview
            </div>
            <div class="card-body">
                @foreach ($departmentStats as $dept)
                    @php
                        $perc = $dept['total'] ? round(($dept['clocked_in'] / $dept['total']) * 100) : 0;
                    @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small fw-semibold">
                            <span>{{ $dept['name'] }}</span>
                            <span>{{ $dept['clocked_in'] }}/{{ $dept['total'] }} ({{ $perc }}%)</span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-primary" style="width: {{ $perc }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex">
        <div class="card shadow-sm flex-fill">
            <div class="card-header quick-actions-title fw-semibold">
                Quick Actions
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- 🆕 SHIFT COVERAGE CARD (NEW) -->
                    <div class="col-12">
                        <a href="{{ route('shifts.coverage') }}"
                           class="card shadow-sm text-decoration-none shift-coverage-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h6 class="mb-0 fw-semibold text-dark">Shift Monitoring</h6>
                                    <i class="bi bi-calendar-check text-primary" style="font-size: 24px;"></i>
                                </div>

                                <div class="row g-2">
                                    <!-- Total Shifts -->
                                    <div class="col-6">
                                        <div class="text-center p-2 rounded" style="background-color: #f8f9fa;">
                                            <div class="text-muted" style="font-size: 0.75rem;">Total Shifts</div>
                                            <div class="h4 mb-0 fw-bold text-dark">
                                                {{ $shiftStats['total'] }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Fully Staffed -->
                                    <div class="col-6">
                                        <div class="text-center p-2 rounded" style="background-color: #d1f2eb;">
                                            <div class="text-success" style="font-size: 0.75rem;">Fully Staffed</div>
                                            <div class="h4 mb-0 fw-bold text-success">
                                                {{ $shiftStats['full'] }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Partial Coverage -->
                                    <div class="col-6">
                                        <div class="text-center p-2 rounded" style="background-color: #fff3cd;">
                                            <div class="text-warning" style="font-size: 0.75rem;">Partial Coverage</div>
                                            <div class="h4 mb-0 fw-bold text-warning">
                                                {{ $shiftStats['partial'] }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Critical Gaps -->
                                    <div class="col-6">
                                        <div class="text-center p-2 rounded" style="background-color: #f8d7da;">
                                            <div class="text-danger" style="font-size: 0.75rem;">Critical Gaps</div>
                                            <div class="h4 mb-0 fw-bold text-danger">
                                                {{ $shiftStats['critical'] }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Timesheets -->
                    <div class="col-6">
                        <a href="{{ route('attendance.index') }}" class="card-action text-center">
                                <span class="iconify mb-2" data-icon="mdi:clipboard-text-clock-outline"
                                      style="font-size: 28px;"></span>
                            <span>Timesheets</span>
                        </a>
                    </div>

                    <!-- Add Employee -->
                    <div class="col-6">
                        <a href="{{ route('employees.index') }}" class="card-action text-center">
                                <span class="iconify mb-2" data-icon="mdi:account-plus-outline"
                                      style="font-size: 28px;"></span>
                            <span>Add Employee</span>
                        </a>
                    </div>

                    <!-- Export Reports -->
                    <div class="col-6">
                        <a href="{{ route('reports.detailed') }}" class="card-action text-center">
                                <span class="iconify mb-2" data-icon="mdi:file-download-outline"
                                      style="font-size: 28px;"></span>
                            <span>Export Reports</span>
                        </a>
                    </div>

                    <!-- System Settings -->
                    <div class="col-6">
                        <a href="{{ route('system-settings.index') }}" class="card-action text-center">
                            <span class="iconify mb-2" data-icon="mdi:cog-outline" style="font-size: 28px;"></span>
                            <span>System Settings</span>
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>


    <!-- Current Employee Status -->
    <!-- Recent Activity Entries -->
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Recent Activity Entries</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Employee Name</th>
                        <th>Activity</th>
                        <th>Date/Time</th>
                        <th>Location</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($currentEmployeeStatus as $emp)
                        <tr>
                            <!-- Name + Department -->
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-semibold">{{ $emp['name'] }}</span>
                                    <span class="badge bg-light text-primary fw-normal mt-1"
                                          style="width: fit-content;">
                                    {{ $emp['department'] }}
                                </span>
                                </div>
                            </td>

                            <!-- Clocked In / Out -->
                            <td>
                                @if ($emp['status'] === 'Clocked In')
                                    <span class="badge-clocked-in">{{ $emp['status'] }}</span>
                                @elseif ($emp['status'] === 'Clocked Out')
                                    <span class="badge-clocked-out">{{ $emp['status'] }}</span>
                                @else
                                    <span class="badge bg-secondary text-white px-3 py-2 rounded-pill">
                                {{ $emp['status'] }}
                                   </span>
                                @endif
                            </td>

                            <!-- Date and Time -->
                            <td>
                                @if ($emp['datetime'])
                                    <div class="d-flex flex-column">
                                        <span>{{ \Carbon\Carbon::parse($emp['datetime'])->format('g:i A') }}</span>
                                        <small class="text-muted">
                                            {{ \Carbon\Carbon::parse($emp['datetime'])->format('M d, Y') }}
                                        </small>
                                    </div>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>

                            <!-- Location -->
                            <td>
                                <div class="d-flex flex-column">
                                    <span>{{ $emp['location'] ? \Illuminate\Support\Str::ucfirst(strtolower($emp['location'])) : 'N/A' }}</span>
                                </div>
                            </td>

                            <!-- View Button -->
                            <td>
                                <a href="{{ $emp['view_link'] }}" class="btn btn-sm btn-primary">View Details</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>


@push('scripts')
    <script src="https://code.iconify.design/3/3.1.0/iconify.min.js"></script>

    <script>
        const workLocations = @json($workLocations);
        const employeeLocations = @json($employeeLocations);

        function initMap() {
            const map = new google.maps.Map(document.getElementById("map"), {
                zoom: 15,
                center: {lat: -1.2921, lng: 36.8219}, // Nairobi fallback
            });

            const bounds = new google.maps.LatLngBounds();
            let activeInfoWindow = null;

            // --- Draw red geofence ---
            function drawGeofence(position, radius) {
                new google.maps.Circle({
                    strokeColor: "#FF0000",
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: "#FF0000",
                    fillOpacity: 0.15,
                    map,
                    center: position,
                    radius: radius,
                });
            }


            // --- Add marker with info window ---
            function addMarker(position, infoContent) {
                const marker = new google.maps.Marker({
                    position,
                    map,
                    icon: {
                        url: "/images/map_marker.png",  // custom icon path
                        scaledSize: new google.maps.Size(30, 30), // resize (width, height)
                        anchor: new google.maps.Point(15, 15) // ⬅️ center of 30x30 icon
                    }
                });

                // --- Add pulsing overlay ---
                const pulse = document.createElement("div");
                pulse.className = "pulse-marker";

                const overlay = new google.maps.OverlayView();
                overlay.onAdd = function () {
                    const panes = this.getPanes();
                    panes.overlayImage.appendChild(pulse);

                    this.draw = function () {
                        const projection = this.getProjection();
                        const point = projection.fromLatLngToDivPixel(position);
                        if (point) {
                            // ⬅️ pulse is 40x40, so offset by half (20) to keep centered
                            pulse.style.left = point.x - 20 + "px";
                            pulse.style.top = point.y - 20 + "px";
                        }
                    };
                };
                overlay.setMap(map);

                // --- Info window support ---
                if (infoContent) {
                    const infoWindow = new google.maps.InfoWindow({content: infoContent});
                    marker.addListener("click", () => {
                        if (activeInfoWindow) activeInfoWindow.close();
                        infoWindow.open(map, marker);
                        activeInfoWindow = infoWindow;
                    });
                }

                return marker;
            }


            // --- Group employees by work_location_id ---
            const groupedEmployees = {};
            employeeLocations.forEach(emp => {
                if (emp.work_location_id) {
                    if (!groupedEmployees[emp.work_location_id]) {
                        groupedEmployees[emp.work_location_id] = [];
                    }
                    groupedEmployees[emp.work_location_id].push(emp);
                }
            });

            // --- Process each work location ---
            workLocations.forEach(loc => {
                if (loc.lat && loc.lng) {
                    const position = {lat: parseFloat(loc.lat), lng: parseFloat(loc.lng)};
                    bounds.extend(position);
                    drawGeofence(position, loc.radius_m);

                    const emps = groupedEmployees[loc.id] || [];

                    let infoContent = `
                   <div style="background:#fff; padding:12px; border-radius:6px;
                               box-shadow:0 2px 6px rgba(0,0,0,0.15); min-width:220px;">
                       <div style="font-weight:700; font-size:16px; color:#333;">
                           ${loc.name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                       </div>
                       <div style="font-size:13px; color:#555; margin-bottom:8px;">
                           ${loc.address || 'No address provided'}
                       </div>`;

                    if (emps.length > 0) {
                        infoContent += `<div style="font-size:14px; color:#222; margin-bottom:6px;">
                        <b>${emps.length} Employee${emps.length > 1 ? 's' : ''}</b> checked in:
                    </div>`;
                        emps.forEach(e => {
                            infoContent += `<div style="font-size:13px; color:#444; margin-bottom:3px;">
                            <b>${e.name}</b> (${e.department}) - Clock In: ${e.clock_in}
                        </div>`;
                        });
                    }

                    infoContent += `</div>`;

                    addMarker(position, infoContent);
                }
            });

            // --- Fit map bounds ---
            if (!bounds.isEmpty()) {
                map.fitBounds(bounds);

                // 👇 cap zoom (don’t let it zoom out too far)
                google.maps.event.addListenerOnce(map, "bounds_changed", function () {
                    if (map.getZoom() > 16) map.setZoom(16);  // street level
                    if (map.getZoom() < 14) map.setZoom(14);  // prevent zooming out too much
                });
            } else {
                map.setCenter({lat: -1.2921, lng: 36.8219});
                map.setZoom(15);
            }

        }

        const dailydata = @json($statusData);

        const seriesData = Object.values(dailydata);
        const labels = Object.keys(dailydata);

        const options_simple = {
            series: seriesData,
            chart: {
                fontFamily: "inherit",
                type: "pie",
                height: 300,
            },
            colors: ["#28a745", "#dc3545"], // Green for present, Red for absent
            labels: labels,
            legend: {
                position: "bottom",
                horizontalAlign: "center",
                fontSize: "12px",
                labels: {
                    colors: "#a1aab2"
                },
            },
            dataLabels: {
                enabled: true,
                formatter: function (val) {
                    return val.toFixed(0) + "%"; // show clean percentages
                }
            },
        };

        const chart_pie_simple = new ApexCharts(
            document.querySelector("#daily-attendance"),
            options_simple
        );
        chart_pie_simple.render();

    </script>

    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&callback=initMap">
    </script>
@endpush




