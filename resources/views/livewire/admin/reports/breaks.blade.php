<?php

use App\Models\Attendance;
use App\Models\AttendanceBreakLog;
use App\Models\Employee;
use Carbon\Carbon;
use Livewire\Volt\Component;

new class extends Component {

    public $startDate;
    public $endDate;

    public $totalEmployees = 0;
    public $compliantBreaks = 0;
    public $overLimitBreaks = 0;
    public $avgHoursWorked = '0.0';

    public function mount()
    {
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
        $this->loadStats();
    }

    public function loadStats()
    {
        $employeeRecord = Employee::withSystemUsers()->where('user_id', auth()->id())->first();
        $orgId = $employeeRecord->organization_id ?? null;

        if (!$orgId) return;

        $this->totalEmployees = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->count();

        $breaks = AttendanceBreakLog::with(['attendance'])
            ->whereHas('attendance', fn($q) => $q->whereBetween('date', [$this->startDate, $this->endDate])
            )
            ->whereHas('attendance.employee', fn($q) => $q->where('organization_id', $orgId)->where('active', 1)
            )
            ->get();

        $this->compliantBreaks = $breaks
            ->where('status', 'completed')
            ->where('is_compliant', 1)
            ->count();

        $this->overLimitBreaks = $breaks
            ->where('status', 'completed')
            ->where('is_compliant', 0)
            ->count();

        $attendanceIds = $breaks->pluck('attendance_id')->unique();
        $avgMinutes = Attendance::whereIn('id', $attendanceIds)
            ->whereNotNull('check_in_time')
            ->whereNotNull('check_out_time')
            ->get()
            ->avg(fn($a) => Carbon::parse($a->check_in_time)->diffInMinutes(Carbon::parse($a->check_out_time))
            );

        $this->avgHoursWorked = $avgMinutes
            ? number_format($avgMinutes / 60, 1)
            : '0.0';
    }

    public function applyFilter()
    {
        $this->loadStats();
        $this->dispatch('break-date-range-updated',
            startDate: $this->startDate,
            endDate: $this->endDate,
        );
    }
};
?>

@push('styles')
    <style>
        .break-report-header {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
        }

        .break-report-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--bs-primary, #0d6efd);
            margin: 0 0 0.3rem;
        }

        .break-report-header .subtitle {
            font-size: 0.83rem;
            color: #6b7280;
            margin: 0;
        }

        .break-stat-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            height: 100%;
        }

        .break-stat-card .stat-label {
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #9ca3af;
            margin-bottom: 0.4rem;
        }

        .break-stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #111827;
        }

        .break-stat-card .stat-value.primary {
            color: var(--bs-primary, #0d6efd);
        }
    </style>
@endpush

<div>

    {{-- Stat cards --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 col-6">
            <div class="break-stat-card">
                <div class="stat-label">Total Employees</div>
                <div class="stat-value primary">{{ $totalEmployees }}</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="break-stat-card">
                <div class="stat-label">Compliant Breaks</div>
                <div class="stat-value" style="color:#111827;">{{ $compliantBreaks }}</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="break-stat-card">
                <div class="stat-label">Over Limit Breaks</div>
                <div class="stat-value" style="color:#111827;">{{ $overLimitBreaks }}</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="break-stat-card">
                <div class="stat-label">Avg Hours Worked</div>
                <div class="stat-value primary">{{ $avgHoursWorked }}</div>
            </div>
        </div>
    </div>

    {{-- Date filters only --}}
    <div class="card card-body mb-3">
        <div class="row align-items-end g-3">
            <div class="col-md-5">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" wire:model="startDate"/>
            </div>
            <div class="col-md-5">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" wire:model="endDate"/>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" wire:click="applyFilter">
                    Apply
                </button>
            </div>
        </div>
    </div>

    <livewire:attendance-break-table theme="bootstrap-4"/>

</div>
