<?php

use App\Models\Leave;
use App\Models\Employee;
use App\Models\Department;
use App\Models\LeaveType;
use App\Services\AttendanceSeeder;
use App\Services\LeaveApprovalService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    // --- Original Properties (Kept) ---
    public $step = 1;
    public $employees = [];
    public $originalEmployees = [];
    public $selectedEmployees = [];
    public $leaveType = '';
    public $durationType = 'dateRange';
    public $startDate = '';
    public $endDate = '';
    public $numberOfDays = '';
    public $searchTerm = '';
    public $sendNotifications = true;
    public $notifyManagers = true;
    public $leaveTypes = []; // loaded per-organization in mount()

    // --- Properties for New Form Fields (Optional, but included for complete logic) ---
    public $reason = '';
    public $contact_during_leave = '';
    public $emergency_contact = '';
    public $handover_to = '';

    public $selectedLeaveType;


    public function mount()
    {

        $orgId = auth()->user()->employee->organization_id ?? null;

        $this->startDate = $this->startDate ?: now()->toDateString();

        $employeeCollection = Employee::where('organization_id', $orgId)
            ->with('department')
            ->get();

        $data = $employeeCollection->toArray();
        $this->originalEmployees = $data;
        $this->employees = $data;

        $this->leaveTypes = LeaveType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'icon'])
            ->map(fn($t) => ['id' => $t->code, 'name' => $t->name, 'icon' => $t->icon ?? '📄'])
            ->toArray();
    }


    public function rules()
    {
        // Validation rules adapted from the original saveLeave method
        $rules = [
            'leaveType' => 'required|string',
            'selectedEmployees' => 'required|array|min:1',
            'startDate' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ];

        if ($this->durationType === 'dateRange') {
            $rules['endDate'] = 'required|date|after_or_equal:startDate';
        } else {
            $rules['numberOfDays'] = 'required|integer|min:1';
        }
        return $rules;
    }


    public function toggleEmployee($id)
    {
        if (in_array($id, $this->selectedEmployees)) {
            $this->selectedEmployees = array_diff($this->selectedEmployees, [$id]);
        } else {
            $this->selectedEmployees[] = $id;
        }
    }

    public function selectAll()
    {
        $this->selectedEmployees = collect($this->originalEmployees)
            ->filter(function ($emp) {
                return empty($emp['current_status_badge']);
            })
            ->pluck('id')
            ->toArray();
    }


    public function clearAll()
    {
        $this->selectedEmployees = [];
    }


    #[On('filter-employees')]
    public function filteredEmployees()
    {
        $searchTerm = strtolower($this->searchTerm);

        if (empty($searchTerm)) {
            // If empty, return the full, original list and stop.
            $this->employees = $this->originalEmployees;
            return;
        }

        $filteredList = collect($this->originalEmployees)->filter(function ($emp) use ($searchTerm) {
            $departmentName = $emp['department']['name'] ?? '';

            $nameMatch = stripos($emp['name'], $searchTerm) !== false;
            $departmentMatch = stripos($departmentName, $searchTerm) !== false;

            return $nameMatch || $departmentMatch;
        });

        $this->employees = $filteredList->toArray();
    }


    public function calculateWorkingDays()
    {
        // ... (existing calculate logic) ...
        if (!$this->startDate || !$this->endDate) return 0;

        if (!empty($this->leaveType)) {
            $this->getSelectedLeaveType(); // Ensure selectedLeaveType is set
            return $this->getSelectedLeaveType()->calculateNumberOfDaysFromLeaveStartAndEndDates(
                Carbon::parse($this->startDate),
                Carbon::parse($this->endDate)
                )['effective_leave_days'];
        }
        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);
        return $start->diffInDays($end) + 1;
    }

    public function getSelectedLeaveTypeProperty()
    {
        // ... (existing selected leave type logic) ...
        return collect($this->leaveTypes)->firstWhere('id', $this->leaveType);
    }
    public function getSelectedLeaveType()
    {
        if (($this->selectedLeaveType == null || $this->selectedLeaveType->code !== $this->leaveType) && !empty($this->leaveType)) {
            $this->selectedLeaveType = LeaveType::where('code', $this->leaveType)->first();
        }
        
        return $this->selectedLeaveType;
    }

    private function getConflictingEmployees(array $employeeIds, string $startDate, string $endDate): array
    {
        $conflictingLeaveIds = Leave::whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                });
            })
            ->pluck('employee_id')
            ->toArray();

        $conflictingOffShiftIds = Employee::whereIn('id', $employeeIds)
            ->whereIn('shift_status', ['off_shift', 'sick_off'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_off_shift_date', '<=', $endDate)
                        ->where('end_off_shift_date', '>=', $startDate);
                });
            })
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($conflictingLeaveIds, $conflictingOffShiftIds));
    }

    public function getSelectedLeaveTypeRemainingDays() {
        
        $num_of_days = $this->calculateWorkingDays();

        $service = app(LeaveApprovalService::class);
        $employeeId = $this->selectedEmployees[0] ?? null; // Assuming you want to check for the first selected employee

        if (!$employeeId || !$this->selectedLeaveType) {
            return null; // Return null if no employee is selected or leave type is not set
        }

        $employee = Employee::find($employeeId);

        $chosenLeaveType = $this->getSelectedLeaveType();

        if (!$chosenLeaveType) {
            return null; // Return null if the leave type is not found
        }

        $remaining = $service->checkBalance($employee, $chosenLeaveType, (float)$num_of_days, Carbon::parse($this->startDate)->year)['remaining'] ?? null;

        return $remaining;

    }

    public function getLeaveBalanceProjectionProperty(): ?array
    {
        if (count($this->selectedEmployees) !== 1 || empty($this->leaveType) || empty($this->startDate)) {
            return null;
        }

        $employee = Employee::find($this->selectedEmployees[0]);
        $leaveType = $this->getSelectedLeaveType();

        if (!$employee || !$leaveType) {
            return null;
        }

        $year = Carbon::parse($this->startDate)->year;

        $balanceRow = collect(app(LeaveApprovalService::class)->balancesForEmployee($employee, $year))
            ->firstWhere('code', $this->leaveType);

        if (!$balanceRow) {
            return null;
        }

        $requestedDays = $this->durationType === 'dateRange'
            ? (($this->startDate && $this->endDate) ? (float) $this->calculateWorkingDays() : 0.0)
            : (float) ($this->numberOfDays ?: 0);

        $remainingBefore = $balanceRow['remaining_days'];

        return [
            'employee_name' => $employee->name,
            'leave_type_name' => $balanceRow['name'] ?? $leaveType->name,
            'accrued' => $balanceRow['entitled_days'],
            'used' => $balanceRow['used_days'],
            'requested' => $requestedDays,
            'remaining' => $remainingBefore === null ? null : $remainingBefore - $requestedDays,
        ];
    }


    public function confirmLeave()
    {
        try {
            DB::beginTransaction();
            $this->validate($this->rules());

            $orgId = auth()->user()->employee->organization_id ?? null;

            $employeesToAssign = collect($this->employees)->whereIn('id', $this->selectedEmployees);
            $totalSelected = $employeesToAssign->count();
            $count = 0;
            $message = '';

            if ($this->durationType === 'numberOfDays') {
                $start = Carbon::parse($this->startDate);

                $end = $this->getSelectedLeaveType()->calculateEndDateWithStartDateAndNumberOfDays($start, (int) $this->numberOfDays)['end_date'];
                //$end = $start->copy()->addDays($this->numberOfDays - 1);
                $endDate = $end->format('Y-m-d');
            } else {
                $endDate = $this->endDate;
            }

            // set end date for num of days calculation
            $this->endDate = $endDate;

            // now check leave bala
            if (count($this->selectedEmployees) > 0 && $this->leaveType !== 'offshift' && $this->leaveType !== 'sick') {
                $remainingDays = $this->getSelectedLeaveTypeRemainingDays();
                if ($remainingDays < $this->calculateWorkingDays()) {
                    throw new \Exception("Insufficient leave balance for the selected leave type. Remaining days: {$remainingDays}, Requested days: {$this->calculateWorkingDays()}");
                }
            }

            // we add 2 to the end date to account for the resumption date being the day after the leave ends
            $resumptionDate = $this->getSelectedLeaveType()->calculateEndDateWithStartDateAndNumberOfDays(Carbon::parse($this->endDate), 2)['end_date'];

            $selectedEmployeeIds = $employeesToAssign->pluck('id')->toArray();
            $conflictingIds = $this->getConflictingEmployees($selectedEmployeeIds, $this->startDate, $endDate);

            $employeesToProcess = $employeesToAssign->whereNotIn('id', $conflictingIds);
            $conflictingCount = count($conflictingIds);

            if ($employeesToProcess->isEmpty()) {
                if ($totalSelected > 0) {
                    throw new \Exception("All {$totalSelected} employees have conflicting leave or off-shift statuses for the requested dates.");
                }
                DB::rollBack();
                return;
            }

            $leaveTypeDetails = $this->getSelectedLeaveTypeProperty();
            $leaveTypeName = $leaveTypeDetails['name'] ?? $this->leaveType;
            $leaveTypeModel = LeaveType::where('organization_id', $orgId)->where('code', $this->leaveType)->first();

            if ($this->leaveType === 'offshift') {

                $employeeIdsToUpdate = $employeesToProcess->pluck('id')->toArray();

                Employee::whereIn('id', $employeeIdsToUpdate)->update([
                    'shift_status' => 'off_shift',
                    'start_off_shift_date' => $this->startDate,
                    'end_off_shift_date' => $endDate,
                ]);

                $count = count($employeeIdsToUpdate);
                $message = "Shift status for {$count} employees updated to 'Off Shift'.";

                // Commit changes
                DB::commit();

                // Show success alert and trigger redirect after the toast
                LivewireAlert::title('Awesome!')
                    ->text($message)
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();

                $orgId = auth()->user()->employee->organization_id ?? null;
                app(AttendanceSeeder::class)->seedMissingAttendanceRecords($orgId);

                // Emit event to other Livewire components
                $this->dispatch('redirect', ['url' => route('attendance.index', ['filterStatus' => 'off_shift'])]);


            } elseif ($this->leaveType === 'sick') {

                $employeeIdsToUpdate = $employeesToProcess->pluck('id')->toArray();

                Employee::whereIn('id', $employeeIdsToUpdate)->update([
                    'shift_status' => 'sick_off',
                    'start_off_shift_date' => $this->startDate,
                    'end_off_shift_date' => $endDate,
                ]);

                $count = count($employeeIdsToUpdate);
                $message = "Shift status for {$count} employees updated to 'Sick Off'.";

                // Commit changes
                DB::commit();

                // Show success alert and trigger redirect after the toast
                LivewireAlert::title('Awesome!')
                    ->text($message)
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();

                $orgId = auth()->user()->employee->organization_id ?? null;
                app(AttendanceSeeder::class)->seedMissingAttendanceRecords($orgId);

                // Trigger redirect after showing the success message
                $this->dispatch('redirect', ['url' => route('attendance.index', ['filterStatus' => 'sick_off'])]);


            } else {

                foreach ($employeesToProcess as $employee) {
                    $departmentId = $employee['department_id'] ?? null;

                    $data = [
                        'organization_id' => $orgId,
                        'employee_id' => $employee['id'],
                        'department_id' => $departmentId,
                        'leave_type' => $leaveTypeName,
                        'leave_type_id' => $leaveTypeModel?->id,
                        'start_date' => $this->startDate,
                        'end_date' => $endDate,
                        'num_of_days' => $this->calculateWorkingDays(),
                        'reason' => $this->reason,
                        'contact_during_leave' => $this->contact_during_leave ?? null,
                        'emergency_contact' => $this->emergency_contact ?? null,
                        'handover_to' => $this->handover_to ?? null,
                        'expected_resumption' => $resumptionDate,
                        'status' => 'pending',
                    ];

                    $leave = Leave::create($data);
                    app(LeaveApprovalService::class)->createApprovalChain($leave);
                    $count++;
                }

                $message = "Leave successfully assigned to {$count} employees. All requests are pending review.";

                // Commit changes
                DB::commit();

                // Show success alert and trigger redirect after the toast
                LivewireAlert::title('Awesome!')
                    ->text($message)
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();

                // Trigger redirect after showing the success message
                $this->dispatch('redirect', ['url' => route('leaves.index')]);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            LivewireAlert::title('Validation Error!')
                ->text('Please check the highlighted fields in the form.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        } catch (\Exception $e) {
            DB::rollBack();

            LivewireAlert::title('Oops!')
                ->text('Something went wrong: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

}; ?>

@push('styles')
    <style>
        /* ... (Your existing custom CSS here) ... */
        .stepper-wrapper {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
            padding: 0 4rem; /* Add padding for lines to connect steps */
        }

        .stepper-wrapper::before {
            content: '';
            position: absolute;
            top: 1.25rem; /* Center vertical on the circle */
            left: 4.5rem;
            right: 4.5rem;
            height: 3px;
            background-color: #dee2e6; /* Gray line */
            z-index: 1;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 2; /* Over the line */
        }

        .step-counter {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: #6c757d; /* Default gray */
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 0.5rem;
            transition: background-color 0.3s;
        }

        .step-name {
            font-size: 0.875rem;
            color: #6c757d;
            text-align: center;
        }

        .step-item.completed .step-counter {
            background-color: #0d6efd; /* Blue */
        }

        .step-item.active .step-counter {
            background-color: #0d6efd; /* Blue */
            border: 3px solid #0d6efd;
        }

        .step-item.completed .step-name,
        .step-item.active .step-name {
            color: #0d6efd;
            font-weight: bold;
        }


        /* Custom CSS for Employee Cards */
        .employee-card {
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.5rem; /* Space between cards */
        }

        .employee-card:hover {
            background-color: #f8f9fa;
            border-color: #adb5bd;
        }

        .employee-card.selected {
            border-color: #0d6efd; /* Primary blue border */
            background-color: #eaf1fb; /* Light blue background */
        }

        .avatar-circle {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .employee-list-scroll {
            max-height: 400px; /* Limits the height for scrolling */
            overflow-y: auto;
            padding-right: 0.5rem; /* Space for scrollbar */
        }

        /* Custom CSS for Leave Type Buttons */
        .btn-leave-type {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 1rem;
            border: 1px solid #dee2e6;
            background-color: white;
            color: #212529;
            text-align: left;
        }

        .btn-leave-type:hover {
            border-color: #0d6efd;
        }

        .btn-leave-type.active {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
            background-color: #eaf1fb;
            color: #0d6efd;
        }

        .balance-projection-card {
            border: 1px solid #e6ebf2;
            border-radius: 0.6rem;
            padding: 1rem 1.25rem;
            background-color: #F9FAFC;
        }

        .balance-projection-kicker {
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
        }

        .balance-projection-meta {
            color: #2563eb;
            font-weight: 600;
        }

        .balance-projection-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto 1fr;
            gap: 0.75rem;
            align-items: end;
            border-top: 1px solid #edf2f7;
            margin-top: 0.9rem;
            padding-top: 0.9rem;
        }

        .balance-projection-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            line-height: 1.2;
        }

        .balance-projection-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #1f2937;
        }

        .balance-projection-value.requested {
            color: #ef4444;
        }

        .balance-projection-value.remaining {
            color: #10b981;
        }

        .balance-projection-op {
            font-size: 1.35rem;
            font-weight: 700;
            color: #cbd5e1;
            line-height: 1;
            padding-bottom: 0.2rem;
        }

        @media (max-width: 767.98px) {
            .balance-projection-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .balance-projection-op {
                display: none;
            }

            .balance-projection-value {
                font-size: 1.75rem;
            }
        }
    </style>
@endpush

<div class="container my-5">

    <livewire:admin.system-settings.bread-crumb
        title="Absence & Leave Management"
        :items="[
        [
         'label' => 'Dashboard',
         'url' => route('dashboard'),
         'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
       ],
       [
         'label' => 'Create Leave',
         'icon' => '<iconify-icon icon=\'mdi:exit-run\' class=\'fs-5\'></iconify-icon>',
       ],
    ]"
    />

    <div class="stepper-wrapper mb-4">
        <div class="step-item {{ $step >= 1 ? 'completed' : '' }} {{ $step == 1 ? 'active' : '' }}">
            <div class="step-counter">@if($step > 1) &check; @else
                    1
                @endif</div>
            <div class="step-name">Select Employees</div>
        </div>
        <div class="step-item {{ $step >= 2 ? 'completed' : '' }} {{ $step == 2 ? 'active' : '' }}">
            <div class="step-counter">@if($step > 2) &check; @else
                    2
                @endif</div>
            <div class="step-name">Configure Details</div>
        </div>
        <div class="step-item {{ $step >= 3 ? 'completed' : '' }} {{ $step == 3 ? 'active' : '' }}">
            <div class="step-counter">3</div>
            <div class="step-name">Review & Confirm</div>
        </div>
    </div>

    <div class="card shadow-sm p-4">

        @if($step === 1)
            <h2 class="h5 font-weight-bold mb-4 text-primary">Select Employees for Absence/Leave</h2>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="text-muted mb-0">{{ count($selectedEmployees) }} selected</p>
                <div>
                    <button wire:click="selectAll" class="btn btn-sm btn-link text-primary">Select All</button>
                    <button wire:click="clearAll" class="btn btn-sm btn-link text-secondary">Clear All</button>
                </div>
            </div>

            {{-- Search --}}
            <div class="input-group mb-4">
                <input type="text" wire:keyup="$dispatch('filter-employees')" wire:model="searchTerm"
                       placeholder="Search by name or department..."
                       class="form-control" style="padding-left: 2.5rem;">
                <span class="input-group-text position-absolute border-0 bg-transparent"
                      style="left: 0.5rem; top: 50%; transform: translateY(-50%); z-index: 10;">
                    🔍
                </span>
            </div>

            <div class="list-group employee-list-scroll mb-4">
                @foreach($employees as $emp)

                    @php
                        $nameParts = explode(' ', $emp['name']);
                        $initials = strtoupper(
                            substr($nameParts[0], 0, 1) .
                            (isset($nameParts[1]) ? substr(end($nameParts), 0, 1) : '')
                        );
                        // Determine if the employee is currently unavailable
                        $isDisabled = isset($emp['current_status_badge']) && $emp['current_status_badge'];
                    @endphp

                    <div wire:key="emp-card-{{ $emp['id'] }}"
                         @if(!$isDisabled) wire:click="toggleEmployee({{ $emp['id'] }})" @endif
                         class="list-group-item list-group-item-action employee-card
                         @if(in_array($emp['id'], $selectedEmployees)) selected @endif
                         @if($isDisabled) disabled bg-light text-muted @endif">

                        <input type="checkbox" class="form-check-input me-3"
                               {{ in_array($emp['id'], $selectedEmployees) ? 'checked' : '' }}
                               @if($isDisabled) disabled @endif readonly>

                        <div class="avatar-circle me-3">{{ $initials }}</div>

                        <div class="flex-grow-1">
                            <div class="font-weight-bold">{{ $emp['name'] }}</div>
                            <small class="text-muted">{{ $emp['department']['name'] ?? 'N/A' }}</small>
                        </div>

                        @if($isDisabled)
                            <div class="ms-3">{!! $emp['current_status_badge'] !!}</div>
                        @endif

                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-end">
                <button wire:click="$set('step',2)"
                        class="btn btn-primary btn-md"
                    {{ count($selectedEmployees) === 0 ? 'disabled' : '' }}>
                    Continue to Details
                    <iconify-icon icon="mdi:arrow-right" class="fs-7 align-middle ms-1"></iconify-icon>
                </button>
            </div>

        @elseif($step === 2)
            <h2 class="h5 font-weight-bold mb-4 text-primary">Configure Absence/Leave Details</h2>

            <div class="mb-4">
                <label class="form-label font-weight-bold">Type of Absence/Leave</label>
                <div class="row g-3">
                    @foreach($leaveTypes as $type)
                        <div class="col-6 col-md-3">
                            <button wire:click="$set('leaveType', '{{ $type['id'] }}')"
                                    class="btn btn-leave-type w-100 @if($leaveType === $type['id']) active @endif"
                                    title="{{ $type['name'] }}">
                                <span class="h4 me-2">{{ $type['icon'] }}</span>
                                <span class="d-none d-md-inline">{{ $type['name'] }}</span>
                                <span class="d-md-none">{{ $type['name'] }}</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label font-weight-bold">Duration</label>
                <div class="btn-group w-100 mb-3" role="group">
                    <button wire:click="$set('durationType', 'dateRange')"
                            class="btn  @if($durationType === 'dateRange') btn-primary @else btn-outline-primary @endif">
                        📅 Date Range
                    </button>
                    <button wire:click="$set('durationType', 'numberOfDays')"
                            class="btn  @if($durationType === 'numberOfDays') btn-primary @else btn-outline-primary @endif">
                        🔢 Number of Days
                    </button>
                </div>

                <div class="row g-3">
                    @if($durationType === 'dateRange')
                        <div class="col-md-6" x-data x-init="$nextTick(() => initLeaveDatepickers())">
                            <label for="leaveStartDate" class="form-label">Start Date</label>
                            <input type="text" id="leaveStartDate" wire:model.live="startDate" class="form-control" autocomplete="off">
                            @error('startDate') <small class="text-primary">{{ $message }}</small>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="leaveEndDate" class="form-label">End Date</label>
                            <input type="text" id="leaveEndDate" wire:model.live="endDate" class="form-control" autocomplete="off">
                            @error('endDate') <small class="text-primary">{{ $message }}</small>@enderror
                            @if($startDate && $endDate)
                                <small class="text-muted mt-1 d-block">Total: {{ $this->calculateWorkingDays() }}
                                    days</small>
                            @endif
                        </div>
                    @else
                        <div class="col-md-6">
                            <label for="numberOfDays" class="form-label">Number of Days</label>
                            <input type="number" id="numberOfDays" wire:model.live="numberOfDays" min="1"
                                   class="form-control">
                            @error('numberOfDays') <small class="text-primary">{{ $message }}</small>@enderror
                        </div>
                        <div class="col-md-6" x-data x-init="$nextTick(() => initLeaveDatepickers())">
                            <label for="leaveStartDate" class="form-label">Starting From</label>
                            <input type="text" id="leaveStartDate" wire:model.live="startDate" class="form-control" autocomplete="off">
                            @error('startDate') <small class="text-primary">{{ $message }}</small>@enderror
                        </div>
                    @endif
                </div>
            </div>
            <h3 class="h6 font-weight-bold mb-3">Additional Details (Optional)</h3>
            <div class="row g-3">
                <div class="col-12">
                    <label for="reason" class="form-label font-weight-bold">Reason / Notes</label>
                    <textarea id="reason" rows="3" wire:model="reason" class="form-control"
                              placeholder=""></textarea>
                    @error('reason') <small class="text-primary">{{ $message }}</small>@enderror
                </div>

                <div class="col-md-4">
                    <label for="contact_during_leave" class="form-label">Contact During Leave</label>
                    <input type="text" id="contact_during_leave" wire:model="contact_during_leave" class="form-control">
                </div>

                <div class="col-md-4">
                    <label for="emergency_contact" class="form-label">Emergency Contact</label>
                    <input type="text" id="emergency_contact" wire:model="emergency_contact" class="form-control">
                </div>

                <div class="col-md-4">
                    <label for="handover_to" class="form-label">Handover To (Colleague Name)</label>
                    <input type="text" id="handover_to" wire:model="handover_to" class="form-control">
                </div>
            </div>

            @if(count($selectedEmployees) === 1)
                @php
                    $projection = $this->leaveBalanceProjection;
                @endphp

                @if($projection)
                    <div class="balance-projection-card mt-4">
                        <div class="balance-projection-kicker">Balance Projection</div>
                        <div class="balance-projection-meta">
                            {{ $projection['employee_name'] }} • {{ $projection['leave_type_name'] }}
                        </div>

                        <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-md-4 mt-1">
                            <div class="col">
                                <div class="card h-75">
                                    <div class="card-body p-3">
                                        <div class="card-title text-center balance-projection-label mb-2">Accrued</div>
                                        <div class="text-center balance-projection-value">{{ $projection['accrued'] ?? '∞' }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col">
                                <div class="card h-75">
                                    <div class="card-body p-3">
                                        <div class="card-title text-center balance-projection-label mb-2">Used</div>
                                        <div class="text-center balance-projection-value">{{ $projection['used'] ?? '∞' }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col">
                                <div class="card h-75">
                                    <div class="card-body p-3">
                                        <div class="card-title text-center balance-projection-label mb-2">Requested</div>
                                        <div class="text-center balance-projection-value requested">{{ $projection['requested'] }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col">
                                <div class="card h-75">
                                    <div class="card-body p-3">
                                        <div class="card-title text-center balance-projection-label mb-2">Remaining</div>
                                        <div class="text-center balance-projection-value remaining">{{ $projection['remaining'] ?? '∞' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <div class="d-flex justify-content-between mt-4">
                <button wire:click="$set('step',1)" class="btn btn-outline-primary btn-md">
                    <iconify-icon icon="mdi:arrow-left" class="fs-5 align-middle me-1"></iconify-icon>
                    Back
                </button>
                <button wire:click="$set('step',3)"
                        class="btn btn-primary btn-md"
                        @if(empty($leaveType) || empty($startDate) || ($durationType === 'dateRange' && empty($endDate)) || ($durationType === 'numberOfDays' && empty($numberOfDays))) disabled @endif>
                    Review
                    <iconify-icon icon="mdi:arrow-right" class="fs-5 align-middle ms-1"></iconify-icon>
                </button>
            </div>

        @elseif($step === 3)
            <h2 class="h5 font-weight-bold mb-4 text-primary">Review & Confirm</h2>

            <div class="card bg-light p-3 mb-4">
                @php
                    $this->getSelectedLeaveType(); // Ensure selectedLeaveType is set

                    $this->getSelectedLeaveTypeRemainingDays();
                @endphp
                <h3 class="h6 font-weight-bold mb-3">Absence/Leave Assignment Summary</h3>
                <div class="row g-2">
                    <div class="col-6"><span class="font-weight-bold">Type:</span></div>
                    <div class="col-6 text-end">{{ $this->selectedLeaveType['name'] ?? 'N/A' }}</div>

                    <div class="col-6"><span class="font-weight-bold">Duration:</span></div>
                    <div class="col-6 text-end">
                        @if($durationType === 'dateRange')
                            {{ $this->calculateWorkingDays() }} days
                            starting {{ \Carbon\Carbon::parse($startDate)->format('Y-m-d') }}
                        @else
                            {{ $numberOfDays }} days starting {{ \Carbon\Carbon::parse($startDate)->format('Y-m-d') }}
                        @endif
                    </div>

                    <div class="col-6"><span class="font-weight-bold">Total Employees:</span></div>
                    <div class="col-6 text-end text-primary font-weight-bold">{{ count($selectedEmployees) }}</div>

                    <div class="col-6"><span class="font-weight-bold">Total Days Impact:</span></div>
                    <div class="col-6 text-end text-primary font-weight-bold">
                        @php
                            $days = ($durationType === 'dateRange') ? $this->calculateWorkingDays() : $numberOfDays;
                        @endphp
                        {{ $days * count($selectedEmployees) }} employee-days
                    </div>
                </div>
            </div>

            <h3 class="h6 font-weight-bold mb-3">Selected Employees ({{ count($selectedEmployees) }})</h3>
            <div class="list-group employee-list-scroll mb-4" style="max-height: 150px;">
                @foreach(collect($employees)->whereIn('id', $selectedEmployees) as $emp)
                    @php
                        $nameParts = explode(' ', $emp['name']);
                        $initials = strtoupper(
                            substr($nameParts[0], 0, 1) .
                            (isset($nameParts[1]) ? substr(end($nameParts), 0, 1) : '')
                        );
                    @endphp
                    <div class="list-group-item d-flex align-items-center">
                        <div class="avatar-circle me-3">{{ $initials }}</div>
                        <div>
                            <div class="font-weight-bold">{{ $emp['name'] }}</div>
                            <small class="text-muted">{{ $emp['department']['name'] ?? 'N/A' }}</small>
                        </div>
                    </div>
                @endforeach
            </div>

            {{--            <div class="form-check mb-2">--}}
            {{--                <input class="form-check-input" type="checkbox" wire:model="sendNotifications" id="sendNotifications">--}}
            {{--                <label class="form-check-label" for="sendNotifications">--}}
            {{--                    Send notification to affected employees--}}
            {{--                </label>--}}
            {{--            </div>--}}
            {{--            <div class="form-check mb-4">--}}
            {{--                <input class="form-check-input" type="checkbox" wire:model="notifyManagers" id="notifyManagers">--}}
            {{--                <label class="form-check-label" for="notifyManagers">--}}
            {{--                    Notify department managers--}}
            {{--                </label>--}}
            {{--            </div>--}}

            <div class="d-flex justify-content-between mt-4">
                <button wire:click="$set('step',2)" class="btn btn-outline-primary btn-md">
                    <iconify-icon icon="mdi:arrow-left" class="fs-5 align-middle me-1"></iconify-icon>
                    Back
                </button>
                <button wire:click="confirmLeave" class="btn btn-primary btn-md">
                    <iconify-icon icon="mdi:check-circle-outline" class="fs-5 align-middle ms-1"></iconify-icon>
                    Confirm
                </button>
            </div>
        @endif
    </div>
</div>

@push('scripts')
    <script>function initLeaveDatepickers() {
            const $startInput = $('#leaveStartDate');
            const $endInput = $('#leaveEndDate');

            if (typeof $.fn.datepicker === 'undefined') {
                return;
            }

            const setLivewireDateValue = (input, field, value) => {
                const componentRoot = input.closest('[wire\\:id]');
                const componentId = componentRoot?.getAttribute('wire:id');

                if (!componentId || !window.Livewire || typeof window.Livewire.find !== 'function') {
                    return;
                }

                const component = window.Livewire.find(componentId);
                if (component && typeof component.set === 'function') {
                    component.set(field, value);
                }
            };

            const syncDateValue = ($input, value) => {
                $input.val(value);
                $input.trigger('input');
                $input.trigger('change');
            };

            // Start and end inputs never coexist for the 'numberOfDays' duration, so each is guarded independently.
            if ($startInput.length) {
                if ($startInput.data('datepicker')) {
                    $startInput.datepicker('destroy');
                }

                const startValue = $startInput.val();

                $startInput.datepicker({
                    format: 'yyyy-mm-dd',
                    autoclose: true,
                    todayHighlight: true,
                }).on('changeDate', function (e) {
                    const selected = e.format('yyyy-mm-dd');
                    syncDateValue($startInput, selected);
                    setLivewireDateValue($startInput[0], 'startDate', selected);

                    if ($endInput.length) {
                        $endInput.datepicker('setStartDate', selected);

                        if ($endInput.val() && $endInput.val() < selected) {
                            syncDateValue($endInput, selected);
                            $endInput.datepicker('update', selected);
                            setLivewireDateValue($endInput[0], 'endDate', selected);
                        }
                    }
                });

                if (startValue) {
                    $startInput.datepicker('update', startValue);
                }
            }

            if ($endInput.length) {
                if ($endInput.data('datepicker')) {
                    $endInput.datepicker('destroy');
                }

                const endValue = $endInput.val();

                $endInput.datepicker({
                    format: 'yyyy-mm-dd',
                    autoclose: true,
                    todayHighlight: true,
                }).on('changeDate', function (e) {
                    const selected = e.format('yyyy-mm-dd');
                    syncDateValue($endInput, selected);
                    setLivewireDateValue($endInput[0], 'endDate', selected);
                });

                if ($startInput.length && $startInput.val()) {
                    $endInput.datepicker('setStartDate', $startInput.val());
                }

                if (endValue) {
                    $endInput.datepicker('update', endValue);
                }
            }
        }

        document.addEventListener('livewire:navigated', initLeaveDatepickers);
        initLeaveDatepickers();


        window.addEventListener('redirect', event => {
            console.log(event);  // This will show the event object
            const url = event.detail[0].url;  // Access the first element of the detail array

            // Redirect the user to the URL
            if (url) {
                window.location.href = url;
            } else {
                console.error('URL not provided in the event detail');
            }
        });
    </script>
@endpush

