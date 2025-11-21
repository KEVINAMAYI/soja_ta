<?php

use App\Models\Leave;
use App\Models\Employee;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
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
    public $leaveTypes = [
        ['id' => 'sick', 'name' => 'Sick Off', 'icon' => '🤒'],
        ['id' => 'offshift', 'name' => 'Off Shift', 'icon' => '🌙'],
        ['id' => 'annual', 'name' => 'Annual Leave', 'icon' => '🏖️'],
        ['id' => 'maternity', 'name' => 'Maternity Leave', 'icon' => '🤰'],
        ['id' => 'paternity', 'name' => 'Paternity Leave', 'icon' => '👨‍🍼'],
        ['id' => 'compassionate', 'name' => 'Compassionate Leave', 'icon' => '🕯️'],
        ['id' => 'study', 'name' => 'Study Leave', 'icon' => '📚'],
        ['id' => 'unpaid', 'name' => 'Unpaid Leave', 'icon' => '💸'],
        ['id' => 'personal', 'name' => 'Personal Leave', 'icon' => '👤'],
    ];

    // --- Properties for New Form Fields (Optional, but included for complete logic) ---
    public $reason = '';
    public $contact_during_leave = '';
    public $emergency_contact = '';
    public $handover_to = '';


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
        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);
        return $start->diffInDays($end) + 1;
    }

    public function getSelectedLeaveTypeProperty()
    {
        // ... (existing selected leave type logic) ...
        return collect($this->leaveTypes)->firstWhere('id', $this->leaveType);
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
                $end = $start->copy()->addDays($this->numberOfDays - 1);
                $endDate = $end->format('Y-m-d');
            } else {
                $endDate = $this->endDate;
            }

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

                // Trigger redirect after showing the success message
                $this->dispatch('redirect', ['url' => route('attendance.status.index', ['status' => 'off_shift'])]);

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

                // Trigger redirect after showing the success message
                $this->dispatch('redirect', ['url' => route('attendance.status.index', ['status' => 'sick_off'])]);

            } else {

                foreach ($employeesToProcess as $employee) {
                    $departmentId = $employee['department_id'] ?? null;

                    $data = [
                        'organization_id' => $orgId,
                        'employee_id' => $employee['id'],
                        'department_id' => $departmentId,
                        'leave_type' => $leaveTypeName,
                        'start_date' => $this->startDate,
                        'end_date' => $endDate,
                        'reason' => $this->reason,
                        'contact_during_leave' => $this->contact_during_leave ?? null,
                        'emergency_contact' => $this->emergency_contact ?? null,
                        'handover_to' => $this->handover_to ?? null,
                        'expected_resumption' => Carbon::parse($endDate)->addDay()->format('Y-m-d'),
                        'status' => 'pending',
                    ];

                    Leave::create($data);
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

                    <div @if(!$isDisabled) wire:click="toggleEmployee({{ $emp['id'] }})" @endif
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
                        <div class="col-md-6">
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" id="startDate" wire:model.live="startDate" class="form-control">
                            @error('startDate') <small class="text-primary">{{ $message }}</small>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="endDate" class="form-label">End Date</label>
                            <input type="date" id="endDate" wire:model.live="endDate" class="form-control">
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
                        <div class="col-md-6">
                            <label for="startingFrom" class="form-label">Starting From</label>
                            <input type="date" id="startingFrom" wire:model.live="startDate" class="form-control">
                            @error('startDate') <small class="text-primary">{{ $message }}</small>@enderror
                        </div>
                    @endif
                </div>
            </div>

            <hr class="my-4">

            <h3 class="h6 font-weight-bold mb-3">Additional Details</h3>
            <div class="row g-3">
                <div class="col-12">
                    <label for="reason" class="form-label font-weight-bold">Reason / Notes</label>
                    <textarea id="reason" rows="3" wire:model="reason" class="form-control"
                              placeholder=""></textarea>
                    @error('reason') <small class="text-primary">{{ $message }}</small>@enderror
                </div>

                {{--                <div class="col-md-4">--}}
                {{--                    <label for="contact_during_leave" class="form-label">Contact During Leave</label>--}}
                {{--                    <input type="text" id="contact_during_leave" wire:model="contact_during_leave" class="form-control">--}}
                {{--                </div>--}}

                {{--                <div class="col-md-4">--}}
                {{--                    <label for="emergency_contact" class="form-label">Emergency Contact</label>--}}
                {{--                    <input type="text" id="emergency_contact" wire:model="emergency_contact" class="form-control">--}}
                {{--                </div>--}}

                {{--                <div class="col-md-4">--}}
                {{--                    <label for="handover_to" class="form-label">Handover To (Colleague Name)</label>--}}
                {{--                    <input type="text" id="handover_to" wire:model="handover_to" class="form-control">--}}
                {{--                </div>--}}
            </div>

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
                    Confirm
                    <iconify-icon icon="mdi:check-circle-outline" class="fs-5 align-middle ms-1"></iconify-icon>
                </button>
            </div>
        @endif
    </div>
</div>

@push('scripts')
    <script>
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

