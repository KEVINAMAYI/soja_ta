<?php

use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;

new class extends Component {
    use WithPagination;

    public $startDate;
    public $endDate;

    public $totalEmployees   = 0;
    public $avgPresent       = 0;
    public $avgAbsent        = 0;
    public $avgLate          = 0;

    public $attendanceTrend  = '';
    public $latenessTrend    = '';
    public $absenteeismTrend = '';

    public $perPagePresent = 10;
    public $perPageLeave   = 10;
    public $perPageDept    = 10;

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->startDate = now()->startOfWeek()->toDateString();
        $this->endDate   = now()->endOfWeek()->toDateString();
    }

    public function updatedStartDate() { $this->validateDateRange(); $this->resetPage(); }
    public function updatedEndDate()   { $this->validateDateRange(); $this->resetPage(); }

    private function validateDateRange()
    {
        if ($this->startDate && $this->endDate) {
            if (Carbon::parse($this->startDate)->gt(Carbon::parse($this->endDate))) {
                $this->endDate = $this->startDate;
            }
        }
    }

    public function setCurrentWeek()  { $this->startDate = now()->startOfWeek()->toDateString();        $this->endDate = now()->endOfWeek()->toDateString();        $this->resetPage(); }
    public function setLastWeek()     { $this->startDate = now()->subWeek()->startOfWeek()->toDateString(); $this->endDate = now()->subWeek()->endOfWeek()->toDateString(); $this->resetPage(); }
    public function setCurrentMonth() { $this->startDate = now()->startOfMonth()->toDateString();       $this->endDate = now()->endOfMonth()->toDateString();       $this->resetPage(); }
    public function setLastMonth()    { $this->startDate = now()->subMonth()->startOfMonth()->toDateString(); $this->endDate = now()->subMonth()->endOfMonth()->toDateString(); $this->resetPage(); }

    public function getDaysInRange()
    {
        if (!$this->startDate || !$this->endDate) return 0;
        return Carbon::parse($this->startDate)->diffInDays(Carbon::parse($this->endDate)) + 1;
    }

    public function with()
    {
        $orgId      = auth()->user()->employee->organization_id ?? null;
        $rangeStart = Carbon::parse($this->startDate);
        $rangeEnd   = Carbon::parse($this->endDate);

        $this->totalEmployees = Employee::where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        $rangeAttendances = Attendance::with(['employee.department'])
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->when($orgId, fn($q) => $q->whereHas('employee',
                fn($q2) => $q2->where('organization_id', $orgId)
            ))
            ->get();

        // ── Averages ──────────────────────────────────────────────────
        $dailyPresent = $rangeAttendances->whereIn('status', ['clocked_in', 'clocked_out'])
            ->groupBy('date')->map(fn($d) => $d->count());
        $dailyAbsent  = $rangeAttendances->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('date')->map(fn($d) => $d->count());
        $dailyLate    = $rangeAttendances->where('is_late_checkin', 1)
            ->groupBy('date')->map(fn($d) => $d->count());

        $this->avgPresent = $dailyPresent->count() > 0 ? round($dailyPresent->avg()) : 0;
        $this->avgAbsent  = $dailyAbsent->count()  > 0 ? round($dailyAbsent->avg())  : 0;
        $this->avgLate    = $dailyLate->count()    > 0 ? round($dailyLate->avg())    : 0;

        // ── Trends vs previous same-length period ─────────────────────
        $days          = $this->getDaysInRange();
        $prevStart     = $rangeStart->copy()->subDays($days);
        $prevEnd       = $rangeEnd->copy()->subDays($days);

        $prevAttendances = Attendance::whereBetween('date', [$prevStart, $prevEnd])
            ->when($orgId, fn($q) => $q->whereHas('employee',
                fn($q2) => $q2->where('organization_id', $orgId)
            ))
            ->get();

        $prevAvgPresent = $prevAttendances->whereIn('status', ['clocked_in', 'clocked_out'])
            ->groupBy('date')->map(fn($d) => $d->count())->avg() ?: 0;
        $prevAvgLate    = $prevAttendances->where('is_late_checkin', 1)
            ->groupBy('date')->map(fn($d) => $d->count())->avg() ?: 0;
        $prevAvgAbsent  = $prevAttendances->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('date')->map(fn($d) => $d->count())->avg() ?: 0;

        $this->attendanceTrend  = $this->calculateTrend($this->avgPresent, $prevAvgPresent);
        $this->latenessTrend    = $this->calculateTrend($prevAvgLate, $this->avgLate);
        $this->absenteeismTrend = $this->calculateTrend($prevAvgAbsent, $this->avgAbsent);

        // ── Absent — chips only, no table ─────────────────────────────
        $absentEmployeesData = $rangeAttendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('employee_id')
            ->map(function($absences) {
                return [
                    'employee'   => $absences->first()->employee,
                    'total_days' => $absences->count(),
                ];
            })
            ->sortByDesc('total_days')
            ->values();

        // ── Present employees — table ─────────────────────────────────
        $presentEmployeesData = $rangeAttendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->groupBy('employee_id')
            ->map(function($records) {
                $days     = $records->map(fn($a) => Carbon::parse($a->date)->format('D, M j'))->unique()->values()->toArray();
                $lateDays = $records->where('is_late_checkin', 1)->count();
                $otHours  = $records->sum('overtime_hours') ?? 0;
                return [
                    'employee'   => $records->first()->employee,
                    'days'       => $days,
                    'total_days' => count($days),
                    'late_days'  => $lateDays,
                    'ot_hours'   => $otHours,
                ];
            })
            ->sortByDesc('total_days')
            ->values();

        $presentPage      = request()->get('presentPage', 1);
        $presentOffset    = ($presentPage - 1) * $this->perPagePresent;
        $presentPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $presentEmployeesData->slice($presentOffset, $this->perPagePresent)->values(),
            $presentEmployeesData->count(),
            $this->perPagePresent,
            $presentPage,
            ['path' => request()->url(), 'pageName' => 'presentPage']
        );

        // ── Employees on Leave ────────────────────────────────────────
        $leavesInRange = Leave::with(['employee.department'])
            ->where('status', 'approved')
            ->where(function($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart, $rangeEnd])
                    ->orWhereBetween('end_date', [$rangeStart, $rangeEnd])
                    ->orWhere(fn($q2) => $q2->where('start_date', '<=', $rangeStart)->where('end_date', '>=', $rangeEnd));
            })
            ->when($orgId, fn($q) => $q->whereHas('employee',
                fn($q2) => $q2->where('organization_id', $orgId)
            ))
            ->get();

        $employeesOnLeaveData = collect();
        foreach ($leavesInRange as $leave) {
            $leaveStart = Carbon::parse($leave->start_date)->max($rangeStart);
            $leaveEnd   = Carbon::parse($leave->end_date)->min($rangeEnd);
            $days = [];
            for ($d = $leaveStart->copy(); $d->lte($leaveEnd); $d->addDay()) {
                if ($d->isWeekday()) $days[] = $d->format('D, M j');
            }
            if (!empty($days)) {
                $employeesOnLeaveData->push([
                    'employee'   => $leave->employee,
                    'leave_type' => $leave->leave_type,
                    'days'       => $days,
                    'total_days' => count($days),
                ]);
            }
        }
        $employeesOnLeaveData = $employeesOnLeaveData->sortByDesc('total_days');

        $leavePage      = request()->get('leavePage', 1);
        $leaveOffset    = ($leavePage - 1) * $this->perPageLeave;
        $leavePaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $employeesOnLeaveData->slice($leaveOffset, $this->perPageLeave)->values(),
            $employeesOnLeaveData->count(),
            $this->perPageLeave,
            $leavePage,
            ['path' => request()->url(), 'pageName' => 'leavePage']
        );

        // ── Department Breakdown ──────────────────────────────────────
        $departments = Employee::with('department')
            ->where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get()->groupBy('department_id');

        $departmentBreakdownData = collect();
        foreach ($departments as $deptId => $employees) {
            $department     = $employees->first()->department;
            $total          = $employees->count();
            $deptPresent    = Attendance::whereIn('employee_id', $employees->pluck('id'))
                ->whereBetween('date', [$rangeStart, $rangeEnd])
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->get();
            $avgPresent     = $deptPresent->groupBy('date')->map(fn($d) => $d->count())->avg() ?: 0;
            $attendanceRate = $total > 0 ? round(($avgPresent / $total) * 100, 2) : 0;
            $departmentBreakdownData->push([
                'department'      => $department->name ?? 'Unassigned',
                'total'           => $total,
                'avg_present'     => round($avgPresent, 1),
                'attendance_rate' => $attendanceRate,
                'visual'          => $attendanceRate,
            ]);
        }

        $departmentBreakdownData = $departmentBreakdownData->sortByDesc('total');
        $deptPage      = request()->get('deptPage', 1);
        $deptOffset    = ($deptPage - 1) * $this->perPageDept;
        $deptPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $departmentBreakdownData->slice($deptOffset, $this->perPageDept)->values(),
            $departmentBreakdownData->count(),
            $this->perPageDept,
            $deptPage,
            ['path' => request()->url(), 'pageName' => 'deptPage']
        );

        return [
            'absentEmployeesData'    => $absentEmployeesData,   // plain collection — chips only
            'presentEmployeesData'   => $presentPaginated,
            'employeesOnLeaveData'   => $leavePaginated,
            'departmentBreakdownData'=> $deptPaginated,
        ];
    }

    private function calculateTrend($current, $previous)
    {
        if ($previous == 0) return $current > 0 ? '+100%' : '0%';
        $change = (($current - $previous) / $previous) * 100;
        return ($change >= 0 ? '+' : '') . number_format($change, 1) . '%';
    }

    public function getProgressBarColor($rate)
    {
        if ($rate >= 90) return 'success';
        if ($rate >= 75) return 'warning';
        return 'danger';
    }

    public function getTrendColor($trend)
    {
        if (str_starts_with($trend, '+')) return 'success';
        if (str_starts_with($trend, '-')) return 'danger';
        return 'secondary';
    }
}; ?>

