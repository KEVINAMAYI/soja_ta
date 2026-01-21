<?php

use Livewire\Volt\Component;
use App\Models\Employee;
use App\Models\Attendance;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {

    public $totalEmployees;
    public $present;
    public $absent;
    public $onLeave;
    public $offShift;
    public $sickOff;
    public $inactiveEmployees;

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;

        // Total employees in the organization
        $this->totalEmployees = Employee::where('organization_id', $orgId)->count();

        $today = Carbon::today();

        // Fetch all today's attendances for this org
        $attendances = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereDate('date', $today)->get();

        // Present (clocked in/out) — unique employees
        $this->present = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->unique('employee_id')
            ->count();

        // Absent — unique employees
        $this->absent = $attendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->unique('employee_id')
            ->count();

        // On Leave — unique employees
        $this->onLeave = $attendances
            ->where('status', 'on_leave')
            ->unique('employee_id')
            ->count();

        // Off Shift — unique employees
        $this->offShift = $attendances
            ->where('status', 'off_shift')
            ->unique('employee_id')
            ->count();

        // Fetch Sick Off count
        $this->sickOff = $attendances->where('status', 'sick_off')->count();

        // Fetch Inactive Employees count
        $this->inactiveEmployees = Employee::where('organization_id', $orgId)->where('active', 0)->count();
    }

}; ?>

@push('styles')
    <style>
        .stat-card {
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


<div>
    <!-- Attendance Statistics Cards -->
    <div class="row g-3">
        <!-- Present Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-success">
                    <iconify-icon icon="mdi:account-check"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Present Today</h6>
                <div class="stat-card-value">{{ $present }} <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                </div>
                <p class="stat-card-subtitle">
                    {{ $totalEmployees > 0 ? number_format(($present / $totalEmployees) * 100, 1) : 0 }}%
                </p>
            </div>
        </div>

        <!-- Absent Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-danger">
                    <iconify-icon icon="mdi:account-remove"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Absent Today</h6>
                <div class="stat-card-value">{{ $absent }} <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                </div>
                <p class="stat-card-subtitle">
                    {{ $totalEmployees > 0 ? number_format(($absent / $totalEmployees) * 100, 1) : 0 }}%
                </p>
            </div>
        </div>

        <!-- Sick Off Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-info">
                    <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Sick Off Today</h6>
                <div class="stat-card-value">{{ $sickOff }} <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                </div>
                <p class="stat-card-subtitle">
                    Out of {{ $totalEmployees }} Total
                </p>
            </div>
        </div>

        <!-- On Leave Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-warning">
                    <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                </div>
                <h6 class="stat-card-title">On Leave Today</h6>
                <div class="stat-card-value">{{ $onLeave }} <span class="stat-card-total">/ {{ $totalEmployees }}</span>
                </div>
                <p class="stat-card-subtitle">
                    Out of {{ $totalEmployees }} Total
                </p>
            </div>
        </div>

        <!-- Off Shift Today -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-cyan">
                    <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Off Shift Today</h6>
                <div class="stat-card-value">{{ $offShift }} <span
                        class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                <p class="stat-card-subtitle">
                    Out of {{ $totalEmployees }} Total
                </p>
            </div>
        </div>

        <!-- Inactive Employees -->
        <div class="col-lg-6 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-icon icon-secondary">
                    <iconify-icon icon="mdi:account-off"></iconify-icon>
                </div>
                <h6 class="stat-card-title">Inactive Employees</h6>
                <div class="stat-card-value">{{ $inactiveEmployees }} <span
                        class="stat-card-total">/ {{ $totalEmployees }}</span></div>
                <p class="stat-card-subtitle">
                    Out of {{ $totalEmployees }} Total
                </p>
            </div>
        </div>
    </div>
</div>
