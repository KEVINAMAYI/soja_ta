<?php

use App\Models\Employee;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Shift;

new class extends Component {

    public $viewMode = 'week';
    public $selectedShift = null;
    public $selectedDate;
    public $filterDepartment = 'all';
    public $showStaffPanel = false;
    public $searchTerm = '';
    public $shifts = [];
    public $stats = [];
    public $availableStaff = [];
    public $departments = [];


    public function mount()
    {
        $this->selectedDate = Carbon::today();
        $this->loadShifts();
    }


    private function loadShifts()
    {
        $this->shifts = $this->getShifts();
        $this->stats = $this->getStats($this->shifts);
        $this->availableStaff = $this->getAvailableStaff();
        $this->departments = $this->buildDepartmentsFromShifts($this->shifts);

    }

    public function setViewMode($mode)
    {
        $this->viewMode = $mode;
        $this->shifts = $this->getShifts();
    }


    private function buildDepartmentsFromShifts(array $shifts): array
    {
        $departments = collect($shifts)
            ->pluck('dept')     // shift name
            ->unique()
            ->sort()
            ->mapWithKeys(fn($name) => [
                Str::slug($name) => $name
            ])
            ->toArray();

        return array_merge(
            ['all' => 'All Shifts'],
            $departments
        );
    }


    public function selectShift($shiftId)
    {
        $shifts = $this->getShifts();
        $this->selectedShift = collect($shifts)->firstWhere('id', $shiftId);
        $this->availableStaff = $this->getAvailableStaff();
        $this->showStaffPanel = false;
    }

    public function closeShiftDetails()
    {
        $this->selectedShift = null;
        $this->showStaffPanel = false;
    }

    public function toggleStaffPanel()
    {
        $this->showStaffPanel = !$this->showStaffPanel;
    }

    public function navigateDate($direction)
    {
        if ($this->viewMode === 'day') {
            $this->selectedDate = $this->selectedDate->addDays($direction);
        } elseif ($this->viewMode === 'week') {
            $this->selectedDate = $this->selectedDate->addWeeks($direction);
        } elseif ($this->viewMode === 'month') {
            $this->selectedDate = $this->selectedDate->addMonths($direction);
        }

        $this->shifts = $this->getShifts();

    }

    public function goToToday()
    {
        $this->selectedDate = Carbon::today();
        $this->shifts = $this->getShifts();

    }

    public function setDateAndView($date)
    {
        $this->selectedDate = Carbon::parse($date);
        $this->viewMode = 'day';
        $this->shifts = $this->getShifts();

    }


    private function getShifts()
    {
        $organizationId = auth()->user()->employee->organization_id;

        // Determine date range based on view mode
        if ($this->viewMode === 'month') {
            $startDate = $this->selectedDate->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::MONDAY);
            $endDate = $this->selectedDate->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
        } elseif ($this->viewMode === 'week') {
            $startDate = $this->selectedDate->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
            $endDate = $this->selectedDate->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
        } else { // day view
            $startDate = $this->selectedDate->copy();
            $endDate = $this->selectedDate->copy();
        }

        $shifts = DB::table('shifts')
            ->where('status', 'active')
            ->where('organization_id', $organizationId)
            ->get();

        $allShifts = [];
        $today = Carbon::today();

        // Generate shifts for each day in the range
        while ($startDate <= $endDate) {
            $date = $startDate->toDateString();
            $isFutureDate = $startDate->isFuture();

            // Get the day of week in short format (Mon, Tue, etc.)
            $dayOfWeek = $startDate->format('D'); // Returns: Mon, Tue, Wed, etc.

            foreach ($shifts as $shift) {

                // Decode pattern_days from JSON
                $patternDays = json_decode($shift->pattern_days, true) ?? [];

                // Skip this shift if it doesn't run on this day of the week
                if (!in_array($dayOfWeek, $patternDays)) {
                    continue;
                }

                // Employees assigned to this shift
                $employees = DB::table('employees')
                    ->where('shift_id', $shift->id)
                    ->where('organization_id', $organizationId)
                    ->get(['id', 'name']);

                $totalEmployees = $employees->count();

                // For future dates, show as "scheduled" not critical
                if ($isFutureDate) {
                    $allShifts[] = [
                        'id' => $shift->id,
                        'date' => $date,
                        'time' => $shift->start_time . ' - ' . $shift->end_time,
                        'dept' => $shift->name,
                        'required' => $totalEmployees,
                        'assigned' => 0,
                        'staff' => $employees->pluck('name')->toArray(),
                        'status' => 'scheduled',
                    ];
                    continue;
                }

                if ($totalEmployees === 0) {
                    $allShifts[] = [
                        'id' => $shift->id,
                        'date' => $date,
                        'time' => $shift->start_time . ' - ' . $shift->end_time,
                        'dept' => $shift->name,
                        'required' => 0,
                        'assigned' => 0,
                        'staff' => [],
                        'status' => 'critical',
                    ];
                    continue;
                }

                // Attendance for this shift's employees on this date (only for past/today)
                $presentEmployeeIds = DB::table('attendances')
                    ->whereDate('date', $date)
                    ->whereIn('employee_id', $employees->pluck('id'))
                    ->whereIn('status', ['clocked_in', 'clocked_out'])
                    ->pluck('employee_id')
                    ->unique();

                $presentCount = $presentEmployeeIds->count();

                // Determine shift status
                if ($presentCount === $totalEmployees) {
                    $status = 'full';
                } elseif ($presentCount > ($totalEmployees / 2)) {
                    $status = 'partial';
                } else {
                    $status = 'critical';
                }

                $allShifts[] = [
                    'id' => $shift->id,
                    'date' => $date,
                    'time' => $shift->start_time . ' - ' . $shift->end_time,
                    'dept' => $shift->name,
                    'required' => $totalEmployees,
                    'assigned' => $presentCount,
                    'staff' => $employees->pluck('name')->toArray(),
                    'status' => $status,
                ];
            }

            $startDate->addDay();
        }

        return $allShifts;
    }

    private function getAvailableStaff()
    {
        if (!$this->selectedShift) return [];

        $organizationId = auth()->user()->employee->organization_id;
        $currentShiftId = $this->selectedShift['id'];

        return Employee::query()
            ->where('organization_id', $organizationId)
            // 1. Exclude those already on THIS shift
            ->where(function ($query) use ($currentShiftId) {
                $query->where('shift_id', '!=', $currentShiftId)
                    ->orWhereNull('shift_id');
            })

            // 2. Search filter
            ->when($this->searchTerm, function ($query) {
                $query->where('name', 'like', '%' . $this->searchTerm . '%');
            })
            ->with('shift') // To show what shift they are currently on
            ->get();
    }


    public function assignStaffToShift($employeeId)
    {
        if (!$this->selectedShift) return;

        try {
            $employee = Employee::findOrFail($employeeId);
            $newShiftId = $this->selectedShift['id'];

            // Perform the reassignment
            $employee->update([
                'shift_id' => $newShiftId,
                'shift_status' => 'on_shift'
            ]);

            // Refresh data
            $this->loadShifts();

            // Refresh the selectedShift data to show the new staff count immediately
            $shifts = $this->getShifts();
            $this->selectedShift = collect($shifts)->firstWhere('id', $newShiftId);

            LivewireAlert::title('Awesome!')
                ->text("{$employee->name} has been reassigned successfully!")
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {

            LivewireAlert::title('Error!')
                ->text('Failed to assign staff!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }


    private function getShiftsForDate(Carbon $date)
    {
        $dateStr = $date->toDateString();

        return collect($this->getShifts())
            ->filter(fn($shift) => $shift['date'] === $dateStr)
            ->values();
    }

    private function getStats()
    {
        $today = Carbon::today()->toDateString();
        $shifts = collect($this->getShifts())
            ->filter(fn($shift) => $shift['date'] === $today);

        return [
            'total' => $shifts->count(),
            'full' => $shifts->where('status', 'full')->count(),
            'partial' => $shifts->where('status', 'partial')->count(),
            'critical' => $shifts->where('status', 'critical')->count(),
            'scheduled' => $shifts->where('status', 'scheduled')->count(),
        ];
    }


}; ?>

@push('styles')
    <style>
        .shift-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border-width: 2px;
        }

        .shift-card:hover {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .shift-card.selected {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.5);
        }

        .shift-status-full {
            background-color: #d1fae5;
            border-color: #6ee7b7;
            color: #065f46;
        }

        .shift-status-partial {
            background-color: #fef3c7;
            border-color: #fcd34d;
            color: #92400e;
        }

        .shift-status-critical {
            background-color: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .staff-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #0d6efd;
            border: 2px solid white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
            margin-left: -8px;
        }

        .staff-avatar:first-child {
            margin-left: 0;
        }

        .stat-card {
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .calendar-day {
            min-height: 100px;
            transition: all 0.2s ease;
        }

        .calendar-day:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .calendar-day.today {
            border-color: #0d6efd !important;
            border-width: 2px;
        }

        .calendar-day.other-month {
            background-color: #f8f9fa;
            opacity: 0.6;
        }

        .progress-bar-custom {
            height: 8px;
            border-radius: 10px;
            overflow: hidden;
        }

        .btn-view-mode {
            transition: all 0.2s ease;
        }

        .btn-view-mode.active {
            background-color: #cfe2ff;
            color: #084298;
        }

        .staff-list-item {
            transition: background-color 0.2s ease;
        }

        .staff-list-item:hover {
            background-color: #f8f9fa;
        }
    </style>
@endpush

<div>
    <!-- Header -->
    <div class="bg-white border-bottom py-3 px-4 ">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1 fw-bold">Shift Monitoring</h1>
                <p class="text-muted mb-0 small">Manage shift assignments and coverage</p>
            </div>
        </div>
    </div>

    <!-- Stats Dashboard -->
    <!-- Stats Dashboard -->
    <div style="margin-top: -5px;" class="row g-3">
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Today's Shifts</p>
                            <h2 class="mb-0 fw-bold">{{ $stats['total'] }}</h2>
                        </div>
                        <i class="bi bi-calendar3 text-secondary" style="font-size: 24px;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Fully Staffed</p>
                            <h2 class="mb-0 fw-bold text-success">{{ $stats['full'] }}</h2>
                        </div>
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 24px;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Partial Coverage</p>
                            <h2 class="mb-0 fw-bold text-warning">{{ $stats['partial'] }}</h2>
                        </div>
                        <i class="bi bi-exclamation-circle-fill text-warning" style="font-size: 24px;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Critical Gaps</p>
                            <h2 class="mb-0 fw-bold text-danger">{{ $stats['critical'] }}</h2>
                        </div>
                        <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size: 24px;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and View Controls -->
    <div style="margin-top:-5px;" class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex gap-3">
                        <div class="d-flex align-items-centeR">
                            <i class="bi bi-funnel text-muted"></i>
                            <select wire:model="filterDepartment" class="form-select"
                                    style="width: 200px;">
                                @foreach($departments as $key => $dept)
                                    <option value="{{ $key }}">{{ $dept }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="btn-group float-end" role="group">
                        <button wire:click="setViewMode('day')"
                                class="btn btn-sm btn-view-mode {{ $viewMode === 'day' ? 'active' : 'btn-outline-secondary' }}">
                            Day
                        </button>
                        <button wire:click="setViewMode('week')"
                                class="btn btn-sm btn-view-mode {{ $viewMode === 'week' ? 'active' : 'btn-outline-secondary' }}">
                            Week
                        </button>
                        <button wire:click="setViewMode('month')"
                                class="btn btn-sm btn-view-mode {{ $viewMode === 'month' ? 'active' : 'btn-outline-secondary' }}">
                            Month
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div STYLE="margin-top:-20px;" class="row">
        <!-- Calendar/Shift View -->
        <div class="{{ $selectedShift ? 'col-lg-8' : 'col-12' }}">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 fw-semibold">{{ $selectedDate->format('F Y') }}</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button style="padding-bottom:0px;" wire:click="navigateDate(-1)" type="button"
                                    class="btn btn-outline-secondary">
                                <iconify-icon icon="mdi:chevron-left"></iconify-icon>
                            </button>
                            <button wire:click="goToToday" type="button" class="btn btn-outline-secondary">Today
                            </button>
                            <button style="padding-bottom:0px;" wire:click="navigateDate(1)" type="button"
                                    class="btn btn-outline-secondary">
                                <iconify-icon icon="mdi:chevron-right"></iconify-icon>
                            </button>
                        </div>
                    </div>

                    @if($viewMode === 'day')
                        <!-- Day View -->
                        <div class="mb-3">
                            <h4 class="fw-semibold">{{ $selectedDate->format('l, F j') }}</h4>
                        </div>
                        <div class="row g-3">
                            @php
                                $dayShifts = collect($shifts)->filter(function($shift) {
                                    return $shift['date'] === $this->selectedDate->format('Y-m-d');
                                });
                            @endphp

                            @forelse($dayShifts as $shift)
                                <div class="col-12">
                                    <div wire:click="selectShift({{ $shift['id'] }})"
                                         class="shift-card shift-status-{{ $shift['status'] }} p-3 rounded {{ $selectedShift && $selectedShift['id'] === $shift['id'] ? 'selected' : '' }}">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h6 class="fw-semibold mb-1">{{ $shift['dept'] }}</h6>
                                                <div class="d-flex align-items-center gap-2 small">
                                                    <i class="bi bi-clock"></i>
                                                    <span>{{ $shift['time'] }}</span>
                                                </div>
                                            </div>
                                            @if($shift['status'] === 'critical')
                                                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                                            @elseif($shift['status'] === 'scheduled')
                                                <i class="bi bi-calendar-check text-primary"></i>
                                            @endif
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small">
                            <span class="fw-semibold">
                                @if($shift['status'] === 'scheduled')
                                    Scheduled
                                @elseif($shift['status'] === 'full')
                                    Fully Staffed
                                @elseif($shift['status'] === 'critical')
                                    Critical Gap
                                @else
                                    {{ $shift['assigned'] }}/{{ $shift['required'] }} Staff
                                @endif
                            </span>
                                            </div>
                                            <div class="d-flex">
                                                @foreach(array_slice($shift['staff'], 0, 3) as $index => $staff)
                                                    <div class="staff-avatar">{{ chr(65 + $index) }}</div>
                                                @endforeach
                                                @if(count($shift['staff']) > 3)
                                                    <div class="staff-avatar" style="background-color: #6c757d;">
                                                        +{{ count($shift['staff']) - 3 }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-12">
                                    <div class="text-center py-5 text-muted">
                                        <i class="bi bi-calendar-x" style="font-size: 48px;"></i>
                                        <p class="mt-3">No shifts scheduled for this day</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>

                    @elseif($viewMode === 'week')
                        <!-- Week View -->
                        <div class="mb-3">
                            <div class="row g-2">
                                @for($i = 0; $i < 7; $i++)
                                    {{-- Changed from 5 to 7 --}}
                                    @php
                                        $day = $selectedDate->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays($i);
                                    @endphp
                                    <div class="col text-center">
                                        <div class="small fw-medium text-muted">
                                            {{ $day->format('D') }} {{ $day->format('j') }}
                                        </div>
                                    </div>
                                @endfor
                            </div>
                        </div>
                        <div class="row g-3">
                            @for($i = 0; $i < 7; $i++)
                                {{-- Changed from 5 to 7 --}}
                                @php
                                    $day = $selectedDate->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays($i);
                                    $dayShifts = collect($shifts)->filter(function($shift) use ($day) {
                                        return $shift['date'] === $day->format('Y-m-d');
                                    });
                                @endphp
                                <div class="col">
                                    @forelse($dayShifts as $shift)
                                        <div wire:click="selectShift({{ $shift['id'] }})"
                                             class="shift-card shift-status-{{ $shift['status'] }} p-2 rounded mb-2 {{ $selectedShift && $selectedShift['id'] === $shift['id'] ? 'selected' : '' }}"
                                             style="font-size: 12px;">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="fw-semibold">{{ $shift['dept'] }}</div>
                                                @if($shift['status'] === 'critical')
                                                    <i class="bi bi-exclamation-triangle-fill"
                                                       style="font-size: 14px;"></i>
                                                @endif
                                            </div>
                                            <div class="d-flex align-items-center gap-1 mb-2">
                                                <i class="bi bi-clock" style="font-size: 10px;"></i>
                                                <span style="font-size: 10px;">{{ $shift['time'] }}</span>
                                            </div>
                                            <div class="fw-medium" style="font-size: 11px;">
                                                @if($shift['status'] === 'scheduled')
                                                    Scheduled
                                                @elseif($shift['status'] === 'full')
                                                    Fully Staffed
                                                @elseif($shift['status'] === 'critical')
                                                    Critical Gap
                                                @else
                                                    {{ $shift['assigned'] }}/{{ $shift['required'] }} Staff
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        <div class="p-3 text-center border border-2 border-dashed rounded"
                                             style="font-size: 12px; color: #adb5bd;">
                                            No shifts
                                        </div>
                                    @endforelse
                                </div>
                            @endfor
                        </div>
                    @else
                        <!-- Month View -->
                        <div class="mb-3">
                            <div class="row g-0 text-center fw-semibold small text-muted pb-2">
                                <div class="col">Mon</div>
                                <div class="col">Tue</div>
                                <div class="col">Wed</div>
                                <div class="col">Thu</div>
                                <div class="col">Fri</div>
                                <div class="col">Sat</div>
                                <div class="col">Sun</div>
                            </div>
                        </div>

                        <div class="row g-2">
                            @php
                                $firstDay = $selectedDate->copy()->startOfMonth();
                                $lastDay = $selectedDate->copy()->endOfMonth();
                                $startDay = $firstDay->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                                $endDay = $lastDay->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
                                $currentDay = $startDay->copy();
                            @endphp

                            @while($currentDay <= $endDay)
                                @php
                                    $isCurrentMonth = $currentDay->month === $selectedDate->month;
                                    $isToday = $currentDay->isToday();

                                    // Get shifts for this day from dynamic data
                                    $dayShifts = collect($shifts)->filter(function($shift) use ($currentDay) {
                                        return $shift['date'] === $currentDay->format('Y-m-d');
                                    });

                                    $criticalCount = $dayShifts->where('status', 'critical')->count();
                                    $partialCount  = $dayShifts->where('status', 'partial')->count();
                                    $fullCount     = $dayShifts->where('status', 'full')->count();
                                    $scheduledCount = $dayShifts->where('status', 'scheduled')->count();

                                @endphp

                                <div class="col mb-2">
                                    <div wire:click="setDateAndView('{{ $currentDay->format('Y-m-d') }}')"
                                         class="calendar-day border rounded p-2 {{ $isCurrentMonth ? 'bg-white' : 'calendar-day other-month' }} {{ $isToday ? 'today' : '' }}"
                                         style="cursor: {{ $dayShifts->count() > 0 ? 'pointer' : 'default' }};">

                                        <div
                                            class="small fw-medium mb-2 {{ $isCurrentMonth ? 'text-dark' : 'text-muted' }}">
                                            {{ $currentDay->format('j') }}
                                        </div>

                                        @if($criticalCount > 0)
                                            <div class="badge bg-danger d-flex justify-content-between"
                                                 style="font-size: 10px;">
                                                <span>Critical</span>
                                                <span class="fw-bold">{{ $criticalCount }}</span>
                                            </div>
                                        @endif
                                        @if($partialCount > 0)
                                            <div class="badge bg-warning text-dark d-flex justify-content-between"
                                                 style="font-size: 10px;">
                                                <span>Partial</span>
                                                <span class="fw-bold">{{ $partialCount }}</span>
                                            </div>
                                        @endif
                                        @if($fullCount > 0)
                                            <div class="badge bg-success d-flex justify-content-between"
                                                 style="font-size: 10px;">
                                                <span>Full</span>
                                                <span class="fw-bold">{{ $fullCount }}</span>
                                            </div>
                                        @endif
                                        @if($scheduledCount > 0)
                                            <div class="badge bg-primary d-flex justify-content-between"
                                                 style="font-size: 10px;">
                                                <span>Scheduled</span>
                                                <span class="fw-bold">{{ $scheduledCount }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                @if($currentDay->dayOfWeek === \Carbon\Carbon::SUNDAY)
                        </div>
                        <div class="row g-2">
                            @endif

                            @php $currentDay->addDay(); @endphp
                            @endwhile
                        </div>

                    @endif
                </div>
            </div>
        </div>

        <!-- Shift Details Panel -->
        @if($selectedShift)
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0 fw-semibold">Shift Details</h5>
                            <button wire:click="closeShiftDetails" class="btn-close"></button>
                        </div>

                        <div class="mb-4">
                            <label class="small text-muted mb-1">Name</label>
                            <p class="mb-0 fw-medium">{{ $selectedShift['dept'] }}</p>
                        </div>

                        <div class="mb-4">
                            <label class="small text-muted mb-1">Date & Time</label>
                            <p class="mb-1 fw-medium">{{ \Carbon\Carbon::parse($selectedShift['date'])->format('l, M j') }}</p>
                            <p class="mb-0 small text-muted">{{ $selectedShift['time'] }}</p>
                        </div>

                        <div class="mb-4">
                            <label class="small text-muted mb-2 d-block">Coverage Status</label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="flex-grow-1 bg-light rounded progress-bar-custom">
                                    @php
                                        $percentage = $selectedShift['required'] > 0 ? ($selectedShift['assigned'] / $selectedShift['required']) * 100 : 0;
                                        $barColor = $selectedShift['status'] === 'full' ? 'bg-success' : ($selectedShift['status'] === 'partial' ? 'bg-warning' : 'bg-danger');
                                    @endphp
                                    <div class="progress-bar {{ $barColor }}"
                                         style="width: {{ $percentage }}%; height: 100%;"></div>
                                </div>
                                <span
                                    class="small fw-semibold">{{ $selectedShift['assigned'] }}/{{ $selectedShift['required'] }}</span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small text-muted mb-2 d-block">Assigned Staff</label>
                            <div class="d-flex flex-column gap-2">
                                @forelse($selectedShift['staff'] as $staff)
                                    <div
                                        class="d-flex justify-content-between align-items-center p-2 bg-light rounded staff-list-item">
                                        <span class="small">{{ $staff }}</span>
                                        <button class="btn btn-sm btn-link text-danger p-0"
                                                style="font-size: 12px;">Remove
                                        </button>
                                    </div>
                                @empty
                                    <div class="p-3 text-center bg-light rounded">
                                        <small class="text-muted">No staff assigned yet</small>
                                    </div>
                                @endforelse

                                @if($selectedShift['assigned'] < $selectedShift['required'])
                                    <button wire:click="toggleStaffPanel"
                                            class="btn btn-outline-primary btn-sm border-2 border-dashed">
                                        + Assign Staff
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if($showStaffPanel)
                            <div class="mt-4 border-top pt-3">
                                <h6 class="fw-bold mb-3">Assign Available Staff</h6>

                                <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text bg-white border-end-0">
                                            <i class="bi bi-search text-muted"></i>
                                        </span>
                                    <input type="text" wire:model.live="searchTerm" class="form-control border-start-0"
                                           placeholder="Search staff...">
                                </div>

                                <div class="staff-list" style="max-height: 300px; overflow-y: auto;">
                                    @forelse($availableStaff as $staff)
                                        <div
                                            class="staff-list-item p-2 border rounded mb-2 d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold small">{{ $staff->name }}</div>
                                                <div class="text-muted" style="font-size: 10px;">
                                                    {{ $staff->shift ? 'Currently: ' . $staff->shift->name : 'Unassigned' }}
                                                </div>
                                            </div>
                                            <button wire:click="assignStaffToShift({{ $staff->id }})"
                                                    class="btn btn-sm btn-outline-primary py-0 px-2"
                                                    style="font-size: 10px;">
                                                Assign
                                            </button>
                                        </div>
                                    @empty
                                        <div class="text-center py-3 text-muted small">No available staff found</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif

                        <div class="d-flex gap-2 pt-4">
                            <button class="btn btn-primary flex-grow-1">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

