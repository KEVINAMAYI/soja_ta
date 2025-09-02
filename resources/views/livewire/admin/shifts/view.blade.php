<?php

use App\Models\Attendance;
use App\Models\Shift;
use Carbon\Carbon;
use Livewire\Volt\Component;

new class extends Component {

    public $shift;
    public $totalEmployees = 0;
    public $countClockedIn = 0;
    public $countClockedOut = 0;
    public $countAbsent = 0;
    public $countUncheckedIn = 0;
    public $attendanceRate = 0;
    public $status = 'clocked_in';
    public $employees = [];


    public function mount($shift)
    {
        $this->shift = Shift::with('employees')->findOrFail($shift);
        $this->totalEmployees = $this->shift->employees->count();

        $today = Carbon::today()->toDateString();
        $employeeIds = $this->shift->employees->pluck('id');

        $attendanceRecords = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->get()
            ->groupBy('status');

        $this->countClockedIn = $attendanceRecords->get('clocked_in', collect())->count();
        $this->countClockedOut = $attendanceRecords->get('clocked_out', collect())->count();
        $this->countAbsent = $attendanceRecords->get('absent', collect())->count();
        $this->countUncheckedIn = $attendanceRecords->get('unchecked_in', collect())->count();

        // If you want those without any record treated as unchecked_in:
        $recordedCount = $attendanceRecords->flatten()->count();
        $missing = $this->totalEmployees - $recordedCount;
        if ($missing > 0) {
            $this->countUncheckedIn += $missing;
        }

        // Calculate Present = countClockedIn
        $present = $this->countClockedIn;
        $this->attendanceRate = $this->totalEmployees
            ? round(($present / $this->totalEmployees) * 100)
            : 0;

        $this->getEmployees();
    }


    public function updateStatus($status)
    {
        $this->status = $status;
        $this->getEmployees();
    }


    public function getEmployees()
    {
        $today = now()->toDateString();

        // Get IDs of employees assigned to the shift
        $employeeIds = $this->shift->employees->pluck('id');

        // Get attendances for those employees today with the current filter status
        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today);

        if ($this->status !== 'all') {
            if ($this->status === 'absent') {
                $attendances->whereIn('status', ['absent', 'unchecked_in']);
            } else {
                $attendances->where('status', $this->status);
            }
        }

        $attendances = $attendances->get();

        // Get employees that match filtered attendances
        $filteredEmployeeIds = $attendances->pluck('employee_id')->unique();

        // Get employees with those IDs
        $employees = $this->shift->employees->whereIn('id', $filteredEmployeeIds);

        // 🔥 FIX: Attach today's attendance to each employee
        foreach ($employees as $employee) {
            $employee->todayAttendance = $attendances->firstWhere('employee_id', $employee->id);
        }

        $this->employees = $employees;
    }


}; ?>


