<?php

use App\Models\WorkLocation;
use Livewire\Volt\Component;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
    public $entityLabel = 'Employee';

    // School-specific
    public bool $isSchoolOrg = false;
    public bool $showStudentView = false;
    public int $onCampusCount = 0;
    public int $offCampusCount = 0;
    public int $checkedInTodayCount = 0;
    public int $checkedOutTodayCount = 0;
    public int $totalStudents = 0;
    public array $gradeStats = [];

    public function mount(): void
    {
        $this->entityLabel = auth()->user()->employee?->organization?->is_student_record ? 'Student' : 'Employee';
        $this->isSchoolOrg = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);

        // Read ?view= query param to determine which view to show on load
        $viewParam = request()->query('view', $this->isSchoolOrg ? 'student' : 'staff');
        $this->showStudentView = $this->isSchoolOrg && ($viewParam === 'student');

        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

        $today = Carbon::today();
        $orgId = Employee::where('user_id', auth()->id())->value('organization_id');

        // Only load the active view's data — no need to load both
        if ($this->showStudentView) {
            $this->loadSchoolStats($orgId, $today);
        } else {
            $this->loadStaffStats($orgId, $today);
        }
    }

    /* ─────────────────────────────────────────────
       TOGGLE — full page redirect with ?view= param
       so Google Maps reloads cleanly every time
    ───────────────────────────────────────────── */
    public function toggleView(string $mode): void
    {
        $this->redirect(request()->url() . '?view=' . $mode);
    }

    /* ─────────────────────────────────────────────
       SCHOOL / STUDENT STATS
    ───────────────────────────────────────────── */
    private function loadSchoolStats(int $orgId, Carbon $today): void
    {
        $students = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->with('lastAttendance')
            ->get();

        $this->totalStudents = $students->count();
        $studentIds = $students->pluck('id');

        $this->onCampusCount = $students->filter(
            fn($s) => $s->lastAttendance?->status === 'clocked_in'
        )->count();

        $this->offCampusCount = $students->filter(
            fn($s) => $s->lastAttendance?->status === 'clocked_out'
        )->count();

        $todayAttendances = Attendance::whereIn('employee_id', $studentIds)
            ->whereDate('date', $today)
            ->get();

        $this->checkedInTodayCount  = $todayAttendances->where('status', 'clocked_in')->pluck('employee_id')->unique()->count();
        $this->checkedOutTodayCount = $todayAttendances->where('status', 'clocked_out')->pluck('employee_id')->unique()->count();

        $this->gradeStats = $this->loadGradeStats($orgId, $today, $studentIds);

        $this->statusData = [
            'On Campus'  => $this->totalStudents > 0 ? round(($this->onCampusCount  / $this->totalStudents) * 100, 1) : 0,
            'Off Campus' => $this->totalStudents > 0 ? round(($this->offCampusCount / $this->totalStudents) * 100, 1) : 0,
        ];

        $this->currentEmployeeStatus = Attendance::with('employee', 'location')
            ->whereIn('employee_id', $studentIds)
            ->whereDate('date', $today)
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get()
            ->map(fn($att) => [
                'name'             => $att->employee->name,
                'id'               => $att->employee->id,
                'department'       => $att->employee->grade ?? 'N/A',
                'status'           => match ($att->status) {
                    'clocked_in'  => 'Checked In',
                    'clocked_out' => 'Checked Out',
                    default       => ucfirst(str_replace('_', ' ', $att->status)),
                },
                'datetime'         => $att->check_in_time ?? $att->check_out_time ?? $att->updated_at,
                'location'         => $att->location->name ?? 'Unknown',
                'location_details' => $att->location_details ?? null,
                'view_link'        => route('attendance.employee-detailed-attendance', ['employeeId' => $att->employee->id]),
            ]);

        $this->loadMapData($orgId, $today);
    }

    private function loadGradeStats(int $orgId, Carbon $today, $studentIds): array
    {
        $byGrade = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->whereNotNull('grade')
            ->with('lastAttendance')
            ->get()
            ->groupBy('grade');

        $todayStr = $today->toDateString();
        $stats    = [];

        foreach ($byGrade as $grade => $gradeStudents) {
            $ids = $gradeStudents->pluck('id');

            $onCampus = $gradeStudents->filter(
                fn($s) => $s->lastAttendance?->status === 'clocked_in'
            )->count();

            $checkedInToday = Attendance::whereIn('employee_id', $ids)
                ->whereDate('date', $todayStr)
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->distinct('employee_id')
                ->count('employee_id');

            $total = $ids->count();
            $rate  = $total > 0 ? round(($checkedInToday / $total) * 100) : 0;

            $stats[] = [
                'grade'          => $grade,
                'onCampus'       => $onCampus,
                'checkedInToday' => $checkedInToday,
                'total'          => $total,
                'rate'           => $rate,
                'color'          => $rate >= 80 ? '#22c55e' : ($rate >= 60 ? '#f59e0b' : '#ef4444'),
            ];
        }

        return $stats;
    }

    /* ─────────────────────────────────────────────
       STAFF STATS
    ───────────────────────────────────────────── */
    private function loadStaffStats(int $orgId, Carbon $today): void
    {
        // Only non-student active employees
        $employees = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 0)
            ->get();

        $this->totalEmployees    = $employees->count();
        $this->inactiveEmployees = Employee::where('organization_id', $orgId)
            ->where('active', 0)
            ->where('is_student', 0)
            ->count();

        $employeeIds = $employees->pluck('id');

        $attendancesToday = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->get();

        // Present = has ANY clocked_in or clocked_out record today
        $presentIds = $attendancesToday
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique();

        $this->presentToday  = $presentIds->count();
        $this->leaveToday    = $attendancesToday->where('status', 'on_leave')->pluck('employee_id')->unique()->count();
        $this->sickOffToday  = $attendancesToday->where('status', 'sick_off')->pluck('employee_id')->unique()->count();
        $this->OffShiftToday = $attendancesToday->where('status', 'off_shift')->pluck('employee_id')->unique()->count();

        // Accounted for = present OR has some other status record
        $accountedForIds = $attendancesToday
            ->whereIn('status', ['clocked_in', 'clocked_out', 'on_leave', 'sick_off', 'off_shift', 'on_break'])
            ->pluck('employee_id')
            ->unique();

        // Absent = active staff with NO attendance record at all today
        // (not present, not on leave, not sick, not off shift)
        $this->absentToday = max(0, $this->totalEmployees - $accountedForIds->count());

        // Overtime — week scope
        $this->overtimeHours = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->sum('overtime_hours');

        // Department breakdown — also filter is_student = 0
        $this->departmentStats = $employees->groupBy('department_id')->map(function ($group) use ($today) {
            $clockedIn = Attendance::whereIn('employee_id', $group->pluck('id'))
                ->whereDate('date', $today)
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->distinct('employee_id')
                ->count('employee_id');

            return [
                'name'       => $group->first()->department->name ?? 'Unknown',
                'clocked_in' => $clockedIn,
                'total'      => $group->count(),
            ];
        })->values()->toArray();

        // Recent activity — only staff
        $this->currentEmployeeStatus = Attendance::with('employee.department', 'location')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->orderBy('check_in_time', 'desc')
            ->take(5)
            ->get()
            ->map(fn($att) => [
                'name'             => $att->employee->name,
                'id'               => $att->employee->id,
                'department'       => $att->employee->department->name ?? 'N/A',
                'status'           => match ($att->status) {
                    'clocked_in'             => 'Clocked In',
                    'clocked_out'            => 'Clocked Out',
                    'absent', 'unchecked_in' => 'Absent',
                    default                  => ucfirst(str_replace('_', ' ', $att->status)),
                },
                'datetime'         => $att->check_in_time ?? $att->check_out_time,
                'location'         => $att->location->name ?? 'Unknown',
                'location_details' => $att->location_details ?? null,
                'view_link'        => route('attendance.employee-detailed-attendance', ['employeeId' => $att->employee->id]),
            ]);

        $this->dailyAttendancePercentage($employeeIds);
        $this->shiftStats = $this->getShiftStats();
        $this->loadMapData($orgId, $today);
    }


    /* ─────────────────────────────────────────────
       MAP DATA  (shared)
    ───────────────────────────────────────────── */
    private function loadMapData(int $orgId, Carbon $today): void
    {
        $employeeIds = Employee::where('organization_id', $orgId)->pluck('id');

        $this->employeeLocations = Attendance::with('employee.department', 'location')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereIn('status', ['clocked_in', 'clocked_out']) // ← ADD THIS
            ->orderBy('check_in_time', 'desc')
            ->get()
            ->groupBy('employee_id')
            ->map(fn($group) => $group->first())
            ->map(fn($att) => [
                'name'             => $att->employee->name,
                'department'       => $att->employee->department->name ?? 'N/A',
                'clock_in'         => Carbon::parse($att->check_in_time)->format('h:i A'),
                'lat'              => $att->latitude,
                'lng'              => $att->longitude,
                'work_location_id' => $att->work_location_id,
            ])
            ->values()
            ->toArray();

        $this->workLocations = WorkLocation::where('organization_id', $orgId)
            ->where('active', true)
            ->get()
            ->map(fn($loc) => [
                'id'         => $loc->id,
                'name'       => $loc->name,
                'lat'        => $loc->latitude,
                'lng'        => $loc->longitude,
                'radius_m'   => $loc->radius_m,
                'address'    => $loc->address,
                'is_default' => $loc->is_default ?? 0,
            ])
            ->toArray();
    }

    /* ─────────────────────────────────────────────
       SHIFT STATS
    ───────────────────────────────────────────── */
    private function getShiftStats(): array
    {
        $organizationId = auth()->user()->employee->organization_id;
        $today          = Carbon::today();
        $todayString    = $today->toDateString();
        $dayOfWeek      = $today->format('D');

        $shifts    = DB::table('shifts')->where('status', 'active')->where('organization_id', $organizationId)->get();
        $allShifts = [];

        foreach ($shifts as $shift) {
            $patternDays = json_decode($shift->pattern_days, true) ?? [];
            if (!in_array($dayOfWeek, $patternDays)) continue;

            $employees     = DB::table('employees')->where('shift_id', $shift->id)->where('organization_id', $organizationId)->where('active', 1)->get(['id']);
            $totalEmployees = $employees->count();

            if ($totalEmployees === 0) {
                $allShifts[] = ['status' => 'critical'];
                continue;
            }

            $presentCount = DB::table('attendances')
                ->whereDate('date', $todayString)
                ->whereIn('employee_id', $employees->pluck('id'))
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->distinct()->count('employee_id');

            $status      = $presentCount === $totalEmployees ? 'full' : ($presentCount > ($totalEmployees / 2) ? 'partial' : 'critical');
            $allShifts[] = ['status' => $status];
        }

        $c = collect($allShifts);
        return [
            'total'     => $c->count(),
            'full'      => $c->where('status', 'full')->count(),
            'partial'   => $c->where('status', 'partial')->count(),
            'critical'  => $c->where('status', 'critical')->count(),
            'scheduled' => 0,
        ];
    }

    /* ─────────────────────────────────────────────
       DAILY ATTENDANCE PERCENTAGE
    ───────────────────────────────────────────── */
    public function dailyAttendancePercentage($employeeIds = null): void
    {
        $orgId = auth()->user()->employee->organization_id;

        // Use passed IDs (already filtered to non-students) or fall back
        if (!$employeeIds) {
            $employeeIds = Employee::where('organization_id', $orgId)
                ->where('active', 1)
                ->where('is_student', 0)
                ->pluck('id');
        }

        $total = $employeeIds->count();

        $present = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', now()->toDateString())
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->distinct('employee_id')
            ->count('employee_id');

        $absent = max(0, $total - $present);

        $this->statusData = $total > 0 ? [
            'Present' => round(($present / $total) * 100, 2),
            'Absent'  => round(($absent  / $total) * 100, 2),
        ] : ['Present' => 0, 'Absent' => 0];
    }

}; ?>

