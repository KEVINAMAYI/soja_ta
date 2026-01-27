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

        $employees = Employee::where('organization_id', $orgId)->where('active',1)->get();
        $this->totalEmployees = $employees->count();
        $employeeIds = $employees->pluck('id');

        // Attendances today
        $attendancesToday = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->get();

        // Step 1: Get employees who actually showed up (clocked in or out)
        $presentEmployeeIds = $attendancesToday
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique();

       // Step 2: Get employees marked absent BUT exclude those who showed up
        $absentEmployeeIds = $attendancesToday
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->pluck('employee_id')
            ->unique()
            ->reject(fn($id) => $presentEmployeeIds->contains($id)); // Key fix!

        $this->presentToday = $presentEmployeeIds->count();
        $this->absentToday = $absentEmployeeIds->count();

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
                ->where('active', 1)
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

        .department-overview-title, .shift-monitoring-title {
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
        .shift-monitoring-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .shift-monitoring-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        /* Header */
        .shift-monitoring-card .card-header {
            background: linear-gradient(135deg, #e14326 0%, #e14326 100%);
            padding: 8px;
            padding-left: 15px;
            border: none;
        }

        .shift-monitoring-card .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .shift-monitoring-card .icon-wrapper {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .shift-monitoring-card .icon-wrapper iconify-icon {
            font-size: 24px;
            color: white;
        }

        .shift-monitoring-card .title {
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.5px;
        }

        /* Body */
        .shift-monitoring-card .card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        /* Stat Box */
        .stat-box {
            padding: 10px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: currentColor;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-4px);
        }

        .stat-box:hover::before {
            opacity: 1;
        }

        /* Stat Icons */
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon iconify-icon {
            font-size: 24px;
        }

        /* Stat Content */
        .stat-content {
            flex: 1;
            min-width: 0;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.8;
        }

        /* Total Stats - Neutral Gray */
        .stat-total {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
        }

        .stat-total .stat-icon {
            color: #334155;
        }

        /* Success - Green */
        .stat-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .stat-success .stat-icon {
            color: #059669;
        }

        /* Warning - Yellow */
        .stat-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #78350f;
        }

        .stat-warning .stat-icon {
            color: #d97706;
        }

        /* Danger - Red */
        .stat-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
        }

        .stat-danger .stat-icon {
            color: #dc2626;
        }

        /* View Details Link */
        .view-details {
            text-align: center;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            margin-top: auto;
        }

        .view-link {
            color: #667eea;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            transition: all 0.3s ease;
        }

        .shift-monitoring-card:hover .view-link {
            gap: 0.45rem;
            color: #764ba2;
        }

        .view-link iconify-icon {
            transition: transform 0.3s ease;
        }

        .shift-monitoring-card:hover .view-link iconify-icon {
            transform: translateX(4px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .shift-monitoring-card .card-header {
                padding: 1.25rem;
            }

            .shift-monitoring-card .card-body {
                padding: 1.25rem;
            }

            .stats-grid {
                gap: 0.75rem;
            }

            .stat-box {
                padding: 1rem;
                flex-direction: column;
                text-align: center;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
            }

            .stat-icon iconify-icon {
                font-size: 20px;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-label {
                font-size: 0.7rem;
            }
        }

        /* Loading Animation (Optional) */
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        .stat-box.loading {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* Quick Action Box - Matching Your Image Style */
        .quick-action-box {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: #fff;
            border: 2px dotted #333;
            border-radius: 8px;
            padding: 15px 10px;
            transition: all 0.2s ease-in-out;
            height: 100%;
        }

        .quick-action-box:hover {
            background: #f5f5f5;
            transform: scale(1.05);
            cursor: pointer;
        }

        .quick-action-icon {
            font-size: 32px;
            color: #333;
        }

        .quick-action-icon iconify-icon {
            display: block;
        }

        .quick-action-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
            color: #333;
        }

        .quick-action-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #333;
            margin: 0;
        }

        /* Color variants - only on icons and values */
        .text-success {
            color: #22c55e !important;
        }

        .text-warning {
            color: #f59e0b !important;
        }

        .text-danger {
            color: #ef4444 !important;
        }

        /* Link styling for View Full Coverage */
        a.quick-action-box {
            color: #333;
        }

        a.quick-action-box:hover {
            color: #333;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .quick-action-box {
                min-height: 90px;
                padding: 12px 8px;
            }

            .quick-action-icon {
                font-size: 28px;
            }

            .quick-action-value {
                font-size: 1.5rem;
            }

            .quick-action-label {
                font-size: 0.75rem;
            }
        }

        .stat-card {
            cursor: pointer;
            background: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 1.25rem;
            height: 100%;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }


        /* Add a subtle pointer cursor effect */
        a.stat-card-link {
            display: block;
            text-decoration: none;
            color: inherit;
        }

        a.stat-card-link:hover {
            text-decoration: none;
            color: inherit;
        }

        .stat-card-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            color: white;
            font-size: 20px;
            margin-bottom: 0.75rem;
        }

        .icon-success {
            background-color: #10b981;
        }

        .icon-danger {
            background-color: #ef4444;
        }

        .icon-info {
            background-color: #3b82f6;
        }

        .icon-warning {
            background-color: #f59e0b;
        }

        .icon-cyan {
            background-color: #06b6d4;
        }

        .icon-secondary {
            background-color: #6b7280;
        }

        .stat-card-title {
            margin: 0 0 0.5rem 0;
            font-size: 0.75rem;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .stat-card-total {
            font-size: 1.25rem;
            color: #9ca3af;
            font-weight: 400;
        }

        .stat-card-subtitle {
            margin: 0;
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 400;
        }

    </style>
@endpush


<div class="row g-3">

    <!-- Dashboard Statistics Cards -->
    <div style="padding-right:0px;" class="row g-3">
        <!-- Present Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'present']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-success">
                        <iconify-icon icon="mdi:account-check"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Present Today</h6>
                    <div class="stat-card-value">{{ $presentToday }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        {{ number_format(($presentToday / $totalEmployees) * 100, 1) }}%
                    </p>
                </div>
            </a>
        </div>

        <!-- Absent Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'absent']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-danger">
                        <iconify-icon icon="mdi:account-remove"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Absent Today</h6>
                    <div class="stat-card-value">{{ $absentToday }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        Out of {{ $totalEmployees }} Total
                    </p>
                </div>
            </a>
        </div>

        <!-- Sick Off Today -->
        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'sick_off']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-info">
                        <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Sick Off Today</h6>
                    <div class="stat-card-value">{{ $sickOffToday }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        Out of {{ $totalEmployees }} Total
                    </p>
                </div>
            </a>
        </div>

        <!-- On Leave Today -->
        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'on_leave']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-warning">
                        <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">On Leave Today</h6>
                    <div class="stat-card-value">{{ $leaveToday }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        Out of {{ $totalEmployees }} Total
                    </p>
                </div>
            </a>
        </div>

        <!-- Off Shift Today -->
        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'off_shift']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-cyan">
                        <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Off Shift Today</h6>
                    <div class="stat-card-value">{{ $OffShiftToday }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        Out of {{ $totalEmployees }} Total
                    </p>
                </div>
            </a>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('employees.index', ['active' => '0']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-secondary">
                        <iconify-icon icon="mdi:account-off"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Inactive Employees</h6>
                    <div class="stat-card-value">{{ $inactiveEmployees }} <span class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                    <p class="stat-card-subtitle">
                        Out of {{ $totalEmployees }} Total
                    </p>
                </div>
            </a>
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
        <div style="margin-top:5px;" class="col-lg-8">
            <div class="card shadow-sm h-100">
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

        <!-- Shift Monitoring -->
        <div style="margin-top:5px;" class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header shift-monitoring-title fw-semibold">
                    Shift Monitoring
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Total Shifts -->
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value">{{ $shiftStats['total'] }}</div>
                                <div class="quick-action-label">Total Shifts</div>
                            </div>
                        </div>

                        <!-- Fully Staffed -->
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-success">{{ $shiftStats['full'] }}</div>
                                <div class="quick-action-label">Fully Staffed</div>
                            </div>
                        </div>

                        <!-- Partial Coverage -->
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-warning">{{ $shiftStats['partial'] }}</div>
                                <div class="quick-action-label">Partial Coverage</div>
                            </div>
                        </div>

                        <!-- Critical Gaps -->
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-danger">{{ $shiftStats['critical'] }}</div>
                                <div class="quick-action-label">Critical Gaps</div>
                            </div>
                        </div>

                        <!-- View Full Coverage -->
                        <div class="col-12">
                            <a href="{{ route('shifts.coverage') }}" class="quick-action-box text-center text-decoration-none d-block">
                                <span class="me-2">View Full Coverage</span>
                                <iconify-icon icon="mdi:arrow-right"></iconify-icon>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>


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