@push('styles')
    <style>
        .avatar-initial {
            width: 48px;
            height: 48px;
            font-size: 18px;
            font-weight: bold;
            border-radius: 50%;
            color: #fff;
            background: linear-gradient(to right, #6a11cb, #2575fc);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .status-badge {
            font-size: 13px;
            font-weight: 500;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
        }

        .badge-active {
            background-color: #d1fadd;
            color: #16a34a;
        }

        .badge-break {
            background-color: #ffe7cc;
            color: #ea580c;
        }

        .icon-circle {
            width: 58px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            line-height: 1;
        }

        .bg-light {
            background-color: #f8f9fa !important;
        }

        .btn-outline-secondary.dropdown-toggle:hover,
        .btn-outline-secondary.dropdown-toggle.show {
            background-color: transparent !important;
            box-shadow: none !important;
            color: orange !important;
        }

    </style>
@endpush

<div>

    <div class="container my-4">
        <div class="card p-4 shadow-sm border-0">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
                <div>
                    <!-- Shift Name -->
                    <h4 class="fw-bold mb-1">{{ $shift->name }}</h4>
                    <p class="text-muted mb-4">Today, {{ now()->format('F d, Y') }}</p>

                </div>
                <button class="btn btn-outline-primary d-flex align-items-center mt-3 mt-md-0">
                    <i class="ti ti-calendar-event me-2"></i> Active Shift
                </button>
            </div>

            <!-- Shift + Attendance Info -->
            <div class="row row-cols-1 row-cols-md-2 g-5 align-items-start">
                <!-- Left Column -->
                <div>
                    <div class="bg-light rounded-3 p-3 d-flex align-items-center mb-4">
                        <i class="ti ti-clock text-primary fs-4 me-3"></i>
                        <div>
                            <small class="text-muted">Shift Hours</small>
                            <div class="fw-bold">{{ $shift->start_time->format('h:i A') }}
                                - {{ $shift->end_time->format('h:i A') }}</div>
                        </div>
                    </div>

                    <div class="bg-light rounded-3 p-3 d-flex align-items-center mb-4">
                        <i class="ti ti-coffee text-warning fs-4 me-3"></i>
                        <div>
                            <small class="text-muted">Break Allowance</small>
                            <div class="fw-bold">{{ $shift->break_minutes }} minutes</div>
                        </div>
                    </div>

                    <div class="bg-light rounded-3 p-3 d-flex align-items-center">
                        <i class="ti ti-users text-purple fs-4 me-3"></i>
                        <div>
                            <small class="text-muted">Assigned Staff</small>
                            <div class="fw-bold">{{ $shift->employees->count() }} employees</div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>

                    <!-- Right Column: Attendance Summary -->
                    <div>
                        <!-- Present (clocked_in) -->
                        <div class="d-flex align-items-start mb-4">
                            <div class="icon-circle bg-success bg-opacity-10 text-success me-3">
                                <i class="ti ti-user-check fs-5"></i>
                            </div>
                            <div class="w-100">
                                <h6 class="fw-bold mb-1">Present</h6>
                                <h4 class="text-success fw-bold mb-1">
                                    {{ $countClockedIn }} <small class="text-muted fs-6">/ {{ $totalEmployees }}</small>
                                </h4>
                                <div class="progress w-100" style="height: 6px;">
                                    <div class="progress-bar bg-success"
                                         style="width: {{ $totalEmployees ? ($countClockedIn/$totalEmployees)*100 : 0 }}%;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Clocked Out (optional category) -->
                        <div class="d-flex align-items-start mb-4">
                            <div class="icon-circle bg-info bg-opacity-10 text-info me-3">
                                <i class="ti ti-clock-off fs-5"></i>
                            </div>
                            <div class="w-100">
                                <h6 class="fw-bold mb-1">Clocked Out</h6>
                                <h4 class="text-info fw-bold mb-1">
                                    {{ $countClockedOut }} <small
                                        class="text-muted fs‑6">/ {{ $totalEmployees }}</small>
                                </h4>
                            </div>
                        </div>

                        <!-- Absent -->
                        <div class="d-flex align-items-start mb-4">
                            <div class="icon-circle bg-danger bg-opacity-10 text-danger me-3">
                                <i class="ti ti-user-x fs-5"></i>
                            </div>
                            <div class="w-100">
                                <h6 class="fw-bold mb-1">Absent</h6>
                                <h4 class="text-danger fw-bold mb-1">
                                    {{ $countAbsent + $countUncheckedIn  }} <small
                                        class="text-muted fs‑6">/ {{ $totalEmployees }}</small>
                                </h4>
                                <div class="progress w-100" style="height: 6px;">
                                    <div class="progress-bar bg-danger"
                                         style="width: {{ $totalEmployees ? ($countAbsent/$totalEmployees)*100 : 0 }}%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Keep employee section full width -->
    <div class="container my-4">
        <div class="card p-4 border-0 shadow-sm">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                <div>
                    <h5 class="fw-bold mb-1">
                        @switch($status)
                            @case('clocked_in') Clocked-In Employees @break
                            @case('clocked_out') Clocked-Out Employees @break
                            @case('absent') Absent Employees @break
                            @default All Employees
                        @endswitch
                    </h5>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="iconify" data-icon="mdi:filter-variant"></span>
                            Filter Status
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            @foreach(['all' => 'All', 'clocked_in' => 'Clock In', 'clocked_out' => 'Clock Out', 'absent' => 'Absent'] as $key => $label)
                                <li>
                                    <a wire:click.prevent="updateStatus('{{ $key }}')"
                                       class="dropdown-item d-flex align-items-center {{ $status === $key ? 'fw-bold' : '' }}"
                                       href="#">
                                        <span
                                            class="rounded-circle bg-{{ $key === 'clocked_in' ? 'success' : ($key === 'clocked_out' ? 'primary' : ($key === 'absent' ? 'danger' : ($key === 'unchecked_in' ? 'info' : 'secondary'))) }} me-2"
                                            style="width: 8px; height: 8px;"></span>
                                        {{ $label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            @foreach($employees as $employee)
                @php
                    $status = $employee->todayAttendance->status ?? 'unchecked_in';
                    $badge = match($status) {
                        'clocked_in' => 'badge-active',
                        'clocked_out' => 'badge-primary',
                        'absent' => 'badge-danger',
                        'unchecked_in' => 'badge-warning',
                        default => 'badge-secondary',
                    };
                @endphp

                <div class="card mb-3 p-3 border-0 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="d-flex align-items-center">
                            <div class="avatar-initial me-3">{{ strtoupper(substr($employee->name, 0, 2)) }}</div>
                            <div>
                                <h6 class="mb-0 fw-bold">{{ $employee->name }}</h6>
                                <div class="mt-1 text-muted small">
                                    <i class="ti ti-clock me-1"></i> {{ $employee->todayAttendance?->created_at->format('h:i A') ?? 'N/A' }}
                                    <br>
                                    <i class="ti ti-map-pin me-1"></i> {{ $employee->department->name ?? '—' }}
                                </div>
                            </div>
                        </div>
                        <span class="status-badge {{ $badge }} mt-2 mt-md-0">
                <i class="ti ti-user-check"></i> {{ str_replace('_', ' ', $status) }}
            </span>
                    </div>
                </div>
            @endforeach

        </div>
    </div>
</div>

@push('scripts')
    <script src="https://code.iconify.design/3/3.1.0/iconify.min.js"></script>
@endpush

