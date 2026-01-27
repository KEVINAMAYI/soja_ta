<?php

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {

    public $status;

    #[Url]
    public $filterStatus;

    public $startDate;
    public $endDate;

    // Summary stats
    public $totalEmployees = 0;
    public $presentCount = 0;
    public $absentCount = 0;
    public $sickOffCount = 0;
    public $onLeaveCount = 0;
    public $offShiftCount = 0;
    public $inactiveCount = 0;

    public function mount()
    {
        $this->status = $this->filterStatus;
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;

        $this->loadSummaryStats();
    }

    public function loadSummaryStats()
    {
        // Get organization ID
        $employeeRecord = Employee::where('user_id', auth()->id())->first();
        $orgId = $employeeRecord->organization_id ?? null;

        if (!$orgId) return;

        // Get all employees in organization
        $employees = Employee::where('organization_id', $orgId)->get();
        $this->totalEmployees = $employees->count();
        $employeeIds = $employees->pluck('id');

        // Get attendances for date range
        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$this->startDate, $this->endDate])
            ->get();

        // Get employees who actually showed up (clocked in or out)
        $presentEmployeeIds = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique();

        // Get employees marked absent BUT exclude those who showed up
        $absentEmployeeIds = $attendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->pluck('employee_id')
            ->unique()
            ->reject(fn($id) => $presentEmployeeIds->contains($id));


        $this->presentCount = $presentEmployeeIds->count();
        $this->absentCount = $absentEmployeeIds->count();

        $this->sickOffCount = $attendances
            ->where('status', 'sick_off')
            ->pluck('employee_id')
            ->unique()
            ->count();

        $this->onLeaveCount = $attendances
            ->where('status', 'on_leave')
            ->pluck('employee_id')
            ->unique()
            ->count();

        $this->offShiftCount = $attendances
            ->where('status', 'off_shift')
            ->pluck('employee_id')
            ->unique()
            ->count();

        $this->inactiveCount = Employee::where('organization_id', $orgId)
            ->where('active', 0)
            ->count();

        $this->inactiveCount = Employee::where('organization_id', $orgId)
            ->where('active', 0)
            ->count();
    }

    #[On('filter-updated')]
    public function dateChanged()
    {
        $this->loadSummaryStats();
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, status: $this->filterStatus);
    }

}; ?>

@push('styles')
    <style>
        .summary-stats-row {
            margin-bottom: 2rem;
        }

        .summary-card {
            background: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 1.25rem;
            height: 100%;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .summary-card-icon {
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

        .summary-card-title {
            margin: 0 0 0.5rem 0;
            font-size: 0.75rem;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .summary-card-total {
            font-size: 1.25rem;
            color: #9ca3af;
            font-weight: 400;
        }

        .summary-card-subtitle {
            margin: 0;
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 400;
        }
    </style>
@endpush

<div class="row">
    <div class="col-12">

        @php
            $statusLabel = 'Attendance';
            $breadcrumbItems = [
                [
                    'label' => 'Dashboard',
                    'url' => route('dashboard'),
                    'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
                ],
                [
                    'label' => $statusLabel,
                    'url' => Route::currentRouteName() === 'timesheets.index'
                        ? route('timesheets.index')
                        : route('attendance.index'),
                    'icon' => Route::currentRouteName() === 'timesheets.index'
                        ? '<iconify-icon icon="mdi:calendar-clock" class="fs-5 text-primary"></iconify-icon>'
                        : '<iconify-icon icon="mdi:clipboard-text-check-outline" class="fs-5"></iconify-icon>',
                ],
            ];
        @endphp

        <livewire:admin.system-settings.bread-crumb
            :title="$statusLabel"
            :items="$breadcrumbItems"
        />

        <!-- Summary Stats -->
        <div class="row g-3 mb-4 summary-stats-row">
            <!-- Present Today -->
            <div class="col-lg-6 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('attendance.index', ['filterStatus' => 'present']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-success">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">Present Today</h6>
                        <div class="summary-card-value">{{ $presentCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($presentCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>

            <!-- Absent Today -->
            <div class="col-lg-6 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('attendance.index', ['filterStatus' => 'absent']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-danger">
                            <iconify-icon icon="mdi:account-remove"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">Absent Today</h6>
                        <div class="summary-card-value">{{ $absentCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($absentCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>

            <!-- Sick Off Today -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('attendance.index', ['filterStatus' => 'sick_off']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-info">
                            <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">Sick Off Today</h6>
                        <div class="summary-card-value">{{ $sickOffCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($sickOffCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>

            <!-- On Leave Today -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('attendance.index', ['filterStatus' => 'on_leave']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-warning">
                            <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">On Leave</h6>
                        <div class="summary-card-value">{{ $onLeaveCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($onLeaveCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>

            <!-- Off Shift Today -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('attendance.index', ['filterStatus' => 'off_shift']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-cyan">
                            <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">Off Shift</h6>
                        <div class="summary-card-value">{{ $offShiftCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($offShiftCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>

            <!-- Inactive Employees -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card">
                    <a href="{{ route('employees.index', ['active' => '0']) }}" class="stat-card-link">
                        <div class="summary-card-icon icon-secondary">
                            <iconify-icon icon="mdi:account-off"></iconify-icon>
                        </div>
                        <h6 class="summary-card-title">Inactive</h6>
                        <div class="summary-card-value">{{ $inactiveCount }} <span
                                class="summary-card-total">/ {{ $totalEmployees }}</span></div>
                        <p class="summary-card-subtitle">
                            {{ $totalEmployees > 0 ? number_format(($inactiveCount / $totalEmployees) * 100, 1) : 0 }}%
                        </p>
                    </a>
                </div>
            </div>
        </div>


        <div class="card card-body">
            <div class="row align-items-end mb-4 justify-content-end">
                <div class="col-md-4 text-end">
                    @if (str_contains($filterStatus, 'sick_off'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Sick Off
                        </a>
                    @elseif (str_contains($filterStatus, 'off_shift'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Off Shifts
                        </a>
                    @elseif (str_contains($filterStatus, 'on_leave'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Leave Request
                        </a>
                    @endif
                </div>
            </div>

            <div class="row align-items-end mb-4">
                <div class="col-md-4">
                    <label class="form-label">Start Date</label>
                    <input
                        type="date"
                        id="attendance-start-date"
                        class="form-control"
                        wire:model="startDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                <div class="col-md-4">
                    <label class="form-label">End Date</label>
                    <input
                        type="date"
                        id="attendance-end-date"
                        class="form-control"
                        wire:model="endDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                <div class="col-md-4">
                    <label class="form-label">Attendance Status</label>
                    <select
                        class="form-control"
                        wire:model="filterStatus"
                        wire:change="$dispatch('filter-updated')">
                        <option value="">All</option>
                        <option value="present">Present [Clocked In + Clocked Out]</option>
                        <option value="clocked_in">Clocked In</option>
                        <option value="clocked_out">Clocked Out</option>
                        <option value="absent">Absent</option>
                        <option value="on_leave">On Leave</option>
                        <option value="off_shift">Off Shift</option>
                        <option value="sick_off">Sick Off</option>
                        {{--                        <option value="inactive">Inactive Employees</option>--}}
                    </select>
                </div>
            </div>

            <livewire:attendance-daily-table :status="$status ?? null" theme="bootstrap-4"/>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('replace-url', event => {
            window.history.replaceState({}, '', event.detail.url);
        });
    </script>
@endpush
