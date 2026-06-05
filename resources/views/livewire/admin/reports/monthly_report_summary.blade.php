<?php

use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;

new class extends Component {
    use WithPagination;

    public $reportDate;
    public $monthName;
    public $year;

    // Stats
    public $totalEmployees = 0;
    public $avgPresent = 0;
    public $avgAbsent = 0;
    public $avgOnLeave = 0;
    public $avgLate = 0;

    // Trends
    public $attendanceTrend = '';
    public $latenessTrend = '';
    public $absenteeismTrend = '';

    // Pagination settings
    public $perPageAbsent = 10;
    public $perPageLeave = 10;
    public $perPageDept = 10;
    public $entityLabel = 'Employee';

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->entityLabel = auth()->user()->employee?->organization?->is_student_record ? "Student" : "Employee";
        $this->reportDate = now()->toDateString();
        $this->calculateMonthInfo();
    }

    public function updatedReportDate()
    {
        $this->calculateMonthInfo();
        $this->resetPage();
    }

    private function calculateMonthInfo()
    {
        $date = Carbon::parse($this->reportDate);
        $this->monthName = $date->format('F');
        $this->year = $date->format('Y');
    }

    public function with()
    {
        $date = Carbon::parse($this->reportDate);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();
        $orgId = auth()->user()->employee->organization_id ?? null;

        // Get total employees
        $this->totalEmployees = Employee::where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        // Get all attendance records for the month
        $monthAttendances = Attendance::with(['employee.department'])
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('employee', function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        // Calculate working days in month
        $workingDays = $startOfMonth->diffInDaysFiltered(function (Carbon $date) use ($endOfMonth) {
            return $date->isWeekday() && $date->lte($endOfMonth);
        }, $endOfMonth);

        // Calculate averages
        $totalPresent = $monthAttendances->whereIn('status', ['clocked_in', 'clocked_out'])->count();
        $totalAbsent = $monthAttendances->whereIn('status', ['absent', 'unchecked_in'])->count();
        $totalLate = $monthAttendances->where('is_late_checkin', 1)->count();

        $this->avgPresent = $workingDays > 0 ? round($totalPresent / $workingDays) : 0;
        $this->avgAbsent = $workingDays > 0 ? round($totalAbsent / $workingDays) : 0;
        $this->avgLate = $workingDays > 0 ? round($totalLate / $workingDays) : 0;

        // Calculate average on leave
        $monthLeaves = Leave::where('status', 'approved')
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($query) use ($startOfMonth, $endOfMonth) {
                        $query->where('start_date', '<=', $startOfMonth)
                            ->where('end_date', '>=', $endOfMonth);
                    });
            })
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('employee', function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        $totalLeaveDays = $monthLeaves->sum(function ($leave) use ($startOfMonth, $endOfMonth) {
            $leaveStart = Carbon::parse($leave->start_date)->max($startOfMonth);
            $leaveEnd = Carbon::parse($leave->end_date)->min($endOfMonth);
            return $leaveStart->diffInDays($leaveEnd) + 1;
        });

        $this->avgOnLeave = $workingDays > 0 ? round($totalLeaveDays / $workingDays) : 0;

        // Calculate trends (compare with previous month)
        $prevMonthStart = $startOfMonth->copy()->subMonth()->startOfMonth();
        $prevMonthEnd = $startOfMonth->copy()->subMonth()->endOfMonth();

        $prevMonthAttendances = Attendance::whereBetween('date', [$prevMonthStart, $prevMonthEnd])
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('employee', function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        $prevWorkingDays = $prevMonthStart->diffInDaysFiltered(function (Carbon $date) use ($prevMonthEnd) {
            return $date->isWeekday() && $date->lte($prevMonthEnd);
        }, $prevMonthEnd);

        if ($prevWorkingDays > 0 && $workingDays > 0) {
            $prevAvgPresent = $prevMonthAttendances->whereIn('status', ['clocked_in', 'clocked_out'])->count() / $prevWorkingDays;
            $prevAvgLate = $prevMonthAttendances->where('is_late_checkin', 1)->count() / $prevWorkingDays;
            $prevAvgAbsent = $prevMonthAttendances->whereIn('status', ['absent', 'unchecked_in'])->count() / $prevWorkingDays;

            $currentAvgPresent = $totalPresent / $workingDays;
            $currentAvgLate = $totalLate / $workingDays;
            $currentAvgAbsent = $totalAbsent / $workingDays;

            $attendanceDiff = $prevAvgPresent > 0 ? (($currentAvgPresent - $prevAvgPresent) / $prevAvgPresent) * 100 : 0;
            $latenessDiff = $prevAvgLate > 0 ? (($currentAvgLate - $prevAvgLate) / $prevAvgLate) * 100 : 0;
            $absenteeismDiff = $prevAvgAbsent > 0 ? (($currentAvgAbsent - $prevAvgAbsent) / $prevAvgAbsent) * 100 : 0;

            $this->attendanceTrend = ($attendanceDiff >= 0 ? '+' : '') . number_format($attendanceDiff, 1) . '%';
            $this->latenessTrend = ($latenessDiff >= 0 ? '+' : '') . number_format($latenessDiff, 1) . '%';
            $this->absenteeismTrend = ($absenteeismDiff >= 0 ? '+' : '') . number_format($absenteeismDiff, 1) . '%';
        } else {
            $this->attendanceTrend = 'N/A';
            $this->latenessTrend = 'N/A';
            $this->absenteeismTrend = 'N/A';
        }

        // Absent Employees Summary (employees with most absences)
        $absentEmployeesQuery = Attendance::with(['employee.department'])
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('employee', function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get()
            ->groupBy('employee_id')
            ->map(function ($absences) {
                $employee = $absences->first()->employee;
                return [
                    'name' => $employee->name ?? 'N/A',
                    'department' => $employee->department->name ?? 'N/A',
                    'total_days' => $absences->count()
                ];
            })
            ->sortByDesc('total_days')
            ->values();

        // Paginate absent employees
        $absentPage = request()->get('absentPage', 1);
        $absentOffset = ($absentPage - 1) * $this->perPageAbsent;
        $absentEmployeesData = new \Illuminate\Pagination\LengthAwarePaginator(
            $absentEmployeesQuery->slice($absentOffset, $this->perPageAbsent)->values(),
            $absentEmployeesQuery->count(),
            $this->perPageAbsent,
            $absentPage,
            ['path' => request()->url(), 'pageName' => 'absentPage']
        );

        // Employees on Leave Summary
        $employeesOnLeaveQuery = Leave::with(['employee.department'])
            ->where('status', 'approved')
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($query) use ($startOfMonth, $endOfMonth) {
                        $query->where('start_date', '<=', $startOfMonth)
                            ->where('end_date', '>=', $endOfMonth);
                    });
            })
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('employee', function ($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get()
            ->map(function ($leave) use ($startOfMonth, $endOfMonth) {
                $leaveStart = Carbon::parse($leave->start_date)->max($startOfMonth);
                $leaveEnd = Carbon::parse($leave->end_date)->min($endOfMonth);
                $totalDays = $leaveStart->diffInDays($leaveEnd) + 1;

                return [
                    'name' => $leave->employee->name ?? 'N/A',
                    'department' => $leave->employee->department->name ?? 'N/A',
                    'leave_type' => ucfirst(str_replace('_', ' ', $leave->leave_type)),
                    'total_days' => $totalDays
                ];
            });

        // Paginate leave data
        $leavePage = request()->get('leavePage', 1);
        $leaveOffset = ($leavePage - 1) * $this->perPageLeave;
        $employeesOnLeaveData = new \Illuminate\Pagination\LengthAwarePaginator(
            $employeesOnLeaveQuery->slice($leaveOffset, $this->perPageLeave)->values(),
            $employeesOnLeaveQuery->count(),
            $this->perPageLeave,
            $leavePage,
            ['path' => request()->url(), 'pageName' => 'leavePage']
        );

        // Department Breakdown
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
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->get();

            $present = $deptAttendances->whereIn('status', ['clocked_in', 'clocked_out'])->count();
            $avgPresent = $workingDays > 0 ? round($present / $workingDays) : 0;
            $attendanceRate = ($total * $workingDays) > 0 ? round(($present / ($total * $workingDays)) * 100, 1) : 0;

            $departmentBreakdownData->push([
                'department' => $department->name ?? 'Unassigned',
                'total' => $total,
                'avg_present' => $avgPresent,
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
            'absentEmployeesData' => $absentEmployeesData,
            'employeesOnLeaveData' => $employeesOnLeaveData,
            'departmentBreakdownData' => $departmentBreakdownPaginated,
        ];
    }

    public function getProgressBarColor($rate)
    {
        if ($rate >= 90) return 'success';
        if ($rate >= 75) return 'warning';
        return 'danger';
    }

    public function getTrendColor($trend)
    {
        if ($trend === 'N/A') return 'secondary';
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
                            <i class="ti ti-calendar-month me-2"></i>
                            Monthly Summary Report
                        </h4>
                        <small class="text-muted">
                            <i class="ti ti-calendar me-1"></i>
                            {{ $monthName }} {{ $year }}
                        </small>
                    </div>
                </div>

                <!-- Month/Year Picker -->
                <div class="mb-4">
                    <label class="form-label small text-muted">Select month</label>
                    <input type="date"
                           class="form-control"
                           style="max-width: 200px;"
                           wire:model.live="reportDate">
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col">
                        <div class="card stats-card border-0 h-100">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="ti ti-users fs-5 text-primary"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Total</small>
                                        <h2 class="mb-0 fw-bold" style="font-size: 2rem;">{{ $totalEmployees }}</h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="card stats-card border-0 h-100">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="ti ti-check fs-5 text-success"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Avg
                                            Present</small>
                                        <h2 class="mb-0 fw-bold text-success"
                                            style="font-size: 2rem;">{{ $avgPresent }}</h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="card stats-card border-0 h-100">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="ti ti-x fs-5 text-danger"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Avg Absent</small>
                                        <h2 class="mb-0 fw-bold text-danger"
                                            style="font-size: 2rem;">{{ $avgAbsent }}</h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="card stats-card border-0 h-100">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="ti ti-calendar-off fs-5 text-warning"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Avg Leave</small>
                                        <h2 class="mb-0 fw-bold text-warning"
                                            style="font-size: 2rem;">{{ $avgOnLeave }}</h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="card stats-card border-0 h-100">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="ti ti-clock fs-5 text-info"></i>
                                    <div class="flex-grow-1">
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">Avg Late</small>
                                        <h2 class="mb-0 fw-bold text-info" style="font-size: 2rem;">{{ $avgLate }}</h2>
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
                            Performance Trends (vs Previous Month)
                        </h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Attendance Rate</span>
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

                <!-- Absent Employees Summary -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-user-x text-danger me-2"></i>
                            Absent Employees Summary
                        </h5>
                        <span class="badge bg-danger">{{ $absentEmployeesData->total() }} Employees</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>{{ $entityLabel }}</th>
                                <th>Department</th>
                                <th>Total Days Absent</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($absentEmployeesData as $absent)
                                <tr>
                                    <td class="fw-medium">{{ $absent['name'] }}</td>
                                    <td>{{ $absent['department'] }}</td>
                                    <td>
                                        <span class="badge bg-danger">{{ $absent['total_days'] }} days</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">
                                        <i class="ti ti-check-circle fs-3 d-block mb-2 text-success"></i>
                                        Perfect attendance! No absences this month.
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

                <!-- Employees on Leave Summary -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-calendar-off text-warning me-2"></i>
                            {{ $entityLabel }}s on Leave Summary
                        </h5>
                        <span class="badge bg-warning">{{ $employeesOnLeaveData->total() }} Employees</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>{{ $entityLabel }}</th>
                                <th>Department</th>
                                <th>Leave Type</th>
                                <th>Total Days</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($employeesOnLeaveData as $leave)
                                <tr>
                                    <td class="fw-medium">{{ $leave['name'] }}</td>
                                    <td>{{ $leave['department'] }}</td>
                                    <td>
                                        <span class="badge bg-info">{{ $leave['leave_type'] }}</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning">{{ $leave['total_days'] }} days</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="ti ti-user-check fs-3 d-block mb-2"></i>
                                        No employees on leave this month.
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
                                <th>Rate</th>
                                <th style="width: 200px;">Visual</th>
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
                                        <div class="progress" style="height: 10px;">
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
                        Report generated on: {{ now()->format('l, F j, Y \a\t g:i A') }}
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
    <style>
        .stats-card {
            background-color: #ffffff !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08) !important;
            transition: all 0.2s ease;
        }

        .stats-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12) !important;
        }

        .stats-card .card-body {
            min-height: auto;
        }

        .bg-light {
            background-color: rgba(0, 0, 0, 0.03) !important;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .badge {
            font-weight: 500;
        }

        .row.g-3 {
            display: flex;
            flex-wrap: nowrap;
        }

        @media (max-width: 1200px) {
            .row.g-3 {
                flex-wrap: wrap;
            }
        }
    </style>
@endpush
