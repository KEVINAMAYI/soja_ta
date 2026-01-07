
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

    // Stats
    public $totalEmployees = 0;
    public $avgPresent = 0;
    public $avgAbsent = 0;
    public $avgLate = 0;

    // Trends
    public $attendanceTrend = '';
    public $latenessTrend = '';
    public $absenteeismTrend = '';

    // Pagination settings
    public $perPageAbsent = 10;
    public $perPageLeave = 10;
    public $perPageDept = 10;

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        // Default to current week
        $this->startDate = now()->startOfWeek()->toDateString();
        $this->endDate = now()->endOfWeek()->toDateString();
    }

    public function updatedStartDate()
    {
        $this->validateDateRange();
        $this->resetPage();
    }

    public function updatedEndDate()
    {
        $this->validateDateRange();
        $this->resetPage();
    }

    private function validateDateRange()
    {
        if ($this->startDate && $this->endDate) {
            $start = Carbon::parse($this->startDate);
            $end = Carbon::parse($this->endDate);

            // Ensure start date is not after end date
            if ($start->gt($end)) {
                $this->endDate = $this->startDate;
            }
        }
    }

    public function setCurrentWeek()
    {
        $this->startDate = now()->startOfWeek()->toDateString();
        $this->endDate = now()->endOfWeek()->toDateString();
        $this->resetPage();
    }

    public function setLastWeek()
    {
        $this->startDate = now()->subWeek()->startOfWeek()->toDateString();
        $this->endDate = now()->subWeek()->endOfWeek()->toDateString();
        $this->resetPage();
    }

    public function setCurrentMonth()
    {
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function setLastMonth()
    {
        $this->startDate = now()->subMonth()->startOfMonth()->toDateString();
        $this->endDate = now()->subMonth()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function getDaysInRange()
    {
        if (!$this->startDate || !$this->endDate) {
            return 0;
        }

        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);
        return $start->diffInDays($end) + 1;
    }

    public function with()
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $rangeStart = Carbon::parse($this->startDate);
        $rangeEnd = Carbon::parse($this->endDate);

        // Get total employees count for the organization
        $this->totalEmployees = Employee::where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        // Get all attendance records for the date range
        $rangeAttendances = Attendance::with(['employee.department'])
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        // Calculate working days in range (weekdays only)
        $workingDays = $rangeStart->diffInDaysFiltered(function (Carbon $date) use ($rangeEnd) {
                return $date->isWeekday() && $date->lte($rangeEnd);
            }, $rangeEnd) + 1;

        // Calculate daily averages
        $dailyPresent = $rangeAttendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->groupBy('date')
            ->map(fn($day) => $day->count());

        $dailyAbsent = $rangeAttendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('date')
            ->map(fn($day) => $day->count());

        $dailyLate = $rangeAttendances
            ->where('is_late_checkin', 1)
            ->groupBy('date')
            ->map(fn($day) => $day->count());

        $this->avgPresent = $dailyPresent->count() > 0 ? round($dailyPresent->avg()) : 0;
        $this->avgAbsent = $dailyAbsent->count() > 0 ? round($dailyAbsent->avg()) : 0;
        $this->avgLate = $dailyLate->count() > 0 ? round($dailyLate->avg()) : 0;

        // Calculate trends (compare with previous period of same length)
        $daysInRange = $this->getDaysInRange();
        $prevRangeStart = $rangeStart->copy()->subDays($daysInRange);
        $prevRangeEnd = $rangeEnd->copy()->subDays($daysInRange);

        $prevRangeAttendances = Attendance::whereBetween('date', [$prevRangeStart, $prevRangeEnd])
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        $prevAvgPresent = $prevRangeAttendances->whereIn('status', ['clocked_in', 'clocked_out'])
            ->groupBy('date')
            ->map(fn($day) => $day->count())
            ->avg() ?: 0;

        $prevAvgLate = $prevRangeAttendances->where('is_late_checkin', 1)
            ->groupBy('date')
            ->map(fn($day) => $day->count())
            ->avg() ?: 0;

        $prevAvgAbsent = $prevRangeAttendances->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('date')
            ->map(fn($day) => $day->count())
            ->avg() ?: 0;

        // Calculate percentage changes
        $this->attendanceTrend = $this->calculateTrend($this->avgPresent, $prevAvgPresent);
        $this->latenessTrend = $this->calculateTrend($prevAvgLate, $this->avgLate); // Inverted: lower is better
        $this->absenteeismTrend = $this->calculateTrend($prevAvgAbsent, $this->avgAbsent); // Inverted: lower is better

        // Absent Employees Data - Group by employee and show which days they were absent
        $absentByEmployee = $rangeAttendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->groupBy('employee_id');

        $absentEmployeesData = collect();
        foreach ($absentByEmployee as $employeeId => $absences) {
            $employee = $absences->first()->employee;
            $days = $absences->map(function($attendance) {
                return Carbon::parse($attendance->date)->format('D, M j');
            })->toArray();

            $absentEmployeesData->push([
                'employee' => $employee,
                'days' => $days,
                'total_days' => count($days)
            ]);
        }

        // Sort by total days (most absences first)
        $absentEmployeesData = $absentEmployeesData->sortByDesc('total_days');

        // Paginate absent employees
        $absentPage = request()->get('absentPage', 1);
        $absentOffset = ($absentPage - 1) * $this->perPageAbsent;
        $absentEmployeesPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $absentEmployeesData->slice($absentOffset, $this->perPageAbsent)->values(),
            $absentEmployeesData->count(),
            $this->perPageAbsent,
            $absentPage,
            ['path' => request()->url(), 'pageName' => 'absentPage']
        );

        // Employees on Leave Data - Group by employee
        $leavesInRange = Leave::with(['employee.department'])
            ->where('status', 'approved')
            ->where(function($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart, $rangeEnd])
                    ->orWhereBetween('end_date', [$rangeStart, $rangeEnd])
                    ->orWhere(function($query) use ($rangeStart, $rangeEnd) {
                        $query->where('start_date', '<=', $rangeStart)
                            ->where('end_date', '>=', $rangeEnd);
                    });
            })
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        $employeesOnLeaveData = collect();
        foreach ($leavesInRange as $leave) {
            $leaveStart = Carbon::parse($leave->start_date)->max($rangeStart);
            $leaveEnd = Carbon::parse($leave->end_date)->min($rangeEnd);

            $days = [];
            for ($date = $leaveStart->copy(); $date->lte($leaveEnd); $date->addDay()) {
                if ($date->isWeekday()) {
                    $days[] = $date->format('D, M j');
                }
            }

            if (!empty($days)) {
                $employeesOnLeaveData->push([
                    'employee' => $leave->employee,
                    'leave_type' => $leave->leave_type,
                    'days' => $days,
                    'total_days' => count($days)
                ]);
            }
        }

        // Sort by total days
        $employeesOnLeaveData = $employeesOnLeaveData->sortByDesc('total_days');

        // Paginate leave data
        $leavePage = request()->get('leavePage', 1);
        $leaveOffset = ($leavePage - 1) * $this->perPageLeave;
        $employeesOnLeavePaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $employeesOnLeaveData->slice($leaveOffset, $this->perPageLeave)->values(),
            $employeesOnLeaveData->count(),
            $this->perPageLeave,
            $leavePage,
            ['path' => request()->url(), 'pageName' => 'leavePage']
        );

        // Department Breakdown - Period averages
        $departments = Employee::with(['department'])
            ->where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get()
            ->groupBy('department_id');

        $departmentBreakdownData = collect();
        foreach ($departments as $deptId => $employees) {
            $department = $employees->first()->department;
            $total = $employees->count();

            $deptAttendances = Attendance::whereIn('employee_id', $employees->pluck('id'))
                ->whereBetween('date', [$rangeStart, $rangeEnd])
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->get();

            $avgPresent = $deptAttendances->groupBy('date')
                ->map(fn($day) => $day->count())
                ->avg() ?: 0;

            $attendanceRate = $total > 0 ? round(($avgPresent / $total) * 100, 2) : 0;

            $departmentBreakdownData->push([
                'department' => $department->name ?? 'Unassigned',
                'total' => $total,
                'avg_present' => round($avgPresent, 1),
                'attendance_rate' => $attendanceRate,
                'visual' => $attendanceRate
            ]);
        }

        // Sort and paginate department data
        $departmentBreakdownData = $departmentBreakdownData->sortByDesc('total');
        $deptPage = request()->get('deptPage', 1);
        $deptOffset = ($deptPage - 1) * $this->perPageDept;
        $departmentBreakdownPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $departmentBreakdownData->slice($deptOffset, $this->perPageDept)->values(),
            $departmentBreakdownData->count(),
            $this->perPageDept,
            $deptPage,
            ['path' => request()->url(), 'pageName' => 'deptPage']
        );

        return [
            'absentEmployeesData' => $absentEmployeesPaginated,
            'employeesOnLeaveData' => $employeesOnLeavePaginated,
            'departmentBreakdownData' => $departmentBreakdownPaginated,
        ];
    }

    private function calculateTrend($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $change = (($current - $previous) / $previous) * 100;
        $sign = $change >= 0 ? '+' : '';
        return $sign . number_format($change, 1) . '%';
    }

    public function getProgressBarColor($rate)
    {
        if ($rate >= 90) return 'success';
        if ($rate >= 75) return 'warning';
        return 'danger';
    }

    public function getTrendColor($trend)
    {
        if (str_starts_with($trend, '+')) {
            return 'success';
        } elseif (str_starts_with($trend, '-')) {
            return 'danger';
        }
        return 'secondary';
    }
}; ?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="card-title mb-1">
                            <i class="ti ti-calendar-stats me-2"></i>
                            Custom Date Range Report
                        </h4>
                        <small class="text-muted">
                            <i class="ti ti-calendar me-1"></i>
                            {{ \Carbon\Carbon::parse($startDate)->format('M j, Y') }}
                            - {{ \Carbon\Carbon::parse($endDate)->format('M j, Y') }}
                            <span class="badge bg-light text-dark ms-2">{{ $this->getDaysInRange() }} days</span>
                        </small>
                    </div>
                </div>

                <!-- Date Range Picker -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Start Date</label>
                                <input type="date"
                                       class="form-control"
                                       wire:model.live="startDate">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">End Date</label>
                                <input type="date"
                                       class="form-control"
                                       wire:model.live="endDate">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Quick Select</label>
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="setCurrentWeek">
                                <i class="ti ti-calendar-week me-1"></i>This Week
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="setLastWeek">
                                Last Week
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="setCurrentMonth">
                                This Month
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="setLastMonth">
                                Last Month
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-light-primary border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="ti ti-users fs-1 text-primary"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <small class="text-muted d-block">Total Employees</small>
                                        <h3 class="mb-0">{{ $totalEmployees }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light-success border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="ti ti-check fs-1 text-success"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <small class="text-muted d-block">Avg Present</small>
                                        <h3 class="mb-0">{{ $avgPresent }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light-danger border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="ti ti-x fs-1 text-danger"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <small class="text-muted d-block">Avg Absent</small>
                                        <h3 class="mb-0">{{ $avgAbsent }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card bg-light-warning border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="ti ti-clock-exclamation fs-1 text-warning"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <small class="text-muted d-block">Avg Late</small>
                                        <h3 class="mb-0">{{ $avgLate }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Trends -->
                <div class="card bg-light mb-4">
                    <div class="card-body">
                        <h5 class="mb-3">
                            <i class="ti ti-trending-up text-primary me-2"></i>
                            Performance Trends (vs Previous Period)
                        </h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Attendance Change</span>
                                    <span class="badge bg-{{ $this->getTrendColor($attendanceTrend) }} fs-6">
                                        {{ $attendanceTrend }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Lateness Change</span>
                                    <span class="badge bg-{{ $this->getTrendColor($latenessTrend) }} fs-6">
                                        {{ $latenessTrend }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Absenteeism Change</span>
                                    <span class="badge bg-{{ $this->getTrendColor($absenteeismTrend) }} fs-6">
                                        {{ $absenteeismTrend }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Absent Employees Table -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-user-x text-danger me-2"></i>
                            Absent Employees
                        </h5>
                        <span class="badge bg-danger">{{ $absentEmployeesData->total() }} Employees</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Days Absent</th>
                                <th>Total Days</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($absentEmployeesData as $absent)
                                <tr>
                                    <td class="fw-medium">{{ $absent['employee']->name ?? 'N/A' }}</td>
                                    <td>{{ $absent['employee']->department->name ?? 'N/A' }}</td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach(array_slice($absent['days'], 0, 5) as $day)
                                                <span class="badge bg-danger-subtle text-danger">{{ $day }}</span>
                                            @endforeach
                                            @if(count($absent['days']) > 5)
                                                <span class="badge bg-secondary">+{{ count($absent['days']) - 5 }} more</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger">{{ $absent['total_days'] }} {{ $absent['total_days'] === 1 ? 'day' : 'days' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="ti ti-check-circle fs-3 d-block mb-2 text-success"></i>
                                        Perfect attendance! No absences in this period.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($absentEmployeesData->hasPages())
                        <div class="d-flex justify-content-center mt-3">
                            {{ $absentEmployeesData->links() }}
                        </div>
                    @endif
                </div>

                <!-- Employees on Leave Table -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-calendar-off text-info me-2"></i>
                            Employees on Leave
                        </h5>
                        <span class="badge bg-info">{{ $employeesOnLeaveData->total() }} Employees</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Leave Type</th>
                                <th>Days on Leave</th>
                                <th>Total Days</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($employeesOnLeaveData as $leave)
                                <tr>
                                    <td class="fw-medium">{{ $leave['employee']->name ?? 'N/A' }}</td>
                                    <td>{{ $leave['employee']->department->name ?? 'N/A' }}</td>
                                    <td>
                                        <span class="badge bg-info">{{ ucfirst(str_replace('_', ' ', $leave['leave_type'])) }}</span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach(array_slice($leave['days'], 0, 5) as $day)
                                                <span class="badge bg-warning-subtle text-warning">{{ $day }}</span>
                                            @endforeach
                                            @if(count($leave['days']) > 5)
                                                <span class="badge bg-secondary">+{{ count($leave['days']) - 5 }} more</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning">{{ $leave['total_days'] }} {{ $leave['total_days'] === 1 ? 'day' : 'days' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="ti ti-user-check fs-3 d-block mb-2"></i>
                                        No employees on leave in this period.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($employeesOnLeaveData->hasPages())
                        <div class="d-flex justify-content-center mt-3">
                            {{ $employeesOnLeaveData->links() }}
                        </div>
                    @endif
                </div>

                <!-- Department Breakdown -->
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-building text-primary me-2"></i>
                            Department Breakdown
                        </h5>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
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
                                <tr>
                                    <td class="fw-medium">{{ $dept['department'] }}</td>
                                    <td>{{ $dept['total'] }}</td>
                                    <td>{{ $dept['avg_present'] }}</td>
                                    <td>
                                            <span
                                                class="badge bg-{{ $this->getProgressBarColor($dept['attendance_rate']) }}">
                                                {{ number_format($dept['attendance_rate'], 1) }}%
                                            </span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 8px; min-width: 150px;">
                                            <div
                                                class="progress-bar bg-{{ $this->getProgressBarColor($dept['attendance_rate']) }}"
                                                role="progressbar"
                                                style="width: {{ $dept['visual'] }}%"
                                                aria-valuenow="{{ $dept['visual'] }}"
                                                aria-valuemin="0"
                                                aria-valuemax="100">
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No department data available.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($departmentBreakdownData->hasPages())
                        <div class="d-flex justify-content-center mt-3">
                            {{ $departmentBreakdownData->links() }}
                        </div>
                    @endif
                </div>

                <!-- Footer Note -->
                <div class="mt-4 text-center">
                    <small class="text-muted">
                        This report was automatically generated based on your Time & Attendance System.<br>
                        Report generated on {{ now()->format('l, F j, Y \a\t g:i A') }}
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
    <style>
        .bg-light-primary {
            background-color: rgba(93, 135, 255, 0.1) !important;
        }

        .bg-light-success {
            background-color: rgba(19, 194, 150, 0.1) !important;
        }

        .bg-light-danger {
            background-color: rgba(250, 92, 124, 0.1) !important;
        }

        .bg-light-warning {
            background-color: rgba(255, 193, 7, 0.1) !important;
        }

        .bg-light {
            background-color: rgba(0, 0, 0, 0.03) !important;
        }

        .bg-danger-subtle {
            background-color: rgba(250, 92, 124, 0.1) !important;
        }

        .bg-warning-subtle {
            background-color: rgba(255, 193, 7, 0.1) !important;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .badge {
            font-weight: 500;
        }

        .btn-group .btn {
            font-size: 0.875rem;
        }
    </style>
@endpush