@push('styles')
    <style>
        /* ── Toggle pill ── */
        .view-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 99px;
            padding: 4px;
            gap: 2px;
        }
        .view-toggle button {
            border: none;
            background: transparent;
            padding: 6px 20px;
            border-radius: 99px;
            font-size: .85rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all .2s;
        }
        .view-toggle button.active {
            background: #fff;
            color: #1e293b;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }
        .view-toggle button:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        /* ── Toggle loading spinner ── */
        .toggle-spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #cbd5e1;
            border-top-color: #64748b;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            margin-right: 4px;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Stat cards ── */
        .stat-card {
            cursor: pointer;
            background: #fff;
            border: none;
            border-radius: 8px;
            padding: 1.25rem;
            height: 100%;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,.1);
        }
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
            color: #fff;
            font-size: 20px;
            margin-bottom: .75rem;
        }
        .icon-success   { background: #10b981; }
        .icon-danger    { background: #ef4444; }
        .icon-info      { background: #3b82f6; }
        .icon-warning   { background: #f59e0b; }
        .icon-cyan      { background: #06b6d4; }
        .icon-secondary { background: #6b7280; }
        .icon-indigo    { background: #6366f1; }
        .icon-sky       { background: #0ea5e9; }

        .stat-card-title {
            margin: 0 0 .5rem;
            font-size: .75rem;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .stat-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
            margin-bottom: .35rem;
        }
        .stat-card-total    { font-size: 1.25rem; color: #9ca3af; font-weight: 400; }
        .stat-card-subtitle { margin: 0; font-size: .875rem; color: #6b7280; }

        /* ── Grade overview panel ── */
        .grade-overview-panel {
            background: #fff;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .grade-overview-panel h6 { font-size: .95rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; }
        .grade-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .grade-row:last-child { border-bottom: none; }
        .grade-label { width: 72px; font-size: .8rem; font-weight: 600; color: #475569; flex-shrink: 0; }
        .grade-bar-wrap { flex: 1; height: 7px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .grade-bar-fill { height: 100%; border-radius: 99px; transition: width .5s ease; }
        .grade-count { font-size: .82rem; font-weight: 700; width: 60px; text-align: right; flex-shrink: 0; white-space: nowrap; }

        /* ── Shift monitoring ── */
        .quick-action-box {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: #fff;
            border: 2px dotted #333;
            border-radius: 8px;
            padding: 15px 10px;
            transition: all .2s ease-in-out;
            height: 100%;
        }
        .quick-action-box:hover { background: #f5f5f5; transform: scale(1.05); cursor: pointer; }
        .quick-action-value { font-size: 1.75rem; font-weight: 700; line-height: 1; margin-bottom: .25rem; color: #333; }
        .quick-action-label { font-size: .875rem; font-weight: 500; color: #333; margin: 0; }

        .text-success { color: #22c55e !important; }
        .text-warning { color: #f59e0b !important; }
        .text-danger  { color: #ef4444 !important; }

        /* ── Shared labels ── */
        .department-overview-title, .shift-monitoring-title { font-size: 18px; font-weight: bold; color: #000; }
        .recent-activity-title, .map-title, .quick-actions-title { font-weight: bold; font-size: 14px; color: #000; }

        .badge-clocked-in, .badge-checked-in {
            background: #D1F2DC; color: #1E7F45;
            padding: 6px 14px; border-radius: 999px; font-weight: 500; font-size: .875rem;
        }
        .badge-clocked-out, .badge-checked-out {
            background: #F8D7DA; color: #C82333;
            padding: 6px 14px; border-radius: 999px; font-weight: 500; font-size: .875rem;
        }

        .pulse-marker {
            position: absolute;
            width: 40px; height: 40px;
            background: rgba(255,0,0,.4);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
            pointer-events: none;
        }
        @keyframes pulse {
            0%   { transform: scale(.8);  opacity: .6 }
            50%  { transform: scale(1.5); opacity: .3 }
            100% { transform: scale(.8);  opacity: .6 }
        }

        /* ── Map wrapper ── */
        .map-wrapper { position: relative; }

        .view-toggle-btn {
            display: inline-block;
            text-decoration: none;
            padding: 6px 20px;
            border-radius: 99px;
            font-size: .85rem;
            font-weight: 600;
            color: #64748b;
            transition: all .2s;
        }
        .view-toggle-btn.active {
            background: #fff;
            color: #1e293b;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }
    </style>
@endpush

<div class="row g-3">

    {{-- ══════════════════════════════════════════
         VIEW TOGGLE  (only shown for school orgs)
    ══════════════════════════════════════════ --}}
    @if($isSchoolOrg)
        <div class="col-12 d-flex align-items-center justify-content-between mb-1">
            <h5 class="mb-0 fw-bold text-dark">
                {{ $showStudentView ? 'Student Attendance' : 'Staff Attendance' }}
            </h5>
            <div class="view-toggle">
                <a href="{{ request()->url() }}?view=student"
                   class="view-toggle-btn {{ $showStudentView ? 'active' : '' }}">
                    <iconify-icon icon="mdi:school" style="font-size:14px; margin-right:4px;"></iconify-icon>
                    Students
                </a>
                <a href="{{ request()->url() }}?view=staff"
                   class="view-toggle-btn {{ !$showStudentView ? 'active' : '' }}">
                    <iconify-icon icon="mdi:briefcase-outline" style="font-size:14px; margin-right:4px;"></iconify-icon>
                    Staff
                </a>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════
         SCHOOL / STUDENT VIEW
    ══════════════════════════════════════════ --}}
    @if($isSchoolOrg && $showStudentView)

        {{-- Row 1: 4 stat cards --}}
        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'present']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-success">
                        <iconify-icon icon="mdi:school"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">On Campus</h6>
                    <div class="stat-card-value">
                        {{ $onCampusCount }}
                        <span class="stat-card-total">/ {{ $totalStudents }}</span>
                    </div>
                    <p class="stat-card-subtitle">Currently in school</p>
                </div>
            </a>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <a href="{{ route('attendance.index', ['filterStatus' => 'clocked_out']) }}" class="stat-card-link">
                <div class="stat-card">
                    <div class="stat-card-icon icon-sky">
                        <iconify-icon icon="mdi:exit-run"></iconify-icon>
                    </div>
                    <h6 class="stat-card-title">Off Campus</h6>
                    <div class="stat-card-value">
                        {{ $offCampusCount }}
                        <span class="stat-card-total">/ {{ $totalStudents }}</span>
                    </div>
                    <p class="stat-card-subtitle">Left school</p>
                </div>
            </a>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-indigo">
                    <iconify-icon icon="mdi:login"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Checked In Today</h6>
                <div class="stat-card-value">
                    {{ $checkedInTodayCount }}
                    <span class="stat-card-total">/ {{ $totalStudents }}</span>
                </div>
                <p class="stat-card-subtitle">Scanned in today</p>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-warning">
                    <iconify-icon icon="mdi:logout"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Checked Out Today</h6>
                <div class="stat-card-value">
                    {{ $checkedOutTodayCount }}
                    <span class="stat-card-total">/ {{ $totalStudents }}</span>
                </div>
                <p class="stat-card-subtitle">Scanned out today</p>
            </div>
        </div>

        {{-- Row 2: Map (full width) --}}
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header map-title fw-semibold">Live Map</div>
                <div class="card-body p-0 map-wrapper">
                    <div id="map" wire:ignore style="height:300px; width:100%;"></div>
                </div>
            </div>
        </div>

        {{-- Row 3: Grade Overview + Attendance Snapshot --}}
        <div class="col-lg-8 col-12">
            <div class="grade-overview-panel h-100">
                <h6>Student Attendance Overview — by Grade</h6>

                @if(count($gradeStats) > 0)
                    <div class="d-flex mb-2" style="padding:0 0 0 80px;">
                        <span style="flex:1; font-size:.7rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px;">Today's Check-ins</span>
                        <span style="width:80px; text-align:right; font-size:.7rem; font-weight:700; color:var(--primary-color) !important; text-transform:uppercase; letter-spacing:.5px;">On Campus</span>
                    </div>
                    @foreach($gradeStats as $row)
                        <div class="grade-row">
                            <span class="grade-label">{{ $row['grade'] }}</span>
                            <div class="grade-bar-wrap">
                                <div class="grade-bar-fill"
                                     style="width:{{ $row['rate'] }}%; background:var(--primary-color) !important;"></div>
                            </div>
                            <span class="grade-count" style="color:var(--primary-color) !important;">
                                {{ $row['checkedInToday'] }}/{{ $row['total'] }}
                            </span>
                            <span style="width:70px; text-align:right; font-size:.8rem; font-weight:600; color:#16a34a; flex-shrink:0;">
                                <iconify-icon icon="mdi:account-check" style="font-size:14px;"></iconify-icon>
                                {{ $row['onCampus'] }}
                            </span>
                        </div>
                    @endforeach
                @else
                    <p class="text-muted small">No grade data available.</p>
                @endif
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card h-100">
                <div class="card-body">
                    <h4 class="card-title">Attendance Snapshot</h4>
                    <div id="daily-attendance"></div>
                </div>
            </div>
        </div>

        {{-- Row 4: Recent Activity --}}
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Recent Activity</div>
                <div class="card-body p-0">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Student Name</th>
                            <th>Grade</th>
                            <th>Activity</th>
                            <th>Date / Time</th>
                            <th>Location</th>
                            <th>Details</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($currentEmployeeStatus as $emp)
                            <tr>
                                <td><span class="fw-semibold">{{ $emp['name'] }}</span></td>
                                <td>
                                    <span class="badge bg-light text-primary fw-normal">{{ $emp['department'] }}</span>
                                </td>
                                <td>
                                    @if($emp['status'] === 'Checked In')
                                        <span class="badge-checked-in">{{ $emp['status'] }}</span>
                                    @elseif($emp['status'] === 'Checked Out')
                                        <span class="badge-checked-out">{{ $emp['status'] }}</span>
                                    @else
                                        <span class="badge bg-secondary text-white px-3 py-2 rounded-pill">{{ $emp['status'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($emp['datetime'])
                                        <div class="d-flex flex-column">
                                            <span>{{ \Carbon\Carbon::parse($emp['datetime'])->format('g:i A') }}</span>
                                            <small class="text-muted">{{ \Carbon\Carbon::parse($emp['datetime'])->format('M d, Y') }}</small>
                                        </div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>{{ $emp['location'] ? \Illuminate\Support\Str::ucfirst(strtolower($emp['location'])) : 'N/A' }}</td>
                                <td>
                                    <a href="{{ route('attendance.index') }}" class="btn btn-sm btn-primary">View Details</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">No activity recorded today.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    @else
        {{-- ══════════════════════════════════════════
             STAFF VIEW
        ══════════════════════════════════════════ --}}

        <div style="padding-right:0px;" class="row g-3">

            <div class="col-lg-6 col-md-6 col-12">
                <a href="{{ route('attendance.index', ['filterStatus' => 'present']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-success">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">Present Today</h6>
                        <div class="stat-card-value">
                            {{ $presentToday }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">{{ number_format(($presentToday / max($totalEmployees, 1)) * 100, 1) }}%</p>
                    </div>
                </a>
            </div>

            <div class="col-lg-6 col-md-6 col-12">
                <a href="{{ route('attendance.index', ['filterStatus' => 'absent']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-danger">
                            <iconify-icon icon="mdi:account-remove"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">Absent Today</h6>
                        <div class="stat-card-value">
                            {{ $absentToday }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                    </div>
                </a>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <a href="{{ route('attendance.index', ['filterStatus' => 'sick_off']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-info">
                            <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">Sick Off Today</h6>
                        <div class="stat-card-value">
                            {{ $sickOffToday }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                    </div>
                </a>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <a href="{{ route('attendance.index', ['filterStatus' => 'on_leave']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-warning">
                            <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">On Leave Today</h6>
                        <div class="stat-card-value">
                            {{ $leaveToday }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                    </div>
                </a>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <a href="{{ route('attendance.index', ['filterStatus' => 'off_shift']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-cyan">
                            <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">Off Shift Today</h6>
                        <div class="stat-card-value">
                            {{ $OffShiftToday }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                    </div>
                </a>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <a href="{{ route('employees.index', ['active' => '0']) }}" class="stat-card-link">
                    <div class="stat-card">
                        <div class="stat-card-icon icon-secondary">
                            <iconify-icon icon="mdi:account-off"></iconify-icon>
                        </div>
                        <h6 class="stat-card-title">Inactive {{ $entityLabel }}s</h6>
                        <div class="stat-card-value">
                            {{ $inactiveEmployees }}
                            <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                        </div>
                        <p class="stat-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                    </div>
                </a>
            </div>

        </div>

        {{-- Map + Snapshot --}}
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header map-title fw-semibold">Live Map</div>
                <div class="card-body p-0 map-wrapper">
                    <div id="map" wire:ignore style="height:300px; width:100%;"></div>
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

        {{-- Department Overview --}}
        <div style="margin-top:5px;" class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header department-overview-title fw-semibold">Department Overview</div>
                <div class="card-body">
                    @foreach ($departmentStats as $dept)
                        @php $perc = $dept['total'] ? round(($dept['clocked_in'] / $dept['total']) * 100) : 0; @endphp
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small fw-semibold">
                                <span>{{ $dept['name'] }}</span>
                                <span>{{ $dept['clocked_in'] }}/{{ $dept['total'] }} ({{ $perc }}%)</span>
                            </div>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar bg-primary" style="width:{{ $perc }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Shift Monitoring --}}
        <div style="margin-top:5px;" class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header shift-monitoring-title fw-semibold">Shift Monitoring</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value">{{ $shiftStats['total'] }}</div>
                                <div class="quick-action-label">Total Shifts</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-success">{{ $shiftStats['full'] }}</div>
                                <div class="quick-action-label">Fully Staffed</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-warning">{{ $shiftStats['partial'] }}</div>
                                <div class="quick-action-label">Partial Coverage</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="quick-action-box text-center">
                                <div class="quick-action-value text-danger">{{ $shiftStats['critical'] }}</div>
                                <div class="quick-action-label">Critical Gaps</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <a href="{{ route('shifts.coverage') }}"
                               class="quick-action-box text-center text-decoration-none d-block">
                                <span class="me-2">View Full Coverage</span>
                                <iconify-icon icon="mdi:arrow-right"></iconify-icon>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Activity --}}
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Recent Activity Entries</div>
                <div class="card-body p-0">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>{{ $entityLabel }} Name</th>
                            <th>Activity</th>
                            <th>Date / Time</th>
                            <th>Location</th>
                            <th>Details</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($currentEmployeeStatus as $emp)
                            <tr>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $emp['name'] }}</span>
                                        <span class="badge bg-light text-primary fw-normal mt-1"
                                              style="width:fit-content;">{{ $emp['department'] }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if($emp['status'] === 'Clocked In')
                                        <span class="badge-clocked-in">{{ $emp['status'] }}</span>
                                    @elseif($emp['status'] === 'Clocked Out')
                                        <span class="badge-clocked-out">{{ $emp['status'] }}</span>
                                    @else
                                        <span class="badge bg-secondary text-white px-3 py-2 rounded-pill">{{ $emp['status'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($emp['datetime'])
                                        <div class="d-flex flex-column">
                                            <span>{{ \Carbon\Carbon::parse($emp['datetime'])->format('g:i A') }}</span>
                                            <small class="text-muted">{{ \Carbon\Carbon::parse($emp['datetime'])->format('M d, Y') }}</small>
                                        </div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>{{ $emp['location'] ? \Illuminate\Support\Str::ucfirst(strtolower($emp['location'])) : 'N/A' }}</td>
                                <td>
                                    <a href="{{ route('attendance.employee-detailed-attendance', ['employeeId' => $emp['id']]) }}"
                                       class="btn btn-sm btn-primary">View Details</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    @endif

</div>

@push('scripts')
    <script src="https://code.iconify.design/3/3.1.0/iconify.min.js"></script>
    <script>
        // ── Blade-rendered PHP data ──────────────────────────────────────
        const workLocations     = @json($workLocations);
        const employeeLocations = @json($employeeLocations);
        const isSchoolOrg       = @json($isSchoolOrg);
        const showStudentView   = @json($showStudentView);
        const dailyStatusData   = @json($statusData);

        // ── Chart instance ───────────────────────────────────────────────
        let chartInstance = null;

        // ── Map ──────────────────────────────────────────────────────────
        // Called automatically by the Google Maps script tag via callback=initMap.
        // Because we do a full page reload on toggle, this always fires fresh.
        function initMap() {
            const mapEl = document.getElementById('map');
            if (!mapEl) return;

            const defaultLocation = workLocations.find(loc => loc.is_default === 1);
            const initialCenter   = defaultLocation
                ? { lat: parseFloat(defaultLocation.lat), lng: parseFloat(defaultLocation.lng) }
                : { lat: -1.2921, lng: 36.8219 };

            const mapInstance    = new google.maps.Map(mapEl, { zoom: 15, center: initialCenter });
            const bounds         = new google.maps.LatLngBounds();
            let   activeInfoWindow = null;

            // Draw geofence circle
            function drawGeofence(position, radius) {
                new google.maps.Circle({
                    strokeColor: '#FF0000', strokeOpacity: .8, strokeWeight: 2,
                    fillColor: '#FF0000', fillOpacity: .15,
                    map: mapInstance, center: position, radius,
                });
            }

            // Add marker with pulse animation and info window
            function addMarker(position, infoContent) {
                const marker = new google.maps.Marker({
                    position,
                    map: mapInstance,
                    icon: {
                        url: '/images/map_marker.png',
                        scaledSize: new google.maps.Size(30, 30),
                        anchor: new google.maps.Point(15, 15),
                    },
                });

                // Pulse overlay
                const pulse   = document.createElement('div');
                pulse.className = 'pulse-marker';
                const overlay = new google.maps.OverlayView();
                overlay.onAdd = function () {
                    this.getPanes().overlayImage.appendChild(pulse);
                    this.draw = function () {
                        const pt = this.getProjection().fromLatLngToDivPixel(position);
                        if (pt) {
                            pulse.style.left = (pt.x - 20) + 'px';
                            pulse.style.top  = (pt.y - 20) + 'px';
                        }
                    };
                };
                overlay.setMap(mapInstance);

                // Info window on click
                if (infoContent) {
                    const iw = new google.maps.InfoWindow({ content: infoContent });
                    marker.addListener('click', () => {
                        if (activeInfoWindow) activeInfoWindow.close();
                        iw.open(mapInstance, marker);
                        activeInfoWindow = iw;
                    });
                }

                return marker;
            }

            // ── Group employees by work_location_id ───────────────────────
            const grouped = {};
            employeeLocations.forEach(emp => {
                const key = emp.work_location_id != null ? emp.work_location_id : 'unassigned';
                if (!grouped[key]) grouped[key] = [];
                grouped[key].push(emp);
            });

            const label = (isSchoolOrg && showStudentView) ? 'Student' : 'Employee';

            // ── Render each work location ─────────────────────────────────
            workLocations.forEach(loc => {
                if (!loc.lat || !loc.lng) return;

                const pos = { lat: parseFloat(loc.lat), lng: parseFloat(loc.lng) };
                bounds.extend(pos);
                drawGeofence(pos, loc.radius_m);

                // Merge employees for this location + unassigned → default location
                let emps = [...(grouped[loc.id] || [])];
                if (loc.is_default && grouped['unassigned']) {
                    emps = emps.concat(grouped['unassigned']);
                }

                // ── Build info window HTML ────────────────────────────────
                const locName = loc.name
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, c => c.toUpperCase());

                let info = `
                <div style="
                    background:#fff;
                    padding:14px 16px;
                    border-radius:10px;
                    box-shadow:0 4px 16px rgba(0,0,0,.12);
                    min-width:260px;
                    max-width:320px;
                    max-height:340px;
                    overflow-y:auto;
                    font-family:inherit;
                ">
                    <div style="font-weight:700;font-size:15px;color:#1e293b;margin-bottom:2px;">
                        ${locName}
                    </div>
                    <div style="font-size:12px;color:#94a3b8;margin-bottom:12px;">
                        ${loc.address || 'No address provided'}
                    </div>`;

                if (emps.length > 0) {
                    info += `
                    <div style="
                        font-size:12px;font-weight:700;color:#64748b;
                        text-transform:uppercase;letter-spacing:.5px;
                        margin-bottom:8px;
                    ">
                        ${emps.length} ${label}${emps.length !== 1 ? 's' : ''} checked in
                    </div>`;

                    emps.forEach(e => {
                        const initial = e.name ? e.name.charAt(0).toUpperCase() : '?';
                        info += `
                        <div style="
                            display:flex;align-items:center;gap:10px;
                            padding:8px 0;border-bottom:1px solid #f1f5f9;
                        ">
                            <div style="
                                width:32px;height:32px;border-radius:50%;
                                background:#e0e7ff;
                                display:flex;align-items:center;justify-content:center;
                                font-size:13px;font-weight:700;color:#4f46e5;
                                flex-shrink:0;
                            ">${initial}</div>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#1e293b;line-height:1.3;">
                                    ${e.name}
                                </div>
                                <div style="font-size:11px;color:#64748b;margin-top:1px;">
                                    ${e.department}
                                    &nbsp;&bull;&nbsp;
                                    <span style="color:#10b981;font-weight:600;">In: ${e.clock_in}</span>
                                </div>
                            </div>
                        </div>`;
                    });
                } else {
                    info += `
                    <div style="font-size:13px;color:#94a3b8;font-style:italic;padding:8px 0;">
                        No check-ins at this location yet.
                    </div>`;
                }

                info += `</div>`;
                addMarker(pos, info);
            });

            // Fit map to bounds when no default centre is set
            if (!defaultLocation && !bounds.isEmpty()) {
                mapInstance.fitBounds(bounds);
                google.maps.event.addListenerOnce(mapInstance, 'bounds_changed', () => {
                    if (mapInstance.getZoom() > 16) mapInstance.setZoom(16);
                    if (mapInstance.getZoom() < 14) mapInstance.setZoom(14);
                });
            }
        }

        // ── Chart ────────────────────────────────────────────────────────
        function initChart() {
            const chartEl = document.querySelector('#daily-attendance');
            if (!chartEl) return;

            if (chartInstance) {
                try { chartInstance.destroy(); } catch (_) {}
                chartInstance = null;
            }
            chartEl.innerHTML = '';

            const chartColors = (isSchoolOrg && showStudentView)
                ? ['#22c55e', '#0ea5e9']
                : ['#28a745', '#dc3545'];

            chartInstance = new ApexCharts(chartEl, {
                series: Object.values(dailyStatusData),
                chart: {
                    fontFamily: 'inherit',
                    type: 'pie',
                    height: 300,
                },
                colors: chartColors,
                labels: Object.keys(dailyStatusData),
                legend: {
                    position: 'bottom',
                    horizontalAlign: 'center',
                    fontSize: '12px',
                    labels: { colors: '#a1aab2' },
                },
                dataLabels: {
                    enabled: true,
                    formatter: val => val.toFixed(0) + '%',
                },
            });
            chartInstance.render();
        }

        // ── Boot on page load ────────────────────────────────────────────
        // initMap() is called by the Google Maps script tag via callback=initMap.
        // We only need to boot the chart here manually.
        document.addEventListener('DOMContentLoaded', () => {
            initChart();
        });

        // ── No livewire:update map re-init needed ────────────────────────
        // The toggle now does a full page redirect, so the Maps API script
        // fires initMap() fresh on every load — no manual re-init required.
        // We only re-init the chart here in case other Livewire interactions
        // on this page cause a re-render.
        document.addEventListener('livewire:update', () => {
            setTimeout(() => initChart(), 150);
        });
    </script>

    {{-- Google Maps — callback=initMap fires automatically after script loads --}}
    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&callback=initMap">
    </script>
@endpush
