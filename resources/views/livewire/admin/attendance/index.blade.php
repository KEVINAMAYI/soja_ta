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

        // Calculate stats
        $this->presentCount = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique()
            ->count();

        $this->absentCount = $attendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->pluck('employee_id')
            ->unique()
            ->count();

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
        /* Summary Cards */
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.12);
        }

        .summary-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .summary-card-title {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            margin: 0;
            line-height: 1.4;
            letter-spacing: 0.3px;
        }

        .summary-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .summary-card-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .summary-card-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin: 0;
            font-weight: 500;
            line-height: 1.5;
        }

        /* Color variants */
        .summary-card-success .summary-card-value {
            color: #22c55e;
        }
        .summary-card-success .summary-card-icon {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.08) 100%);
            color: #22c55e;
        }

        .summary-card-danger .summary-card-value {
            color: #ef4444;
        }
        .summary-card-danger .summary-card-icon {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(239, 68, 68, 0.08) 100%);
            color: #ef4444;
        }

        .summary-card-info .summary-card-value {
            color: #3b82f6;
        }
        .summary-card-info .summary-card-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.08) 100%);
            color: #3b82f6;
        }

        .summary-card-warning .summary-card-value {
            color: #f59e0b;
        }
        .summary-card-warning .summary-card-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0.08) 100%);
            color: #f59e0b;
        }

        .summary-card-secondary .summary-card-value {
            color: #64748b;
        }
        .summary-card-secondary .summary-card-icon {
            background: linear-gradient(135deg, rgba(100, 116, 139, 0.15) 0%, rgba(100, 116, 139, 0.08) 100%);
            color: #64748b;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .summary-card {
                padding: 20px;
            }

            .summary-card-value {
                font-size: 2rem;
            }

            .summary-card-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
        }

        /* Add spacing for the summary cards row */
        .summary-stats-row {
            margin-bottom: 2rem;
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
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-success">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">Present Today</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $presentCount }}</div>
                    <p class="summary-card-subtitle">
                        {{ $totalEmployees > 0 ? number_format(($presentCount / $totalEmployees) * 100, 2) : 0 }}%
                        (Total: {{ $totalEmployees }})
                    </p>
                </div>
            </div>

            <!-- Absent Today -->
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-danger">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">Absent Today</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:account-remove"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $absentCount }}</div>
                    <p class="summary-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                </div>
            </div>

            <!-- Sick Off Today -->
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-info">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">Sick Off Today</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $sickOffCount }}</div>
                    <p class="summary-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                </div>
            </div>

            <!-- On Leave Today -->
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-warning">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">On Leave Today</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $onLeaveCount }}</div>
                    <p class="summary-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                </div>
            </div>

            <!-- Off Shift Today -->
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-info">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">Off Shift Today</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $offShiftCount }}</div>
                    <p class="summary-card-subtitle">Out of {{ $totalEmployees }} Total</p>
                </div>
            </div>

            <!-- Inactive Employees -->
            <div class="col-lg-2 col-md-4 col-6">
                <div class="summary-card summary-card-secondary">
                    <div class="summary-card-header">
                        <h6 class="summary-card-title">Inactive Employees</h6>
                        <div class="summary-card-icon">
                            <iconify-icon icon="mdi:account-off"></iconify-icon>
                        </div>
                    </div>
                    <div class="summary-card-value">{{ $inactiveCount }}</div>
                    <p class="summary-card-subtitle">Out of {{ $totalEmployees }} Total</p>
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
