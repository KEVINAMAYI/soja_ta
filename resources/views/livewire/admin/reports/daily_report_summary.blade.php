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
    public $activeTab = 'daily';

    // Stats
    public $totalEmployees = 0;
    public $presentCount = 0;
    public $absentCount = 0;
    public $lateArrivals = 0;

    // Pagination settings
    public $perPageLate = 10;
    public $perPageAbsent = 10;
    public $perPageLeave = 10;
    public $perPageDept = 10;

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->reportDate = now()->toDateString();
    }

    public function updatedReportDate()
    {
        $this->resetPage();
    }

    public function with()
    {
        $date = Carbon::parse($this->reportDate);
        $orgId = auth()->user()->employee->organization_id ?? null;

        // Get total employees count for the organization
        $this->totalEmployees = Employee::where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        // Get attendance records for the selected date for the organization
        $attendances = Attendance::with(['employee.department'])
            ->whereDate('date', $date)
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->get();

        // Calculate stats
        $this->presentCount = $attendances->whereIn('status', ['clocked_in', 'clocked_out'])->count();
        $this->absentCount = $attendances->whereIn('status', ['absent','unchecked_in'])->count();
        $this->lateArrivals = $attendances->where('is_late_checkin', 1)->count();

        // Late Arrivals Data with Pagination
        $lateArrivalsQuery = Attendance::with(['employee.department'])
            ->whereDate('date', $date)
            ->where('is_late_checkin', 1)
            ->whereNotNull('check_in_time')
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            })
            ->orderByDesc('minutes_late');

        $lateArrivalsData = $lateArrivalsQuery->paginate($this->perPageLate, ['*'], 'latePage');

        // Absent Employees Data with Pagination
        $absentEmployeesQuery = Attendance::with(['employee.department'])
            ->whereDate('date', $date)
            ->whereIn('status', ['absent','unchecked_in'])
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            });

        $absentEmployeesData = $absentEmployeesQuery->paginate($this->perPageAbsent, ['*'], 'absentPage');

        // Employees on Leave Data with Pagination
        $employeesOnLeaveQuery = Leave::with(['employee.department'])
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->when($orgId, function($q) use ($orgId) {
                $q->whereHas('employee', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            });

        $employeesOnLeaveData = $employeesOnLeaveQuery->paginate($this->perPageLeave, ['*'], 'leavePage');

        // Department Breakdown Data with Pagination
        $departments = Employee::with(['department'])
            ->where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get()
            ->groupBy('department_id');

        $departmentBreakdownData = collect();

        foreach ($departments as $deptId => $employees) {
            $department = $employees->first()->department;
            $total = $employees->count();

            $present = Attendance::whereIn('employee_id', $employees->pluck('id'))
                ->whereDate('date', $date)
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->count();

            $attendanceRate = $total > 0 ? round(($present / $total) * 100, 2) : 0;

            $departmentBreakdownData->push([
                'department' => $department->name ?? 'Unassigned',
                'total' => $total,
                'present' => $present,
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
            'lateArrivalsData' => $lateArrivalsData,
            'absentEmployeesData' => $absentEmployeesData,
            'employeesOnLeaveData' => $employeesOnLeaveData,
            'departmentBreakdownData' => $departmentBreakdownPaginated,
        ];
    }

    private function formatMinutes($minutes)
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $mins = $minutes % 60;
            return $mins > 0 ? "{$hours}h {$mins}min" : "{$hours}h";
        }

        return "{$minutes} min";
    }

    public function getProgressBarColor($rate)
    {
        if ($rate >= 90) return 'success';
        if ($rate >= 75) return 'warning';
        return 'danger';
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
                            <i class="ti ti-clock-hour-4 me-2"></i>
                            Daily Summary Report
                        </h4>
                        <small class="text-muted">
                            <i class="ti ti-calendar me-1"></i>
                            {{ \Carbon\Carbon::parse($reportDate)->format('l, F j, Y') }}
                        </small>
                    </div>
                </div>

                <!-- Date Picker -->
                <div class="mb-4">
                    <input type="date"
                           class="form-control"
                           style="max-width: 200px;"
                           wire:model.live="reportDate">
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
                                        <small class="text-muted d-block">Present</small>
                                        <h3 class="mb-0">{{ $presentCount }}</h3>
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
                                        <small class="text-muted d-block">Absent</small>
                                        <h3 class="mb-0">{{ $absentCount }}</h3>
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
                                        <small class="text-muted d-block">Late Arrivals</small>
                                        <h3 class="mb-0">{{ $lateArrivals }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Late Arrivals Table -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="ti ti-clock-pause text-warning me-2"></i>
                            Late Arrivals
                        </h5>
                        <span class="badge bg-warning">{{ count($lateArrivalsData) }} Employees</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Scheduled</th>
                                <th>Actual</th>
                                <th>Delay</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($lateArrivalsData as $attendance)
                                <tr>
                                    <td>{{ $attendance->employee->name ?? 'N/A' }}</td>
                                    <td>{{ $attendance->employee->department->name ?? 'N/A' }}</td>
                                    <td>{{ $attendance->expected_check_in_time ? \Carbon\Carbon::parse($attendance->expected_check_in_time)->format('H:i') : 'N/A' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($attendance->check_in_time)->format('H:i') }}</td>
                                    <td>
                                        <span class="badge bg-warning">{{ $this->formatMinutes($attendance->minutes_late) }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="ti ti-check-circle fs-3 d-block mb-2 text-success"></i>
                                        No late arrivals today!
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

                    @if($lateArrivalsData->hasPages())
                        <div class="d-flex justify-content-center mt-3">
                            {{ $lateArrivalsData->links() }}
                        </div>
                    @endif
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
                                <th>Last Seen</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($absentEmployeesData as $attendance)
                                @php
                                    $lastAttendance = \App\Models\Attendance::where('employee_id', $attendance->employee_id)
                                        ->whereDate('date', '<', $attendance->date)
                                        ->whereIn('status', ['clocked_in', 'clocked_out'])
                                        ->orderBy('date', 'desc')
                                        ->first();
                                @endphp
                                <tr>
                                    <td>{{ $attendance->employee->name ?? 'N/A' }}</td>
                                    <td>{{ $attendance->employee->department->name ?? 'N/A' }}</td>
                                    <td>
                                        <small class="text-muted">{{ $lastAttendance ? \Carbon\Carbon::parse($lastAttendance->date)->format('Y-m-d') : 'N/A' }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger">Not Approved</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="ti ti-check-circle fs-3 d-block mb-2 text-success"></i>
                                        Perfect attendance! No absences today.
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
                                <th>Duration</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($employeesOnLeaveData as $leave)
                                <tr>
                                    <td>{{ $leave->employee->name ?? 'N/A' }}</td>
                                    <td>{{ $leave->employee->department->name ?? 'N/A' }}</td>
                                    <td>
                                        <span class="badge bg-info">{{ ucfirst(str_replace('_', ' ', $leave->leave_type)) }}</span>
                                    </td>
                                    <td>
                                        <small>{{ \Carbon\Carbon::parse($leave->start_date)->format('M d') }} - {{ \Carbon\Carbon::parse($leave->end_date)->format('M d') }}</small>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="ti ti-user-check fs-3 d-block mb-2"></i>
                                        No employees on leave today.
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
                                <th>Present</th>
                                <th>Attendance Rate</th>
                                <th>Visual</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($departmentBreakdownData as $dept)
                                <tr>
                                    <td>{{ $dept['department'] }}</td>
                                    <td>{{ $dept['total'] }}</td>
                                    <td>{{ $dept['present'] }}</td>
                                    <td>
                                            <span
                                                class="badge bg-{{ $this->getProgressBarColor($dept['attendance_rate']) }}">
                                                {{ $dept['attendance_rate'] }}%
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

        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
    </style>
@endpush
