<?php

use App\Models\Employee;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $employeeId;
    public $shiftStatus;

    // Mount method to initialize the component's state
    public function mount($employeeId, $shiftStatus)
    {
        $this->employeeId = $employeeId;
        $this->shiftStatus = $shiftStatus;
    }

    // Toggle the shift status between 'on_shift' and 'off_shift'
    public function toggleShiftStatus()
    {
        // Find the employee by ID
        $employee = Employee::find($this->employeeId);

        if ($employee) {
            // Toggle the shift status between 'on_shift' and 'off_shift'
            $employee->shift_status = $this->shiftStatus === 'on_shift' ? 'off_shift' : 'on_shift';
            $employee->save();

            // Update the component state
            $this->shiftStatus = $employee->shift_status;
        }
    }

    // Event handler for refreshing the shift status
    #[On('refresh-status')]
    public function refreshShiftStatusToggle($employee)
    {
        // Update the component state with the new employee data
        $this->employeeId = $employee['id'];
        $this->shiftStatus = $employee['shift_status'];
    }

} ?>

@push('styles')
    <style>
        /* Label and overall container */
        .shift-toggle-label {
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        /* Text based on the shift status */
        .shift-status-text {
            font-size: 0.875rem;
            color: #333;
        }

        /* Hidden checkbox */
        .shift-toggle-checkbox {
            display: none;
        }

        /* Custom toggle switch (container) */
        .shift-toggle-slider {
            width: 45px;
            height: 24px;
            background-color: #d6d6d6; /* Default gray */
            border-radius: 50px;
            position: relative;
            transition: background-color 0.3s ease-in-out;
        }

        /* The circle inside the toggle */
        .shift-toggle-circle {
            width: 18px;
            height: 18px;
            background-color: white;
            border-radius: 50%;
            position: absolute;
            top: 3px;
            left: 3px;
            transition: transform 0.3s ease-in-out;
        }

        /* Styles for when the switch is ON (green) */
        .shift-toggle-checkbox:checked + .shift-toggle-slider {
            background-color: #28a745; /* Green */
        }

        .shift-toggle-checkbox:checked + .shift-toggle-slider .shift-toggle-circle {
            transform: translateX(21px);
        }

        /* Styles for when the switch is OFF (red) */
        .shift-toggle-checkbox:not(:checked) + .shift-toggle-slider {
            background-color: #dc3545; /* Red */
        }
    </style>
@endpush

<div>
    <label for="shiftStatus" class="shift-toggle-label">
        <span class="shift-status-text">{{ $shiftStatus === 'on_shift' ? 'On Shift' : 'Off Shift' }}</span>

        <!-- Use wire:model to bind shiftStatus to the checkbox state -->
        <input type="checkbox" id="shiftStatus" wire:model="shiftStatus" wire:click="toggleShiftStatus"
               {{ $shiftStatus === 'on_shift' ? 'checked' : '' }} class="shift-toggle-checkbox"/>

        <!-- The custom switch itself -->
        <div class="shift-toggle-slider">
            <div class="shift-toggle-circle"></div>
        </div>
    </label>
</div>