@push('styles')
    <style>
        .dsr-bar {
            display: grid;
            grid-template-columns: 1fr 1px 1fr 1px 1fr 1px 1fr;
            background: #fff; border: 1px solid #e5e7eb;
            border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem; width: 100%;
        }
        .dsr-bar-cell { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.25rem 0.75rem 1rem; text-align: center; }
        .dsr-bar-divider { background: #e5e7eb; }
        .dsr-val { font-size: 2.2rem; font-weight: 800; line-height: 1; letter-spacing: -1px; }
        .dsr-lbl { font-size: 0.63rem; font-weight: 700; color: #9ca3af; letter-spacing: 0.07em; text-transform: uppercase; margin-top: 0.3rem; }
        .dsr-section-lbl { display: block; font-size: 0.68rem; font-weight: 700; color: #9ca3af; letter-spacing: 0.09em; text-transform: uppercase; margin-bottom: 0.35rem; }
        .dsr-section-hr { border: none; border-top: 1px solid #e5e7eb; margin: 0 0 1rem 0; }
        .dsr-hl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem 1.15rem; min-height: 88px; height: 100%; display: flex; flex-direction: column; justify-content: center; gap: 0.1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 2px 6px rgba(0,0,0,0.03); }
        .dsr-hl-icon { font-size: 1rem; margin-bottom: 0.2rem; line-height: 1; }
        .dsr-hl-val  { font-size: 1.15rem; font-weight: 800; line-height: 1.2; }
        .dsr-hl-sub  { font-size: 0.77rem; color: #6b7280; margin-top: 0.2rem; }
        .dsr-chip { display: inline-flex; align-items: center; gap: 0.45rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.4rem 0.7rem; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dsr-chip-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .dsr-chip-dot-red    { background: #ef4444; }
        .dsr-chip-dot-yellow { background: #f59e0b; }
        .dsr-chip-name { font-size: 0.82rem; font-weight: 600; color: #111827; white-space: nowrap; }
        .dsr-chip-dept { font-size: 0.71rem; color: #9ca3af; white-space: nowrap; }
        .dsr-table { width: 100%; border-collapse: collapse; }
        .dsr-table thead th { font-size: 0.67rem; font-weight: 700; color: #9ca3af; letter-spacing: 0.06em; text-transform: uppercase; padding: 0.55rem 0.85rem; border-bottom: 1px solid #e5e7eb; white-space: nowrap; background: #fff; }
        .dsr-table tbody td { font-size: 0.875rem; padding: 0.7rem 0.85rem; color: #111827; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .dsr-table tbody tr:last-child td { border-bottom: none; }
        .dsr-table tbody tr:hover td { background: #fafafa; }
        .dsr-name { font-weight: 600; }
        .dsr-dept { display: inline-block; font-size: 0.71rem; font-weight: 500; color: #6b7280; background: #f3f4f6; border-radius: 99px; padding: 0.18rem 0.55rem; white-space: nowrap; }
        .dsr-pill { display: inline-block; font-size: 0.71rem; font-weight: 600; border-radius: 99px; padding: 0.2rem 0.6rem; white-space: nowrap; }
        .dsr-pill-g { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .dsr-pill-b { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .dsr-pill-o { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .dsr-pill-r { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .dsr-pill-s { background: #f9fafb; color: #6b7280; border: 1px solid #e5e7eb; }
        .dsr-ot { font-size: 0.8rem; font-weight: 700; color: #7c3aed; }
        .dsr-empty { display: flex; flex-direction: column; align-items: center; gap: 0.3rem; padding: 2.5rem 0; }
        .dsr-empty-icon  { font-size: 2rem; }
        .dsr-empty-title { font-size: 0.9rem; font-weight: 600; color: #374151; }
        .dsr-empty-sub   { font-size: 0.8rem; color: #9ca3af; }
        .dsr-footer { margin-top: 1.5rem; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; text-align: center; font-size: 0.75rem; color: #9ca3af; }
        .dsr-date-input { border-radius: 8px !important; border: 1px solid #e5e7eb !important; font-size: 0.85rem !important; padding: 0.35rem 0.65rem !important; box-shadow: none !important; width: 155px !important; }
        .dsr-range-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; border-radius: 99px; padding: 0.2rem 0.65rem; }
        .dsr-quick-btn { font-size: 0.78rem !important; font-weight: 600 !important; border-radius: 8px !important; padding: 0.35rem 0.75rem !important; border: 1px solid #e5e7eb !important; background: #fff !important; color: #374151 !important; cursor: pointer; transition: background 0.15s; }
        .dsr-quick-btn:hover { background: #f3f4f6 !important; }
        .dsr-dept-bar-wrap { height: 6px; background: #e5e7eb; border-radius: 99px; min-width: 120px; overflow: hidden; }
        .dsr-dept-bar-fill { height: 100%; border-radius: 99px; }
        @media (max-width: 640px) {
            .dsr-bar { grid-template-columns: 1fr 1fr; }
            .dsr-bar-divider { display: none; }
            .dsr-bar-cell { border-bottom: 1px solid #e5e7eb; }
            .dsr-val { font-size: 1.6rem; }
        }
    </style>
@endpush

<div>
    <div class="card">
        <div class="card-body p-4">

            {{-- Header --}}
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h5 class="mb-0 fw-bold">Custom Date Range Report</h5>
                    <small class="text-muted">
                        {{ \Carbon\Carbon::parse($startDate)->format('M j, Y') }} – {{ \Carbon\Carbon::parse($endDate)->format('M j, Y') }}
                    </small>
                    <span class="dsr-range-badge ms-2">{{ $this->getDaysInRange() }} days</span>
                </div>
            </div>

            {{-- Date pickers + quick select --}}
            <div class="d-flex flex-wrap gap-3 align-items-end mb-4">
                <div>
                    <span class="dsr-section-lbl mb-1">Start Date</span>
                    <input type="date" class="form-control dsr-date-input" wire:model.live="startDate">
                </div>
                <div>
                    <span class="dsr-section-lbl mb-1">End Date</span>
                    <input type="date" class="form-control dsr-date-input" wire:model.live="endDate">
                </div>
                <div>
                    <span class="dsr-section-lbl mb-1">Quick Select</span>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="dsr-quick-btn" wire:click="setCurrentWeek">This Week</button>
                        <button class="dsr-quick-btn" wire:click="setLastWeek">Last Week</button>
                        <button class="dsr-quick-btn" wire:click="setCurrentMonth">This Month</button>
                        <button class="dsr-quick-btn" wire:click="setLastMonth">Last Month</button>
                    </div>
                </div>
            </div>

            {{-- Stats bar --}}
            <div class="dsr-bar">
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-dark">{{ $totalEmployees }}</span>
                    <span class="dsr-lbl">Total Employees</span>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-success">{{ $avgPresent }}</span>
                    <span class="dsr-lbl">Avg Present</span>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-danger">{{ $avgAbsent }}</span>
                    <span class="dsr-lbl">Avg Absent</span>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-warning">{{ $avgLate }}</span>
                    <span class="dsr-lbl">Avg Late</span>
                </div>
            </div>

            {{-- Trends (inline flex — override-proof) --}}
            <span class="dsr-section-lbl">Performance Trends (vs Previous Period)</span>
            <hr class="dsr-section-hr">
            <div style="display:flex; flex-wrap:wrap; gap:0.85rem; margin-bottom:1.5rem;">
                @php
                    $trends = [
                        ['icon' => '📈', 'label' => 'Attendance change vs prior period',  'value' => $attendanceTrend,  'color' => $this->getTrendColor($attendanceTrend)],
                        ['icon' => '⏰', 'label' => 'Lateness change vs prior period',    'value' => $latenessTrend,    'color' => $this->getTrendColor($latenessTrend)],
                        ['icon' => '🚫', 'label' => 'Absenteeism change vs prior period', 'value' => $absenteeismTrend, 'color' => $this->getTrendColor($absenteeismTrend)],
                    ];
                @endphp
                @foreach($trends as $t)
                    <div style="flex:1 1 calc(33.333% - 0.85rem); min-width:180px; box-sizing:border-box;">
                        <div class="dsr-hl-card">
                            <div class="dsr-hl-icon">{{ $t['icon'] }}</div>
                            <div class="dsr-hl-val text-{{ $t['color'] === 'success' ? 'success' : ($t['color'] === 'danger' ? 'danger' : 'muted') }}">
                                {{ $t['value'] }}
                            </div>
                            <div class="dsr-hl-sub">{{ $t['label'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Absent — chips ONLY, no table --}}
            @if($absentEmployeesData->count() > 0)
                <span class="dsr-section-lbl">
                    Absent in Period
                    <span class="dsr-pill dsr-pill-r ms-1">{{ $absentEmployeesData->count() }}</span>
                </span>
                <hr class="dsr-section-hr">
                <div class="d-flex flex-wrap gap-2 mb-4">
                    @foreach($absentEmployeesData as $absent)
                        <div class="dsr-chip">
                            <span class="dsr-chip-dot dsr-chip-dot-red"></span>
                            <div>
                                <div class="dsr-chip-name">{{ $absent['employee']->name ?? 'N/A' }}</div>
                                <div class="dsr-chip-dept">
                                    {{ $absent['employee']->department->name ?? 'N/A' }} · {{ $absent['total_days'] }}d
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Present employees — table --}}
            <span class="dsr-section-lbl">
                Present in Period
                <span class="dsr-pill dsr-pill-g ms-1">{{ $presentEmployeesData->total() }}</span>
            </span>
            <hr class="dsr-section-hr">
            <div class="table-responsive mb-4">
                <table class="dsr-table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Days Present</th>
                        <th>Total</th>
                        <th>Late Days</th>
                        <th>OT Hrs</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($presentEmployeesData as $present)
                        <tr>
                            <td><span class="dsr-name">{{ $present['employee']->name ?? 'N/A' }}</span></td>
                            <td><span class="dsr-dept">{{ $present['employee']->department->name ?? 'N/A' }}</span></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach(array_slice($present['days'], 0, 5) as $day)
                                        <span class="dsr-pill dsr-pill-g">{{ $day }}</span>
                                    @endforeach
                                    @if(count($present['days']) > 5)
                                        <span class="dsr-pill dsr-pill-s">+{{ count($present['days']) - 5 }} more</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="dsr-pill dsr-pill-g">{{ $present['total_days'] }}d</span></td>
                            <td>
                                @if($present['late_days'] > 0)
                                    <span class="dsr-pill dsr-pill-o">{{ $present['late_days'] }}d</span>
                                @else
                                    <span class="text-muted" style="font-size:0.78rem;">—</span>
                                @endif
                            </td>
                            <td>
                                @if(($present['ot_hours'] ?? 0) > 0)
                                    <span class="dsr-ot">+{{ number_format($present['ot_hours'], 2) }}h</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="dsr-empty">
                                    <span class="dsr-empty-icon">📋</span>
                                    <div class="dsr-empty-title">No attendance recorded in this period</div>
                                    <div class="dsr-empty-sub">Try selecting a different date range</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($presentEmployeesData->hasPages())
                <div class="d-flex justify-content-center mb-4">{{ $presentEmployeesData->links() }}</div>
            @endif

            {{-- Leave — chips + table --}}
            <span class="dsr-section-lbl">
                Employees on Leave
                <span class="dsr-pill dsr-pill-o ms-1">{{ $employeesOnLeaveData->total() }}</span>
            </span>
            <hr class="dsr-section-hr">
            @if($employeesOnLeaveData->total() > 0)
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @foreach($employeesOnLeaveData as $leave)
                        <div class="dsr-chip">
                            <span class="dsr-chip-dot dsr-chip-dot-yellow"></span>
                            <div>
                                <div class="dsr-chip-name">{{ $leave['employee']->name ?? 'N/A' }}</div>
                                <div class="dsr-chip-dept">
                                    {{ ucfirst(str_replace('_', ' ', $leave['leave_type'])) }} · {{ $leave['total_days'] }}d
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="table-responsive mb-4">
                <table class="dsr-table">
                    <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Days on Leave</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($employeesOnLeaveData as $leave)
                        <tr>
                            <td><span class="dsr-name">{{ $leave['employee']->name ?? 'N/A' }}</span></td>
                            <td><span class="dsr-dept">{{ $leave['employee']->department->name ?? 'N/A' }}</span></td>
                            <td><span class="dsr-pill dsr-pill-b">{{ ucfirst(str_replace('_', ' ', $leave['leave_type'])) }}</span></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach(array_slice($leave['days'], 0, 5) as $day)
                                        <span class="dsr-pill dsr-pill-o">{{ $day }}</span>
                                    @endforeach
                                    @if(count($leave['days']) > 5)
                                        <span class="dsr-pill dsr-pill-s">+{{ count($leave['days']) - 5 }} more</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="dsr-pill dsr-pill-o">{{ $leave['total_days'] }}d</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="dsr-empty">
                                    <span class="dsr-empty-icon">🏖️</span>
                                    <div class="dsr-empty-title">No employees on leave</div>
                                    <div class="dsr-empty-sub">No approved leave in this period.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($employeesOnLeaveData->hasPages())
                <div class="d-flex justify-content-center mb-4">{{ $employeesOnLeaveData->links() }}</div>
            @endif

            {{-- Department Breakdown --}}
            <span class="dsr-section-lbl">Department Breakdown</span>
            <hr class="dsr-section-hr">
            <div class="table-responsive">
                <table class="dsr-table">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Total</th>
                        <th>Avg Present</th>
                        <th>Attendance Rate</th>
                        <th>Visual</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($departmentBreakdownData as $dept)
                        @php
                            $color     = $this->getProgressBarColor($dept['attendance_rate']);
                            $pillClass = $color === 'success' ? 'dsr-pill-g' : ($color === 'warning' ? 'dsr-pill-o' : 'dsr-pill-r');
                            $barColor  = $color === 'success' ? '#16a34a' : ($color === 'warning' ? '#d97706' : '#dc2626');
                        @endphp
                        <tr>
                            <td><span class="dsr-name">{{ $dept['department'] }}</span></td>
                            <td>{{ $dept['total'] }}</td>
                            <td>{{ $dept['avg_present'] }}</td>
                            <td><span class="dsr-pill {{ $pillClass }}">{{ number_format($dept['attendance_rate'], 1) }}%</span></td>
                            <td>
                                <div class="dsr-dept-bar-wrap">
                                    <div class="dsr-dept-bar-fill" style="width:{{ $dept['visual'] }}%; background:{{ $barColor }};"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="dsr-empty">
                                    <span class="dsr-empty-icon">🏢</span>
                                    <div class="dsr-empty-title">No department data</div>
                                    <div class="dsr-empty-sub">No data available for this period.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($departmentBreakdownData->hasPages())
                <div class="d-flex justify-content-center mt-3">{{ $departmentBreakdownData->links() }}</div>
            @endif

            <div class="dsr-footer">
                Automatically generated from your Time & Attendance System ·
                {{ now()->format('l, F j, Y \a\t g:i A') }}
            </div>

        </div>
    </div>
</div>
