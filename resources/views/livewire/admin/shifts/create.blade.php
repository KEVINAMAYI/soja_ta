<?php

use App\Models\Employee;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Shift;
use App\Models\OrganizationShiftSetting;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public $shifts = [];
    public $selectedShift = [];
    public $showPatternModal = false;
    public $showAddShift = false;
    public $showMultiShiftModal = false;
    public string $activeShiftTab = 'shift_settings';
    public string $tabTitle;
    public string $tabIcon;

    // Multi-shift assignment (ONLY system now)
    public $assignedStaffIds = [];
    public $availableStaff = [];
    public $assignedStaff = [];
    public $assigningShift;
    public $searchTerm = '';

    // Track pending changes
    public $pendingChanges = false;
    public $originalAssignedStaffIds = [];

    // Multi-shift modal
    public $selectedEmployeeForMultiShift = null;
    public $employeeMultiShifts = [];
    public $newShiftAssignment = [
        'shift_id' => null,
        'priority' => 0,
        'effective_from' => null,
        'effective_until' => null,
    ];

    // Organization settings
    public $orgSettings = null;

    public $shiftPatterns = [
        ['id' => 'weekdays', 'name' => 'Weekdays Only', 'description' => 'Monday to Friday'],
        ['id' => 'weekends', 'name' => 'Weekends Only', 'description' => 'Saturday and Sunday'],
        ['id' => 'daily', 'name' => 'Daily', 'description' => 'All 7 days of the week'],
        ['id' => 'rotating', 'name' => 'Rotating Schedule', 'description' => 'Custom rotation pattern'],
        ['id' => 'custom', 'name' => 'Custom Days', 'description' => 'Select specific days']
    ];

    public function rules()
    {
        return [
            'assignedStaffIds' => 'array',
            'assignedStaffIds.*' => 'exists:employees,id',
            'newShiftAssignment.shift_id' => 'required|exists:shifts,id',
            'newShiftAssignment.priority' => 'required|integer|min:0|max:999',
        ];
    }

    public $dayAbbreviations = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    public $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function mount()
    {
        $this->loadShifts();
        $this->loadOrgSettings(); // This loads the org settings


        if (count($this->shifts) > 0) {
            // ✅ FIX: Load from employee_shift_assignments pivot table
            $this->assignedStaffIds = DB::table('employee_shift_assignments')
                ->where('shift_id', $this->selectedShift['id'])
                ->where('is_active', true)
                ->pluck('employee_id')
                ->toArray();

            $this->originalAssignedStaffIds = $this->assignedStaffIds;
            $this->assigningShift = Shift::findOrFail($this->selectedShift['id']);

            $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
                ->with(['shifts'])
                ->get();
        }

        $this->searchEmployees();
    }

    public function loadOrgSettings()
    {
        $organizationId = auth()->user()->employee->organization_id;
        $this->orgSettings = OrganizationShiftSetting::getForOrganization($organizationId);

        // If no settings exist, create default ones
        if (!$this->orgSettings) {
            $this->orgSettings = OrganizationShiftSetting::create([
                'organization_id' => $organizationId,
                'allow_auto_shift_detection' => false,
                'allow_manual_shift_selection' => true,
                'require_approval_for_manual_shift_change' => false,
                'shift_change_cooldown_minutes' => 240,
                'auto_detection_minimum_score' => 40,
            ]);
        }
    }

    public function loadShifts()
    {
        $organizationId = auth()->user()->employee->organization_id;

        $dbShifts = Shift::where('organization_id', $organizationId)
            ->orderBy('created_at', 'asc')
            ->get();

        $this->shifts = $dbShifts->map(function ($shift) {
            // ✅ FIX: Count from employee_shift_assignments
            $employeesCount = DB::table('employee_shift_assignments')
                ->where('shift_id', $shift->id)
                ->where('is_active', true)
                ->count();

            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'startTime' => Carbon::parse($shift->start_time)->format('H:i'),
                'endTime' => Carbon::parse($shift->end_time)->format('H:i'),
                'duration' => (float)$shift->duration_hours,
                'overtimeEnabled' => $shift->overtime_enabled,
                'maxOvertime' => (float)$shift->max_overtime_hours,
                'autoClockOut' => $shift->auto_clock_out,
                'warningTime' => $shift->warning_time_minutes,
                'breakDuration' => $shift->break_minutes,
                'employees' => $employeesCount,
                'pattern' => $shift->pattern_type,
                'patternDays' => $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'notifyManagers' => $shift->notify_managers_overtime,
                'mobileNotifications' => $shift->employee_mobile_notifications,
                'emailSummaries' => $shift->email_summaries,
                'gracePeriodEnabled' => $shift->grace_period_enabled,
                'gracePeriodMinutes' => $shift->grace_period_minutes,
                'trackLateCheckin' => $shift->track_late_checkin,
                'notifyOnLateCheckin' => $shift->notify_on_late_checkin,
                'trackEarlyCheckout' => $shift->track_early_checkout,
                'earlyCheckoutThreshold' => $shift->early_checkout_threshold_minutes,
            ];
        })->toArray();

        if (count($this->shifts) > 0) {
            $this->selectedShift = $this->shifts[0];
        }
    }


    // ✅ FIXED: Add database save to toggle methods

    public function toggleAutoDetection()
    {
        if ($this->orgSettings) {
            $this->orgSettings->allow_auto_shift_detection = !$this->orgSettings->allow_auto_shift_detection;
            $this->orgSettings->save();

            LivewireAlert::title('Success!')
                ->text('Auto shift detection ' . ($this->orgSettings->allow_auto_shift_detection ? 'enabled' : 'disabled'))
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function toggleManualSelection()
    {
        if ($this->orgSettings) {
            $this->orgSettings->allow_manual_shift_selection = !$this->orgSettings->allow_manual_shift_selection;
            $this->orgSettings->save();

            LivewireAlert::title('Success!')
                ->text('Manual shift selection ' . ($this->orgSettings->allow_manual_shift_selection ? 'enabled' : 'disabled'))
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function toggleApprovalRequired()
    {
        if ($this->orgSettings) {
            $this->orgSettings->require_approval_for_manual_shift_change = !$this->orgSettings->require_approval_for_manual_shift_change;
            $this->orgSettings->save();

            LivewireAlert::title('Success!')
                ->text('Approval requirement ' . ($this->orgSettings->require_approval_for_manual_shift_change ? 'enabled' : 'disabled'))
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function selectShift($shiftId)
    {
        if (!$shiftId) return;

        if ($this->pendingChanges) {
            LivewireAlert::title('Unsaved Changes!')
                ->text('You have unsaved staff assignments. Please save or discard them before switching shifts.')
                ->warning()
                ->toast()
                ->position('top-end')
                ->show();
            return;
        }

        $key = array_search($shiftId, array_column($this->shifts, 'id'));

        if ($key !== false) {
            $this->selectedShift = $this->shifts[$key];
            $this->assigningShift = Shift::find($shiftId);

            if (!$this->assigningShift) {
                $this->loadShifts();
                return;
            }

            // ✅ FIX: Load from employee_shift_assignments
            $this->assignedStaffIds = DB::table('employee_shift_assignments')
                ->where('shift_id', $shiftId)
                ->where('is_active', true)
                ->pluck('employee_id')
                ->toArray();

            $this->originalAssignedStaffIds = $this->assignedStaffIds;
            $this->pendingChanges = false;

            $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
                ->with(['shifts'])
                ->get();

            $this->searchEmployees();
        }
    }

    #[On('searchStaff')]
    public function searchEmployees()
    {
        $organizationId = auth()->user()->employee->organization_id;

        $query = Employee::query()
            ->where('organization_id', $organizationId)
            ->with(['shifts']);

        if (!empty($this->searchTerm)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->searchTerm . '%')
                    ->orWhereHas('shifts', function ($sq) {
                        $sq->where('name', 'like', '%' . $this->searchTerm . '%');
                    })
                    ->orWhereHas('department', function ($sq) {
                        $sq->where('name', 'like', '%' . $this->searchTerm . '%');
                    });
            });
        }

        $this->availableStaff = $query->get();
    }

    public function getAssignedStaff()
    {
        $this->assigningShift = Shift::findOrFail($this->selectedShift['id']);

        $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
            ->with(['shifts'])
            ->get();
    }

    public function assignStaff($staffId)
    {
        if (!in_array($staffId, $this->assignedStaffIds)) {
            $this->assignedStaffIds[] = $staffId;
            $this->checkForPendingChanges();
        }

        $this->getAssignedStaff();
    }

    public function removeStaff($staffId)
    {
        $this->assignedStaffIds = array_values(
            array_filter($this->assignedStaffIds, fn($id) => $id != $staffId)
        );

        $this->checkForPendingChanges();
        $this->getAssignedStaff();
    }

    private function checkForPendingChanges()
    {
        $current = array_values($this->assignedStaffIds);
        $original = array_values($this->originalAssignedStaffIds);

        sort($current);
        sort($original);

        $this->pendingChanges = ($current !== $original);
    }

    public function discardChanges()
    {
        $this->assignedStaffIds = $this->originalAssignedStaffIds;
        $this->pendingChanges = false;
        $this->getAssignedStaff();

        LivewireAlert::title('Changes Discarded')
            ->text('Staff assignments have been reset.')
            ->info()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveAssignment()
    {
        try {
            DB::beginTransaction();

            $organizationId = auth()->user()->employee->organization_id;
            $currentShiftId = $this->selectedShift['id'];

            // ✅ FIX: Query employee_shift_assignments
            $currentlyAssigned = DB::table('employee_shift_assignments')
                ->where('shift_id', $currentShiftId)
                ->where('is_active', true)
                ->pluck('employee_id')
                ->toArray();

            // Remove employees no longer assigned
            $toRemove = array_diff($currentlyAssigned, $this->assignedStaffIds);
            foreach ($toRemove as $employeeId) {
                // ✅ FIX: Soft delete (set is_active = false)
                DB::table('employee_shift_assignments')
                    ->where('shift_id', $currentShiftId)
                    ->where('employee_id', $employeeId)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now()
                    ]);
            }

            // Add new employees
            $toAdd = array_diff($this->assignedStaffIds, $currentlyAssigned);
            foreach ($toAdd as $employeeId) {
                // ✅ FIX: Check if exists and reactivate, or create new
                $existing = DB::table('employee_shift_assignments')
                    ->where('shift_id', $currentShiftId)
                    ->where('employee_id', $employeeId)
                    ->first();

                if ($existing) {
                    // Reactivate
                    DB::table('employee_shift_assignments')
                        ->where('id', $existing->id)
                        ->update([
                            'is_active' => true,
                            'updated_at' => now()
                        ]);
                } else {
                    // Create new
                    DB::table('employee_shift_assignments')->insert([
                        'employee_id' => $employeeId,
                        'shift_id' => $currentShiftId,
                        'priority' => 0,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Update employee's current_shift_id if not set
                $employee = Employee::find($employeeId);
                if (!$employee->current_shift_id) {
                    $employee->current_shift_id = $currentShiftId;
                    $employee->save();
                }
            }

            DB::commit();

            $this->originalAssignedStaffIds = $this->assignedStaffIds;
            $this->pendingChanges = false;

            $this->loadShifts();

            $key = array_search($currentShiftId, array_column($this->shifts, 'id'));
            if ($key !== false) {
                $this->selectedShift = $this->shifts[$key];
            }

            $this->getAssignedStaff();
            $this->searchEmployees();

            LivewireAlert::title('Success!')
                ->text('Staff assignments saved successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Save assignment error: ' . $e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Failed to save staff assignments. Please try again.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function openMultiShiftModal($employeeId)
    {
        $this->selectedEmployeeForMultiShift = Employee::with('shifts')->findOrFail($employeeId);

        $this->employeeMultiShifts = $this->selectedEmployeeForMultiShift->shifts
            ->filter(function ($shift) {
                return $shift->pivot->is_active == true;
            })
            ->map(function ($shift) {  // ✅ Added -> before map
                return [
                    'id' => $shift->id,
                    'name' => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time' => $shift->end_time,
                    'priority' => $shift->pivot->priority,
                    'is_active' => $shift->pivot->is_active,
                    'effective_from' => $shift->pivot->effective_from,
                    'effective_until' => $shift->pivot->effective_until,
                ];
            })
            ->values()  // ✅ Reset array keys after filtering
            ->toArray();

        $this->showMultiShiftModal = true;
    }


    public function addShiftToEmployee()
    {
        $this->validate([
            'newShiftAssignment.shift_id' => 'required|exists:shifts,id',
            'newShiftAssignment.priority' => 'required|integer|min:0|max:999',
        ]);

        try {
            // ✅ FIX: Check only for ACTIVE assignments using DB query
            $activeExists = DB::table('employee_shift_assignments')
                ->where('employee_id', $this->selectedEmployeeForMultiShift->id)
                ->where('shift_id', $this->newShiftAssignment['shift_id'])
                ->where('is_active', true)  // ← KEY FIX: Only check active assignments
                ->exists();

            if ($activeExists) {
                LivewireAlert::title('Info!')
                    ->text('This shift is already assigned to the employee.')
                    ->info()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // ✅ FIX: Check if there's an inactive assignment we can reactivate
            $inactiveAssignment = DB::table('employee_shift_assignments')
                ->where('employee_id', $this->selectedEmployeeForMultiShift->id)
                ->where('shift_id', $this->newShiftAssignment['shift_id'])
                ->where('is_active', false)
                ->first();

            if ($inactiveAssignment) {
                // Reactivate the existing assignment with new priority and dates
                DB::table('employee_shift_assignments')
                    ->where('id', $inactiveAssignment->id)
                    ->update([
                        'priority' => $this->newShiftAssignment['priority'],
                        'is_active' => true,
                        'effective_from' => $this->newShiftAssignment['effective_from'],
                        'effective_until' => $this->newShiftAssignment['effective_until'],
                        'updated_at' => now()
                    ]);
            } else {
                // Create new assignment using DB insert (more reliable)
                DB::table('employee_shift_assignments')->insert([
                    'employee_id' => $this->selectedEmployeeForMultiShift->id,
                    'shift_id' => $this->newShiftAssignment['shift_id'],
                    'priority' => $this->newShiftAssignment['priority'],
                    'is_active' => true,
                    'effective_from' => $this->newShiftAssignment['effective_from'],
                    'effective_until' => $this->newShiftAssignment['effective_until'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $employee = $this->selectedEmployeeForMultiShift;
            if (!$employee->current_shift_id) {
                $employee->current_shift_id = $this->newShiftAssignment['shift_id'];
                $employee->save();
            }

            $this->selectedEmployeeForMultiShift->refresh();
            $this->openMultiShiftModal($this->selectedEmployeeForMultiShift->id);
            $this->selectShift($this->selectedShift['id']);

            $this->newShiftAssignment = [
                'shift_id' => null,
                'priority' => 100,
                'effective_from' => null,
                'effective_until' => null,
            ];

            LivewireAlert::title('Success!')
                ->text('Shift assigned successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            \Log::error('Add shift error: ' . $e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Failed to assign shift.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    
    public function removeShiftFromEmployee($shiftId)
    {
        try {
            // ✅ FIX: Soft delete
            DB::table('employee_shift_assignments')
                ->where('employee_id', $this->selectedEmployeeForMultiShift->id)
                ->where('shift_id', $shiftId)
                ->update([
                    'is_active' => false,
                    'updated_at' => now()
                ]);

            // If removing current_shift_id, find next highest priority
            if ($this->selectedEmployeeForMultiShift->current_shift_id == $shiftId) {
                $nextShift = DB::table('employee_shift_assignments')
                    ->where('employee_id', $this->selectedEmployeeForMultiShift->id)
                    ->where('is_active', true)
                    ->orderBy('priority', 'desc')
                    ->first();

                $this->selectedEmployeeForMultiShift->current_shift_id = $nextShift->shift_id ?? null;
                $this->selectedEmployeeForMultiShift->save();
            }

            $this->selectedEmployeeForMultiShift->refresh();
            $this->openMultiShiftModal($this->selectedEmployeeForMultiShift->id);
            $this->selectShift($this->selectedShift['id']);

            LivewireAlert::title('Success!')
                ->text('Shift removed successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to remove shift.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function updateShiftPriority($shiftId, $newPriority)
    {
        try {
            DB::table('employee_shift_assignments')
                ->where('employee_id', $this->selectedEmployeeForMultiShift->id)
                ->where('shift_id', $shiftId)
                ->update([
                    'priority' => $newPriority,
                    'updated_at' => now()
                ]);

            $this->selectedEmployeeForMultiShift->refresh();
            $this->openMultiShiftModal($this->selectedEmployeeForMultiShift->id);

            LivewireAlert::title('Success!')
                ->text('Priority updated successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to update priority.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function getInitials($name)
    {
        $words = explode(' ', $name);
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    public function updateShiftField($field, $value)
    {
        $this->selectedShift[$field] = $value;
    }

    public function toggleOvertime()
    {
        $this->selectedShift['overtimeEnabled'] = !$this->selectedShift['overtimeEnabled'];
    }

    public function toggleAutoClockOut()
    {
        $this->selectedShift['autoClockOut'] = !$this->selectedShift['autoClockOut'];
    }

    public function handlePatternChange($patternId)
    {
        $newPatternDays = [];

        switch ($patternId) {
            case 'weekdays':
                $newPatternDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
                break;
            case 'weekends':
                $newPatternDays = ['Sat', 'Sun'];
                break;
            case 'daily':
                $newPatternDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                break;
            case 'rotating':
            case 'custom':
                $newPatternDays = $this->selectedShift['patternDays'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
                break;
        }

        $this->selectedShift['pattern'] = $patternId;
        $this->selectedShift['patternDays'] = $newPatternDays;
    }

    public function togglePatternDay($day)
    {
        if (in_array($day, $this->selectedShift['patternDays'])) {
            $this->selectedShift['patternDays'] = array_values(array_diff($this->selectedShift['patternDays'], [$day]));
        } else {
            $this->selectedShift['patternDays'][] = $day;
        }
    }

    public function toggleGracePeriod()
    {
        $this->selectedShift['gracePeriodEnabled'] = !$this->selectedShift['gracePeriodEnabled'];
    }

    public function toggleTrackLateCheckin()
    {
        $this->selectedShift['trackLateCheckin'] = !$this->selectedShift['trackLateCheckin'];
    }

    public function toggleTrackEarlyCheckout()
    {
        $this->selectedShift['trackEarlyCheckout'] = !$this->selectedShift['trackEarlyCheckout'];
    }


    public function saveShift()
    {
        $shift = Shift::find($this->selectedShift['id']);

        if ($shift) {
            $shift->update([
                'name' => $this->selectedShift['name'],
                'start_time' => $this->selectedShift['startTime'],
                'end_time' => $this->selectedShift['endTime'],
                'duration_hours' => $this->selectedShift['duration'],
                'overtime_enabled' => $this->selectedShift['overtimeEnabled'],
                'max_overtime_hours' => $this->selectedShift['maxOvertime'],
                'auto_clock_out' => $this->selectedShift['autoClockOut'],
                'warning_time_minutes' => $this->selectedShift['warningTime'],
                'break_minutes' => $this->selectedShift['breakDuration'],
                'pattern_type' => $this->selectedShift['pattern'],
                'pattern_days' => $this->selectedShift['patternDays'],
                'notify_managers_overtime' => $this->selectedShift['notifyManagers'] ?? false,
                'employee_mobile_notifications' => $this->selectedShift['mobileNotifications'] ?? true,
                'email_summaries' => $this->selectedShift['emailSummaries'] ?? false,
                'grace_period_enabled' => $this->selectedShift['gracePeriodEnabled'] ?? true,
                'grace_period_minutes' => $this->selectedShift['gracePeriodMinutes'] ?? 15,
                'track_late_checkin' => $this->selectedShift['trackLateCheckin'] ?? true,
                'notify_on_late_checkin' => $this->selectedShift['notifyOnLateCheckin'] ?? false,
                'track_early_checkout' => $this->selectedShift['trackEarlyCheckout'] ?? true,
                'early_checkout_threshold_minutes' => $this->selectedShift['earlyCheckoutThreshold'] ?? 15,
            ]);

            // ✅ SAVE ORGANIZATION SETTINGS WITH VALIDATION
            if ($this->orgSettings) {
                // Validate organization settings
                $this->validate([
                    'orgSettings.shift_change_cooldown_minutes' => 'required|integer|min:0|max:1440',
                    'orgSettings.auto_detection_minimum_score' => 'required|integer|min:0|max:100',
                ]);

                // Save the organization settings
                $this->orgSettings->save();
            }

            $this->loadShifts();

            $key = array_search($shift->id, array_column($this->shifts, 'id'));
            if ($key !== false) {
                $this->selectedShift = $this->shifts[$key];
            }

            LivewireAlert::title('Awesome!')
                ->text('Shift and organization settings saved successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function addNewShift()
    {
        $shift = Shift::create([
            'organization_id' => auth()->user()->employee->organization_id,
            'name' => 'New Shift',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'duration_hours' => 8,
            'break_minutes' => 60,
            'overtime_rate' => 0,
            'overtime_enabled' => true,
            'max_overtime_hours' => 2,
            'auto_clock_out' => true,
            'warning_time_minutes' => 30,
            'pattern_type' => 'weekdays',
            'pattern_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'notify_managers_overtime' => false,
            'employee_mobile_notifications' => true,
            'email_summaries' => false,
            'status' => 'active',
            'grace_period_enabled' => true,
            'grace_period_minutes' => 15,
            'track_late_checkin' => true,
            'notify_on_late_checkin' => false,
            'track_early_checkout' => true,
            'early_checkout_threshold_minutes' => 15,
        ]);

        $this->loadShifts();
        $this->selectShift($shift->id);
        $this->showAddShift = false;

        session()->flash('message', 'New shift created successfully!');
    }

    public function deleteShift()
    {
        $shift = Shift::find($this->selectedShift['id']);

        if ($shift) {
            // ✅ FIX: Check employee_shift_assignments
            $activeEmployees = DB::table('employee_shift_assignments')
                ->where('shift_id', $shift->id)
                ->where('is_active', true)
                ->count();

            if ($activeEmployees > 0) {
                LivewireAlert::title('Error!')
                    ->text('Cannot delete shift with assigned employees. Please reassign employees first.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();

                return;
            }

            $shift->delete();

            $this->loadShifts();

            if (count($this->shifts) > 0) {
                $this->selectShift($this->shifts[0]['id']);
            }

            LivewireAlert::title('Awesome!')
                ->text('Shift deleted successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function getPatternDisplay($pattern, $days)
    {
        $patternInfo = collect($this->shiftPatterns)->firstWhere('id', $pattern);

        if (in_array($pattern, ['custom', 'rotating'])) {
            return $patternInfo['name'] . ' (' . implode(', ', $days) . ')';
        }

        return $patternInfo['name'];
    }

    #[On('time-selected')]
    public function calculateShiftDuration()
    {
        if (!isset($this->selectedShift['startTime']) || !isset($this->selectedShift['endTime'])) {
            return;
        }

        try {
            $start = Carbon::parse($this->selectedShift['startTime']);
            $end = Carbon::parse($this->selectedShift['endTime']);

            if ($end->lt($start)) {
                $end->addDay();
            }

            $minutes = $start->diffInMinutes($end);
            $break = $this->selectedShift['breakDuration'] ?? 0;
            $minutes -= $break;

            $this->selectedShift['duration'] = round($minutes / 60, 2);

        } catch (\Exception $e) {
            // Fail silently
        }
    }

    #[On('shiftTabChanged')]
    public function shiftTabChanged($tabId)
    {
        $this->activeShiftTab = $tabId;
        $this->changeShiftBreadcrumb();
    }

    public function changeShiftBreadcrumb()
    {
        switch ($this->activeShiftTab) {
            case 'shift_settings':
                $this->tabTitle = 'Shift Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock-outline" class="fs-5"></iconify-icon>';
                break;

            case 'assign_employee':
                $this->tabTitle = 'Assign Employee';
                $this->tabIcon = '<iconify-icon icon="mdi:account-multiple-check-outline" class="fs-5"></iconify-icon>';
                $this->getAssignedStaff();
                break;

            default:
                $this->tabTitle = 'shift_settings';
                $this->tabIcon = '<iconify-icon icon="mdi:cog-outline" class="fs-5"></iconify-icon>';
                break;
        }
    }
}; ?>

@push('styles')
    <style>
        .shift-config-wrapper {
            min-height: 100vh;
            background-color: #f8f9fa;
        }

        .shift-header {
            background-color: white;
            border-bottom: 1px solid #dee2e6;
            padding: 1.5rem;
        }

        .shift-sidebar {
            width: 320px;
            min-height: 700px;
            background-color: white;
            border-right: 1px solid #dee2e6;
            padding: 1.5rem;
            height: calc(100vh - 80px);
            overflow-y: auto;
        }

        .shift-card {
            padding: 1rem;
            border-radius: 0.5rem;
            border: 1px solid #dee2e6;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 0.75rem;
        }

        .shift-card:hover {
            border-color: #adb5bd;
        }

        .shift-card.active {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }

        .shift-content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
        }

        .config-section {
            background-color: white;
            border-radius: 0.5rem;
            border: 1px solid #dee2e6;
            margin-bottom: 1.5rem;
        }

        .section-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .section-body {
            padding: 1.5rem;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #6c757d;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #0d6efd;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }

        .day-btn {
            padding: 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 0.5rem;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
            color: #6c757d;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .day-btn.active {
            background-color: #cfe2ff;
            border-color: #9ec5fe;
            color: #084298;
        }

        .day-btn:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .alert-warning-custom {
            background-color: #fff3cd;
            border: 1px solid #ffe69c;
            color: #664d03;
            border-radius: 0.5rem;
            padding: 1rem;
        }

        .alert-danger-custom {
            background-color: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            border-radius: 0.5rem;
            padding: 1rem;
        }

        .pattern-template-btn {
            padding: 1rem;
            text-align: left;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            background-color: white;
            transition: all 0.3s ease;
            cursor: pointer;
            width: 100%;
        }

        .pattern-template-btn:hover {
            border-color: #adb5bd;
        }

        .pattern-template-btn.active {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }

        .modal-backdrop-custom {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content-custom {
            background-color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .badge-success {
            background-color: #d1e7dd;
            color: #0f5132;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
        }

        .badge-danger {
            background-color: #f8d7da;
            color: #842029;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .delete-btn {
            border-radius: 50%;
            cursor: pointer;
            transition: 0.2s ease;
            color: #dc3545;
        }

        .staff-assignment-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .section-header svg {
            width: 24px;
            height: 24px;
            color: #dc3545;
        }

        .section-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #212529;
            margin: 0;
        }

        .alert-success {
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: #d1e7dd;
            border: 1px solid #badbcc;
            border-radius: 8px;
            color: #0f5132;
        }

        .alert-error {
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: #f8d7da;
            border: 1px solid #f5c2c7;
            border-radius: 8px;
            color: #842029;
        }

        .section-title {
            font-size: 0.875rem;
            font-weight: 500;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .search-wrapper {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 12px;
            width: 20px;
            height: 20px;
            color: #6c757d;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .search-input:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .staff-list {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 2rem;
        }

        .staff-list::-webkit-scrollbar {
            width: 8px;
        }

        .staff-list::-webkit-scrollbar-track {
            background: #f8f9fa;
            border-radius: 4px;
        }

        .staff-list::-webkit-scrollbar-thumb {
            background: #dee2e6;
            border-radius: 4px;
        }

        .staff-list::-webkit-scrollbar-thumb:hover {
            background: #adb5bd;
        }

        .staff-item {
            padding: 1rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }

        .staff-item.clickable {
            cursor: pointer;
        }

        .staff-item.clickable:hover {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }

        .staff-item.disabled {
            background-color: #f8f9fa;
            opacity: 0.6;
            border-color: #dee2e6;
            cursor: not-allowed;
        }

        .staff-item.assigned-item {
            /*background-color: #d1e7dd;*/
            border-color: #badbcc;
        }

        .staff-item-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .staff-avatar-unassigned {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            border: solid #e14326 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .staff-avatar-assigned {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            border: solid green 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .staff-avatar {
            border: solid green 2px;
        }


        .staff-info {
            flex: 1;
            min-width: 0;
        }

        .staff-name {
            font-weight: 500;
            color: #212529;
            margin: 0 0 0.25rem 0;
        }

        .staff-details {
            font-size: 0.875rem;
            color: #6c757d;
            margin: 0;
        }

        .assigned-badge {
            color: #198754;
            font-size: 0.875rem;
            font-weight: 500;
            flex-shrink: 0;
        }

        .remove-button {
            padding: 0.5rem;
            background: transparent;
            border: none;
            color: #dc3545;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            flex-shrink: 0;
        }

        .remove-button:hover {
            background-color: #f8d7da;
        }

        .remove-button svg {
            width: 20px;
            height: 20px;
            display: block;
        }

        .empty-state {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 3rem 2rem;
            text-align: center;
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            color: #adb5bd;
            margin: 0 auto 0.75rem;
            display: block;
        }

        .empty-state-text {
            color: #6c757d;
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
        }

        .empty-state-subtext {
            color: #adb5bd;
            margin: 0;
            font-size: 0.875rem;
        }

        .assigned-section {
            border-top: 2px solid #dee2e6;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }

        .assigned-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 1rem;
        }

        .summary-box {
            border: 1px solid #b6d4fe;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .summary-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #084298;
            margin: 0 0 0.25rem 0;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #0a58ca;
            margin: 0;
        }

        .save-button {
            padding: 0.5rem 1rem;
            background-color: #0d6efd;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .save-button:hover:not(:disabled) {
            background-color: #0b5ed7;
        }

        .save-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Add to existing styles */
        .shift-assignment-item {
            background-color: #f8f9fa;
            transition: all 0.2s ease;
        }

        .shift-assignment-item:hover {
            background-color: #e9ecef;
        }

        /* Enhanced Styles for Improved UI */
        .status-indicator-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);
        }

        /* Improve scrollbar */
        .staff-list::-webkit-scrollbar {
            width: 10px;
        }

        .staff-list::-webkit-scrollbar-track {
            background: #f8f9fa;
            border-radius: 5px;
        }

        .staff-list::-webkit-scrollbar-thumb {
            background: #dee2e6;
            border-radius: 5px;
        }

        .staff-list::-webkit-scrollbar-thumb:hover {
            background: #adb5bd;
        }

        /* FIXED: Status dot colors */
        .status-dot.status-assigned {
            background-color: #28a745;
        }

        .status-dot.status-other-shift {
            background-color: #ffc107;
        }

        .status-dot.status-unassigned {
            background-color: #dc3545;
        }

        /* FIXED: Better button styling */
        .btn-assign {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .btn-assign:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        /* Update summary box styling */
        .summary-box {
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .summary-box:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

    </style>
@endpush

<div>
    <!-- Header -->
    <div class="shift-header">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <svg width="32" height="32" class="text-primary me-3" fill="currentColor" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div>
                    <h1 class="h3 mb-0">Shift Configuration</h1>
                    <p class="text-muted small mb-0">Manage automated clock-out and overtime settings</p>
                </div>
            </div>
            @if($activeShiftTab === 'shift_settings')
                <button wire:click="saveShift" class="btn btn-primary">
                    <svg width="16" height="16" class="me-2" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" fill="none"
                              stroke="currentColor" stroke-width="2"/>
                        <polyline points="17 21 17 13 7 13 7 21" stroke="currentColor" stroke-width="2"/>
                        <polyline points="7 3 7 8 15 8" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Save Changes
                </button>
            @endif
        </div>
    </div>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="shift-sidebar">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Shifts</h5>
                <button wire:click="$set('showAddShift', true)" class="btn btn-sm btn-link text-primary">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/>
                        <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Add Shift
                </button>
            </div>

            @foreach($shifts as $shift)
                <div wire:click="selectShift({{ $shift['id'] }})"
                     class="shift-card {{ $selectedShift['id'] == $shift['id'] ? 'active' : '' }}">

                    <div class="d-flex justify-content-between align-items-start mb-2">

                        <h6 class="mb-0">{{ $shift['name'] }}</h6>

                        <div class="d-flex align-items-center">

                            <div class="d-flex align-items-center text-muted me-2">
                                <svg width="12" height="12" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                </svg>
                                <small>{{ $shift['employees'] }}</small>
                            </div>

                            <button type="button"
                                    class="delete-btn mw-3 mb-1 btn btn-outline-danger p-1 d-flex align-items-center justify-content-center"
                                    wire:click="deleteShift()"
                                    wire:confirm="Are you sure you want to delete this shift?"
                                    {{ $selectedShift['id'] !== $shift['id'] ? 'disabled' : '' }}
                                    title="{{ $selectedShift['id'] === $shift['id'] ? 'Delete shift' : 'Select this shift to delete' }}">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                     viewBox="0 0 24 24">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6l-1 14H6L5 6"/>
                                    <path d="M10 11v6"/>
                                    <path d="M14 11v6"/>
                                    <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                                </svg>
                            </button>


                        </div>

                    </div>

                    <div class="small text-muted">
                        <div>{{ $shift['startTime'] }} - {{ $shift['endTime'] }}</div>
                        <div class="d-flex mt-1">
                            <span>Duration: {{ $shift['duration'] }}h</span>
                            @if($shift['overtimeEnabled'])
                                <span class="ms-3">Max OT: {{ $shift['maxOvertime'] }}h</span>
                            @endif
                        </div>
                        <div class="text-primary mt-1" style="font-size: 0.7rem;">
                            {{ $this->getPatternDisplay($shift['pattern'], $shift['patternDays']) }}
                        </div>
                    </div>
                    <div class="mt-2 d-flex justify-content-between align-items-center">
                        @if($shift['autoClockOut'])
                            <span class="badge-success">
                                <svg width="12" height="12" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="none"
                                          stroke="currentColor" stroke-width="2"/>
                                </svg>
                                Auto clock-out
                            </span>
                        @endif
                        @if(!$shift['overtimeEnabled'])
                            <span class="badge-danger">
                                <svg width="12" height="12" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                                    <path
                                        d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                                        fill="none" stroke="currentColor" stroke-width="2"/>
                                    <line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2"/>
                                </svg>
                                No overtime
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Main Content -->
        <div class="shift-content">
            <div style="max-width: 1000px;">

                <ul class="nav nav-pills user-profile-tab" id="shift-tabs" role="tablist">
                    <!-- Company Information -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeShiftTab === 'shift_settings' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                            id="tab-shift-settings-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-company-information"
                            type="button"
                            role="tab"
                            aria-controls="tab-shift-settings"
                            aria-selected="true">
                            <i class="ti ti-clock mx-1 fs-6"></i>
                            <span class="d-none d-md-block">Shift Settings</span>
                        </button>
                    </li>

                    <!-- Roles & Permissions -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeShiftTab === 'assign_employee' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                            id="tab-assign-employee-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-roles-permissions"
                            type="button"
                            role="tab"
                            aria-controls="tab-roles-permissions"
                            aria-selected="false">
                            <i class="ti ti-user-circle mx-1 fs-6"></i>
                            <span class="d-none d-md-block">Assign Employee</span>
                        </button>
                    </li>

                </ul>


                <!-- Inner Tab Content -->
                <div class="tab-content" id="innerRolesTabContent">

                    <!-- Overtime Policy Tab -->
                    <div class="mt-3 tab-pane fade {{ $activeShiftTab === 'shift_settings' ? 'show active' : '' }}"
                         id="tab-shift-settings">

                        <!-- Shift Details -->
                        <div class="config-section">
                            <div class="section-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <input type="text"
                                               wire:model.defer="selectedShift.name"
                                               class="form-control form-control-lg border-0 p-0 h4 mb-1">
                                        <p class="text-muted small mb-0">Configure automated time tracking rules</p>
                                    </div>
                                    <button class="btn btn-link text-danger">
                                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path
                                                d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="section-body">
                                <h5 class="mb-3">
                                    <svg width="20" height="20" class="text-primary me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" fill="none"
                                              stroke="currentColor"
                                              stroke-width="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2"/>
                                        <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2"/>
                                        <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                    Basic Schedule
                                </h5>

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Start Time</label>
                                        <input type="time"
                                               wire:input="$dispatch('time-selected')"
                                               wire:model="selectedShift.startTime"
                                               class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">End Time</label>
                                        <input type="time"
                                               wire:input="$dispatch('time-selected')"
                                               wire:model="selectedShift.endTime"
                                               class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Break Time (minutes)</label>
                                        <input type="number"
                                               wire:input="$dispatch('time-selected')"
                                               wire:model="selectedShift.breakDuration"
                                               class="form-control"
                                               min="0" max="120" step="5">
                                    </div>

                                </div>
                                <div class="col-md-12 mt-3">
                                    <label class="form-label">Shift Duration (hours)</label>
                                    <input type="number"
                                           wire:model="selectedShift.duration"
                                           class="form-control"
                                           min="1" max="24" step="0.5">
                                </div>

                            </div>
                        </div>

                        <!-- Shift Pattern -->
                        <div class="config-section">
                            <div class="section-header">
                                <div class="d-flex justify-content-between w-100">
                                    <h5 class="mb-0">
                                        <svg width="20" height="20" class="text-primary me-2" fill="currentColor"
                                             viewBox="0 0 24 24">
                                            <polyline points="23 4 23 10 17 10" fill="none" stroke="currentColor"
                                                      stroke-width="2"/>
                                            <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10" fill="none"
                                                  stroke="currentColor"
                                                  stroke-width="2"/>
                                        </svg>
                                        Shift Pattern
                                    </h5>
                                    <button wire:click="$set('showPatternModal', true)" class="btn btn-sm btn-primary">
                                        Configure Pattern
                                    </button>
                                </div>
                            </div>

                            <div class="section-body">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Pattern Type</label>
                                        @foreach($shiftPatterns as $pattern)
                                            <div class="form-check mb-2">
                                                <input class="form-check-input"
                                                       type="radio"
                                                       name="shiftPattern_{{ $selectedShift['id'] ?? 'new' }}"
                                                       id="pattern_{{ $pattern['id'] }}_{{ $selectedShift['id'] ?? 'new' }}"
                                                       value="{{ $pattern['id'] }}"
                                                       wire:model.live="selectedShift.pattern"
                                                       wire:change="handlePatternChange('{{ $pattern['id'] }}')">
                                                <label class="form-check-label"
                                                       for="pattern_{{ $pattern['id'] }}_{{ $selectedShift['id'] ?? 'new' }}">
                                                    <strong>{{ $pattern['name'] }}</strong>
                                                    <div class="small text-muted">{{ $pattern['description'] }}</div>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Active Days</label>
                                        <div class="row g-2 mb-3">
                                            @foreach($dayAbbreviations as $day)
                                                <div class="col-3">
                                                    <button type="button"
                                                            wire:click="togglePatternDay('{{ $day }}')"
                                                            class="day-btn w-100 {{ in_array($day, $selectedShift['patternDays']) ? 'active' : '' }}"
                                                        {{ !in_array($selectedShift['pattern'], ['custom', 'rotating']) ? 'disabled' : '' }}>
                                                        {{ $day }}
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="alert alert-light border">
                                            <div class="d-flex align-items-center mb-2">
                                                <svg width="16" height="16" class="text-success me-2"
                                                     fill="currentColor"
                                                     viewBox="0 0 24 24">
                                                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01" fill="none"
                                                              stroke="currentColor"
                                                              stroke-width="2"/>
                                                </svg>
                                                <strong>Current Pattern:</strong>
                                            </div>
                                            <p class="mb-1">{{ $this->getPatternDisplay($selectedShift['pattern'], $selectedShift['patternDays']) }}</p>
                                            @if(count($selectedShift['patternDays']) > 0)
                                                <small class="text-muted">
                                                    {{ count($selectedShift['patternDays']) }} {{ count($selectedShift['patternDays']) == 1 ? 'day' : 'days' }}
                                                    per week
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Overtime & Auto Clock-Out -->
                        <div class="config-section">
                            <div class="section-header">
                                <h5 class="mb-0">
                                    <svg width="20" height="20" class="text-warning me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="3"/>
                                        <path
                                            d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m5.08 5.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m5.08-5.08l4.24-4.24"/>
                                    </svg>
                                    Overtime & Auto Clock-Out
                                </h5>
                            </div>

                            <div class="section-body">
                                <!-- Enable Overtime Toggle -->
                                <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                                    <div>
                                        <h6 class="mb-1">Enable Overtime</h6>
                                        <p class="text-muted small mb-0">Allow employees to work beyond their scheduled
                                            shift
                                            hours</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleOvertime"
                                            {{ $selectedShift['overtimeEnabled'] ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <!-- Auto Clock-Out Toggle -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="mb-1">Automatic Clock-Out</h6>
                                        <p class="text-muted small mb-0">
                                            @if($selectedShift['overtimeEnabled'])
                                                Automatically clock out employees after maximum allowed hours
                                            @else
                                                Automatically clock out employees at scheduled shift end
                                            @endif
                                        </p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleAutoClockOut"
                                            {{ $selectedShift['autoClockOut'] ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                @if($selectedShift['overtimeEnabled'] && $selectedShift['autoClockOut'])
                                    <!-- Overtime Settings -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Maximum Overtime Hours</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       wire:model.defer="selectedShift.maxOvertime"
                                                       class="form-control"
                                                       min="0" max="8" step="0.5">
                                                <span class="input-group-text">hours</span>
                                            </div>
                                            <small class="text-muted">
                                                Auto clock-out at: {{ $selectedShift['endTime'] }}
                                                + {{ $selectedShift['maxOvertime'] }}h
                                            </small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Warning Time Before Auto Clock-Out</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       wire:model.defer="selectedShift.warningTime"
                                                       class="form-control"
                                                       min="5" max="60" step="5">
                                                <span class="input-group-text">minutes</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Warning Preview -->
                                    <div class="alert-warning-custom">
                                        <div class="d-flex">
                                            <svg width="20" height="20" class="me-2 flex-shrink-0"
                                                 style="color: #664d03;"
                                                 fill="currentColor" viewBox="0 0 24 24">
                                                <path
                                                    d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                                                    fill="none" stroke="currentColor" stroke-width="2"/>
                                                <line x1="12" y1="9" x2="12" y2="13" stroke="currentColor"
                                                      stroke-width="2"/>
                                                <line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor"
                                                      stroke-width="2"/>
                                            </svg>
                                            <div>
                                                <h6 class="mb-1">Auto Clock-Out Preview</h6>
                                                <p class="mb-0 small">
                                                    Employees will receive a warning at
                                                    <strong>{{ $selectedShift['endTime'] }}
                                                        + {{ max(0, $selectedShift['maxOvertime'] - $selectedShift['warningTime']/60) }}
                                                        h</strong>
                                                    and be automatically clocked out at
                                                    <strong>{{ $selectedShift['endTime'] }}
                                                        + {{ $selectedShift['maxOvertime'] }}h</strong>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @elseif(!$selectedShift['overtimeEnabled'])
                                    <!-- No Overtime Warning -->
                                    <div class="alert-danger-custom">
                                        <div class="d-flex">
                                            <svg width="20" height="20" class="me-2 flex-shrink-0"
                                                 style="color: #842029;"
                                                 fill="currentColor" viewBox="0 0 24 24">
                                                <path
                                                    d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                                                    fill="none" stroke="currentColor" stroke-width="2"/>
                                                <line x1="12" y1="9" x2="12" y2="13" stroke="currentColor"
                                                      stroke-width="2"/>
                                                <line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor"
                                                      stroke-width="2"/>
                                            </svg>
                                            <div>
                                                <h6 class="mb-1">Overtime Disabled</h6>
                                                <p class="mb-0 small">
                                                    Employees
                                                    will {{ $selectedShift['autoClockOut'] ? 'be automatically clocked out' : 'need to manually clock out' }}
                                                    at their scheduled shift end time ({{ $selectedShift['endTime'] }}).
                                                    @if(!$selectedShift['autoClockOut'])
                                                        They cannot work beyond this time without manager override.
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Grace Period & Late Check-in Tracking -->
                        <div class="config-section">
                            <div class="section-header">
                                <h5 class="mb-0">
                                    <svg width="20" height="20" class="text-info me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                stroke-width="2"/>
                                        <polyline points="12 6 12 12 16 14" stroke="currentColor" stroke-width="2"
                                                  stroke-linecap="round"/>
                                    </svg>
                                    Grace Period & Attendance Tracking
                                </h5>
                            </div>

                            <div class="section-body">
                                <!-- Grace Period Toggle -->
                                <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                                    <div>
                                        <h6 class="mb-1">Enable Grace Period</h6>
                                        <p class="text-muted small mb-0">Allow employees a grace period for late
                                            check-ins
                                            without marking them as late</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleGracePeriod"
                                            {{ $selectedShift['gracePeriodEnabled'] ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                @if($selectedShift['gracePeriodEnabled'])
                                    <!-- Grace Period Settings -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Grace Period Duration</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       wire:model.defer="selectedShift.gracePeriodMinutes"
                                                       class="form-control"
                                                       min="0" max="60" step="5">
                                                <span class="input-group-text">minutes</span>
                                            </div>
                                            <small class="text-muted">
                                                Employees can check in up to {{ $selectedShift['gracePeriodMinutes'] }}
                                                minutes
                                                late
                                            </small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Late Check-in Window</label>
                                            <div class="input-group">
                                                <input type="text"
                                                       class="form-control"
                                                       value="{{ $selectedShift['startTime'] }} - {{ \Carbon\Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}"
                                                       disabled>
                                            </div>
                                            <small class="text-muted">
                                                Grace period ends
                                                at {{ \Carbon\Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Grace Period Preview -->
                                    <div class="alert alert-light border border-primary mb-3">
                                        <div class="d-flex">
                                            <svg width="20" height="20" class="me-2 flex-shrink-0 text-primary"
                                                 fill="currentColor" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                        stroke-width="2"/>
                                                <path d="M12 16v-4m0-4h.01" stroke="currentColor" stroke-width="2"
                                                      stroke-linecap="round"/>
                                            </svg>
                                            <div>
                                                <h6 class="mb-1 text-primary">Grace Period Summary</h6>
                                                <p style="backgound:transparent;" class="mb-0 small">
                                                    <strong class="text-primary">Shift
                                                        Start: {{ $selectedShift['startTime'] }}</strong> <br>
                                                    <strong class="text-primary">Grace Period
                                                        Ends: {{ \Carbon\Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}</strong>
                                                    <br>
                                                    <strong class="text-primary">Status: Check-ins
                                                        before {{ \Carbon\Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}
                                                        are "On Time", after are "Late"</strong>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <!-- Track Late Check-in Toggle -->
                                <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                                    <div>
                                        <h6 class="mb-1">Track Late Check-ins</h6>
                                        <p class="text-muted small mb-0">Record and report when employees check in
                                            late</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleTrackLateCheckin"
                                            {{ $selectedShift['trackLateCheckin'] ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <!-- Notify on Late Check-in -->
                                @if($selectedShift['trackLateCheckin'])
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">Notify Managers on Late Check-in</h6>
                                            <p class="text-muted small mb-0">Send immediate alerts when employees check
                                                in
                                                late</p>
                                        </div>
                                        <input type="checkbox"
                                               class="form-check-input"
                                               style="width: 20px; height: 20px;"
                                               wire:model="selectedShift.notifyOnLateCheckin">
                                    </div>
                                @endif

                                <!-- Track Early Check-out Toggle -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="mb-1">Track Early Check-outs</h6>
                                        <p class="text-muted small mb-0">Record when employees check out before shift
                                            end</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleTrackEarlyCheckout"
                                            {{ $selectedShift['trackEarlyCheckout'] ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                @if($selectedShift['trackEarlyCheckout'])
                                    <!-- Early Checkout Threshold -->
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Early Check-out Threshold</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       wire:model.defer="selectedShift.earlyCheckoutThreshold"
                                                       class="form-control"
                                                       min="0" max="60" step="5">
                                                <span class="input-group-text">minutes</span>
                                            </div>
                                            <small class="text-muted">
                                                Mark as early if checking out more
                                                than {{ $selectedShift['earlyCheckoutThreshold'] }} minutes before shift
                                                end
                                            </small>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Notifications -->
                        <div class="config-section">
                            <div class="section-header">
                                <h5 class="mb-0">
                                    <svg width="20" height="20" class="text-success me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                        <path d="M13.73 21a2 2 0 01-3.46 0"/>
                                    </svg>
                                    Notifications
                                </h5>
                            </div>

                            <div class="section-body">

                                <!-- Notify Managers -->
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="d-flex align-items-start">
                                        <svg width="18" height="18" class="text-primary me-2 mt-1" fill="none"
                                             stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                            <circle cx="9" cy="7" r="4"/>
                                            <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                                            <path d="M17 7a4 4 0 010 7.87"/>
                                        </svg>
                                        <div>
                                            <h6 class="mb-1">Notify Managers on Overtime</h6>
                                            <p class="text-muted small mb-0">Send alerts when employees exceed standard
                                                hours</p>
                                        </div>
                                    </div>
                                    <input type="checkbox" class="form-check-input" style="width: 20px; height: 20px;"
                                           wire:model="selectedShift.notifyManagers">
                                </div>

                                <!-- Mobile Notifications -->
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="d-flex align-items-start">
                                        <svg width="18" height="18" class="text-info me-2 mt-1" fill="none"
                                             stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <rect x="7" y="2" width="10" height="20" rx="2" ry="2"/>
                                            <line x1="12" y1="18" x2="12" y2="18"/>
                                        </svg>
                                        <div>
                                            <h6 class="mb-1">Employee Mobile Notifications</h6>
                                            <p class="text-muted small mb-0">Push notifications for overtime
                                                warnings</p>
                                        </div>
                                    </div>
                                    <input type="checkbox" class="form-check-input" style="width: 20px; height: 20px;"
                                           wire:model="selectedShift.mobileNotifications">
                                </div>

                                <!-- Email Summaries -->
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="d-flex align-items-start">
                                        <svg width="18" height="18" class="text-warning me-2 mt-1" fill="none"
                                             stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path d="M4 4h16v16H4z"/>
                                            <polyline points="22,6 12,13 2,6"/>
                                        </svg>
                                        <div>
                                            <h6 class="mb-1">Email Summaries</h6>
                                            <p class="text-muted small mb-0">Daily overtime reports to management</p>
                                        </div>
                                    </div>
                                    <input type="checkbox" class="form-check-input" style="width: 20px; height: 20px;"
                                           wire:model="selectedShift.emailSummaries">
                                </div>
                            </div>
                        </div>

                        <!-- Organization Settings Section -->
                        <div class="config-section mb-4">
                            <div class="section-header">
                                <h5 class="mb-0">
                                    <svg width="20" height="20" class="text-info me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                        <path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>
                                    </svg>
                                    Organization Settings
                                </h5>
                            </div>

                            <div class="section-body">

                                <!-- Auto Detection Toggle -->
                                <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                                    <div>
                                        <h6 class="mb-1">Enable Auto Shift Detection</h6>
                                        <p class="text-muted small mb-0">
                                            Allow system to automatically detect which shift an employee should be checked into.
                                        </p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleAutoDetection"
                                            {{ $orgSettings->allow_auto_shift_detection ?? false ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                @if($orgSettings && $orgSettings->allow_auto_shift_detection)
                                    <!-- Detection Settings -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Minimum Detection Score</label>
                                            <input type="number"
                                                   wire:model.defer="orgSettings.auto_detection_minimum_score"
                                                   class="form-control"
                                                   min="0"
                                                   max="100">
                                            <small class="text-muted">
                                                Minimum score required for auto-detection to succeed (0-100). Default: 40
                                            </small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Shift Change Cooldown</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       wire:model.defer="orgSettings.shift_change_cooldown_minutes"
                                                       class="form-control"
                                                       min="0"
                                                       max="1440">
                                                <span class="input-group-text">minutes</span>
                                            </div>
                                            <small class="text-muted">
                                                Time required between shift changes. Default: 240 minutes (4 hours)
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Info Alert -->
                                    <div class="alert alert-info mb-3">
                                        <h6 class="mb-2">
                                            <svg width="16" height="16" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                        stroke-width="2"/>
                                                <line x1="12" y1="16" x2="12" y2="12" stroke="currentColor" stroke-width="2"/>
                                                <line x1="12" y1="8" x2="12.01" y2="8" stroke="currentColor" stroke-width="2"/>
                                            </svg>
                                            How Auto Detection Works
                                        </h6>
                                        <ul class="small mb-0">
                                            <li>System scores each shift based on: day pattern match, time proximity, grace
                                                period, and priority
                                            </li>
                                            <li>The shift with the highest score above the minimum threshold is selected</li>
                                            <li>If no shift meets the minimum score, detection fails and employee must select
                                                manually (if enabled)
                                            </li>
                                        </ul>
                                    </div>
                                @endif

                                <!-- Manual Selection Toggle -->
                                <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                                    <div>
                                        <h6 class="mb-1">Allow Manual Shift Selection</h6>
                                        <p class="text-muted small mb-0">
                                            Employees can manually select their shift during check-in if auto-detection fails or
                                            is disabled
                                        </p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox"
                                               wire:click="toggleManualSelection"
                                            {{ $orgSettings->allow_manual_shift_selection ?? false ? 'checked' : '' }}>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                @if($orgSettings && $orgSettings->allow_manual_shift_selection)
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Require Manager Approval</h6>
                                            <p class="text-muted small mb-0">
                                                Manual shift changes require manager approval before taking effect
                                            </p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox"
                                                   wire:click="toggleApprovalRequired"
                                                {{ $orgSettings->require_approval_for_manual_shift_change ?? false ? 'checked' : '' }}>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                @endif

                            </div>
                        </div>

                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="mt-3 tab-pane fade {{ $activeShiftTab === 'assign_employee' ? 'show active' : '' }}"
                         id="tab-assign-employee">
                        <div class="staff-assignment-card">
                            <!-- Header -->
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div class="section-header">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                    <div>
                                        <h2>Staff Assignment</h2>
                                        <p class="text-muted small mb-0">{{ $assigningShift->start_time }}
                                            - {{ $assigningShift->end_time }}</p>
                                    </div>
                                </div>

                                <!-- Save Button (Top Right) -->
                                <div class="d-flex gap-2">
                                    @if($pendingChanges)
                                        <button type="button"
                                                wire:click.prevent="discardChanges"
                                                class="btn btn-outline-secondary btn-md">
                                            <svg width="18" height="18" class="me-2" fill="currentColor"
                                                 viewBox="0 0 24 24">
                                                <path
                                                    d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"
                                                    stroke="currentColor" stroke-width="2" fill="none"/>
                                            </svg>
                                            Discard
                                        </button>
                                    @endif

                                    <button type="button"
                                            wire:click.prevent="saveAssignment"
                                            wire:loading.attr="disabled"
                                            class="btn btn-primary btn-md {{ !$pendingChanges ? 'disabled' : '' }}"
                                        {{ !$pendingChanges ? 'disabled' : '' }}>
                                        <svg width="18" height="18" class="me-2" fill="currentColor"
                                             viewBox="0 0 24 24">
                                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"
                                                  fill="none" stroke="currentColor" stroke-width="2"/>
                                            <polyline points="17 21 17 13 7 13 7 21" stroke="currentColor"
                                                      stroke-width="2"/>
                                            <polyline points="7 3 7 8 15 8" stroke="currentColor" stroke-width="2"/>
                                        </svg>
                                        <span wire:loading.remove wire:target="saveAssignment">
            @if($pendingChanges)
                                                Save  ({{ count($assignedStaffIds) }})
                                            @else
                                                No Changes
                                            @endif
        </span>
                                        <span wire:loading wire:target="saveAssignment">Saving...</span>
                                    </button>
                                </div>

                            </div>

                            @if (session()->has('success'))
                                <div class="alert-success">
                                    {{ session('success') }}
                                </div>
                            @endif

                            @if (session()->has('error'))
                                <div class="alert-error">
                                    {{ session('error') }}
                                </div>
                            @endif

                            <!-- Search Bar -->
                            <div class="search-wrapper">
                                <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <input type="text"
                                       wire:model="searchTerm"
                                       wire:keyup="$dispatch('searchStaff')"
                                       placeholder="Search by name, department, or current shift..."
                                       class="search-input"/>
                            </div>

                            <!-- Summary Stats -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"
                                                 style="width: 48px; height: 48px; background: transparent; display: flex; align-items: center; justify-content: center;">
                                                <svg width="32" height="32" fill="none" stroke="#FF6B6B"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                                    <circle cx="9" cy="7" r="4"/>
                                                    <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                                                    <path d="M17 7a4 4 0 010 7.87"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div class="text-muted small fw-semibold">Total Staff</div>
                                                <div class="h3 mb-0 fw-bold"
                                                     style="color: #FF6B6B;">{{ $availableStaff->count() }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"
                                                 style="width: 48px; height: 48px; background: transparent; display: flex; align-items: center; justify-content: center;">
                                                <svg width="32" height="32" fill="none" stroke="#4CAF50"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div class="text-muted small fw-semibold">Assigned</div>
                                                <div class="h3 mb-0 fw-bold"
                                                     style="color: #4CAF50;">{{ count($assignedStaffIds) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3"
                                                 style="width: 48px; height: 48px; background: transparent; display: flex; align-items: center; justify-content: center;">
                                                <svg width="32" height="32" fill="none" stroke="#2196F3"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <polyline points="12 6 12 12 16 14"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div class="text-muted small fw-semibold">Unassigned</div>
                                                <div class="h3 mb-0 fw-bold"
                                                     style="color: #2196F3;">{{ $availableStaff->count() - count($assignedStaffIds) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Single Unified Staff List -->
                            <div class="staff-list" style="max-height: 600px;">
                                @forelse($availableStaff as $staff)
                                    @php
                                        $isAssignedToThisShift = in_array($staff->id, $assignedStaffIds);

                                        // Count ALL active shifts for this employee
                                        $totalShiftCount = DB::table('employee_shift_assignments')
                                            ->where('employee_id', $staff->id)
                                            ->where('is_active', true)
                                            ->count();

                                        // Count shifts EXCLUDING the current one (for status dot logic)
                                        $otherShiftsCount = DB::table('employee_shift_assignments')
                                            ->where('employee_id', $staff->id)
                                            ->where('is_active', true)
                                            ->where('shift_id', '!=', $this->selectedShift['id'])
                                            ->count();

                                        $hasOtherShifts = $otherShiftsCount > 0;
                                    @endphp

                                    <div class="staff-item {{ $isAssignedToThisShift ? 'assigned-item' : '' }}">
                                        <div class="staff-item-content">
                                            <!-- Status Indicator Dot -->
                                            <div class="status-indicator-wrapper me-2">
                                                @if($isAssignedToThisShift)
                                                    <div class="status-dot status-assigned"
                                                         data-bs-toggle="tooltip"
                                                         title="Assigned to this shift"></div>
                                                @elseif($hasOtherShifts)
                                                    <div class="status-dot status-other-shift"
                                                         data-bs-toggle="tooltip"
                                                         title="Assigned to {{ $otherShiftsCount }} other shift(s)"></div>
                                                @else
                                                    <div class="status-dot status-unassigned"
                                                         data-bs-toggle="tooltip"
                                                         title="Not assigned to any shift"></div>
                                                @endif
                                            </div>

                                            <!-- Avatar -->
                                            <div
                                                class="{{ $isAssignedToThisShift ? 'staff-avatar-assigned text-success assigned' : 'staff-avatar-unassigned text-primary' }}">
                                                {{ $this->getInitials($staff->name) }}
                                            </div>

                                            <!-- Staff Info -->
                                            <div class="staff-info">
                                                <div class="d-flex align-items-center mb-1">
                                                    <div class="staff-name me-2">{{ $staff->name }}</div>
                                                </div>

                                                <!-- Staff Details -->
                                                <div class="staff-details">
    <span class="me-3">
        <svg width="14" height="14" class="me-1" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
        </svg>
        {{ $staff->department->name }}
    </span>

                                                    @if($isAssignedToThisShift)
                                                        <span class="badge text-success">
            <svg width="12" height="12" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2"/>
            </svg>
            Assigned
        </span>
                                                    @else
                                                        <span class="badge text-primary">Not Assigned</span>
                                                    @endif
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div class="d-flex align-items-center gap-2">
                                                <!-- Multi-Shift Manager Button -->
                                                @if($orgSettings && $orgSettings->allow_auto_shift_detection)
                                                    <button wire:click.stop="openMultiShiftModal({{ $staff->id }})"
                                                            class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="tooltip"
                                                            title="Manage all shifts for this employee">
                                                        <svg width="16" height="16" fill="currentColor"
                                                             viewBox="0 0 24 24">
                                                            <circle cx="12" cy="12" r="10" fill="none"
                                                                    stroke="currentColor" stroke-width="2"/>
                                                            <path d="M12 6v6l4 2" stroke="currentColor"
                                                                  stroke-width="2"/>
                                                        </svg>
                                                        @if($totalShiftCount > 0)
                                                            <span
                                                                class="badge bg-primary ms-1">{{ $totalShiftCount }}</span>
                                                        @else
                                                            <span class="ms-1">Manage</span>
                                                        @endif
                                                    </button>
                                                @endif

                                                <!-- Assign/Remove Button -->
                                                @if($isAssignedToThisShift)
                                                    <button
                                                        wire:click="removeStaff({{ $staff->id }})"
                                                        class="delete-btn mw-3 mb-1 btn btn-outline-danger p-1 d-flex align-items-center justify-content-center"
                                                        title="Remove staff">
                                                        <svg width="16" height="16" fill="none" stroke="currentColor"
                                                             stroke-width="2"
                                                             viewBox="0 0 24 24">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14H6L5 6"/>
                                                            <path d="M10 11v6"/>
                                                            <path d="M14 11v6"/>
                                                            <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                                                        </svg>
                                                    </button>
                                                @else
                                                    <button
                                                        wire:click="assignStaff({{ $staff->id }})"
                                                        class="btn border-primary btn-sm"
                                                        title="Assign staff">
                                                        <svg width="16" height="16"
                                                             fill="none"
                                                             stroke="orange"
                                                             stroke-width="2"
                                                             viewBox="0 0 20 20"
                                                             stroke-linecap="round"
                                                             stroke-linejoin="round">
                                                            <line x1="4" y1="10" x2="16" y2="10"/>
                                                            <line x1="10" y1="4" x2="10" y2="16"/>
                                                        </svg>
                                                    </button>
                                                @endif

                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="empty-state">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                        </svg>
                                        <p class="empty-state-text">No staff found</p>
                                        <p class="empty-state-subtext">Try adjusting your search or add new staff
                                            members</p>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>


    <!-- Pattern Modal -->
    @if($showPatternModal)
        <div class="modal-backdrop-custom" wire:click.self="$set('showPatternModal', false)">
            <div class="modal-content-custom">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">
                        <svg width="20" height="20" class="text-primary me-2" fill="currentColor" viewBox="0 0 24 24">
                            <polyline points="23 4 23 10 17 10" fill="none" stroke="currentColor" stroke-width="2"/>
                            <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10" fill="none" stroke="currentColor"
                                  stroke-width="2"/>
                        </svg>
                        Configure Shift Pattern
                    </h5>
                    <button wire:click="$set('showPatternModal', false)" class="btn btn-close"></button>
                </div>

                <div class="mb-4">
                    <h6 class="mb-3">Pattern Templates</h6>
                    <div class="row g-3">
                        @foreach($shiftPatterns as $pattern)
                            <div class="col-md-6">
                                <button type="button"
                                        wire:click="handlePatternChange('{{ $pattern['id'] }}')"
                                        class="pattern-template-btn {{ $selectedShift['pattern'] == $pattern['id'] ? 'active' : '' }}">
                                    <div class="fw-bold">{{ $pattern['name'] }}</div>
                                    <div class="small text-muted">{{ $pattern['description'] }}</div>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if(in_array($selectedShift['pattern'], ['custom', 'rotating']))
                    <div class="mb-4">
                        <h6 class="mb-3">Select Active Days</h6>
                        <div class="row g-2">
                            @foreach($dayAbbreviations as $index => $day)
                                <div class="col">
                                    <button type="button"
                                            wire:click="togglePatternDay('{{ $day }}')"
                                            class="day-btn w-100 {{ in_array($day, $selectedShift['patternDays']) ? 'active' : '' }}"
                                            style="padding: 0.75rem;">
                                        <div class="fw-bold">{{ $day }}</div>
                                        <div style="font-size: 0.65rem; margin-top: 0.25rem;">
                                            {{ substr($dayNames[$index], 0, 3) }}
                                        </div>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($selectedShift['pattern'] == 'rotating')
                    <div class="alert alert-light border border-primary mb-4">
                        <h6 class="text-primary mb-2">Rotating Schedule Info</h6>
                        <p class="small mb-0">
                            Rotating schedules cycle through different shift assignments. Configure the days this shift
                            is active,
                            and the system will automatically rotate employees through different shifts based on your
                            rotation rules.
                        </p>
                    </div>
                @endif

                <div class="alert alert-light border mb-4">
                    <h6 class="mb-2">Pattern Preview</h6>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="small">
                            <strong>Selected Days:</strong> {{ implode(', ', $selectedShift['patternDays']) ?: 'None' }}
                        </div>
                        <div class="small">
                            <strong>Days per week:</strong> {{ count($selectedShift['patternDays']) }}
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 pt-3 border-top">
                    <button wire:click="$set('showPatternModal', false)" class="btn btn-primary flex-fill">
                        Apply Pattern
                    </button>
                    <button wire:click="$set('showPatternModal', false)" class="btn btn-secondary flex-fill">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Add Shift Modal -->
    @if($showAddShift)
        <div class="modal-backdrop-custom" wire:click.self="$set('showAddShift', false)">
            <div class="modal-content-custom" style="max-width: 500px;">
                <h5 class="mb-3">Add New Shift</h5>
                <p class="text-muted mb-4">Create a new shift configuration with default settings.</p>

                <div class="d-flex gap-2">
                    <button wire:click="addNewShift" class="btn btn-primary flex-fill">
                        Create Shift
                    </button>
                    <button wire:click="$set('showAddShift', false)" class="btn btn-secondary flex-fill">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Multi-Shift Assignment Modal -->
    <!-- Multi-Shift Assignment Modal -->
    @if($showMultiShiftModal && $selectedEmployeeForMultiShift)
        <div class="modal-backdrop-custom" wire:click.self="$set('showMultiShiftModal', false)">
            <div class="modal-content-custom" style="max-width: 1200px;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="mb-1">
                            <svg width="20" height="20" class="text-primary me-2" fill="currentColor"
                                 viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                                <path d="M17 7a4 4 0 010 7.87"/>
                            </svg>
                            Multi-Shift Assignment
                        </h5>
                        <p class="text-muted small mb-0">
                            Employee: <strong>{{ $selectedEmployeeForMultiShift->name }}</strong>
                            • Department:
                            <strong>{{ $selectedEmployeeForMultiShift->department->name ?? 'N/A' }}</strong>
                        </p>
                    </div>
                    <button wire:click="$set('showMultiShiftModal', false)" class="btn btn-close"></button>
                </div>

                <div class="row g-4">
                    <!-- Left Column: Add New Shift -->
                    <div class="col-md-5">
                        <div class="config-section mb-3">
                            <div class="section-header">
                                <h6 class="mb-0">
                                    <svg width="16" height="16" class="text-primary me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/>
                                        <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                    Add New Shift Assignment
                                </h6>
                            </div>
                            <div class="section-body">
                                <div style="margin-top:-30px;" class="mb-3">
                                    <label class="form-label fw-bold">Select Shift</label>
                                    <select wire:model="newShiftAssignment.shift_id" class="form-select form-select-md">
                                        <option value="">-- Choose a Shift --</option>
                                        @foreach($shifts as $shift)
                                            <option value="{{ $shift['id'] }}">
                                                {{ $shift['name'] }} ({{ $shift['startTime'] }}
                                                - {{ $shift['endTime'] }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Priority Level</label>
                                    <input type="number"
                                           wire:model="newShiftAssignment.priority"
                                           class="form-control form-control-md"
                                           min="0"
                                           max="999"
                                           placeholder="Enter priority (0-999)">
                                    <small class="text-muted">Higher number = Higher priority. Used for shift
                                        detection.</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Effective From (Optional)</label>
                                    <input type="date"
                                           wire:model="newShiftAssignment.effective_from"
                                           class="form-control form-control-md">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Effective Until (Optional)</label>
                                    <input type="date"
                                           wire:model="newShiftAssignment.effective_until"
                                           class="form-control form-control-md">
                                </div>

                                <button wire:click="addShiftToEmployee" class="btn btn-primary btn-md w-100">
                                    <svg width="18" height="18" class="me-2" fill="currentColor" viewBox="0 0 24 24">
                                        <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/>
                                        <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                    Add Shift to Employee
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Current Shifts -->
                    <div class="col-md-7">
                        <div class="config-section">
                            <div class="section-header">
                                <h6 class="mb-0">
                                    <svg width="16" height="16" class="text-success me-2" fill="currentColor"
                                         viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                stroke-width="2"/>
                                        <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                    Current Shift Assignments ({{ count($employeeMultiShifts) }})
                                </h6>
                            </div>
                            <div style="margin-top:-30px;" class="section-body">
                                @if(count($employeeMultiShifts) === 0)
                                    <div class="text-center py-5 text-muted">
                                        <svg width="64" height="64" class="mb-3 opacity-50" fill="currentColor"
                                             viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                    stroke-width="2"/>
                                            <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor"
                                                  stroke-width="2"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16" stroke="currentColor"
                                                  stroke-width="2"/>
                                        </svg>
                                        <p class="mb-1 fw-bold">No shifts assigned yet</p>
                                        <p class="small">Add a shift using the form on the left</p>
                                    </div>
                                @else
                                    <div style="max-height: 500px; overflow-y: auto;">
                                        @foreach($employeeMultiShifts as $index => $shift)
                                            <div class="shift-assignment-item border rounded p-3 mb-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-2">
                                                            <div class="badge bg-secondary me-2">#{{ $index + 1 }}</div>
                                                            <h6 class="mb-0 me-3">{{ $shift['name'] }}</h6>
                                                            <span
                                                                class="badge bg-primary">Priority: {{ $shift['priority'] }}</span>
                                                            @if($shift['is_active'])
                                                                <span class="badge bg-success ms-2">Active</span>
                                                            @else
                                                                <span class="badge bg-secondary ms-2">Inactive</span>
                                                            @endif
                                                        </div>

                                                        <div class="row g-2 small text-muted">
                                                            <div class="col-md-6">
                                                                <div class="mb-2">
                                                                    <strong>⏰ Time:</strong> {{ $shift['start_time'] }}
                                                                    - {{ $shift['end_time'] }}
                                                                </div>
                                                            </div>
                                                            @if($shift['effective_from'] || $shift['effective_until'])
                                                                <div class="col-md-6">
                                                                    <div class="mb-2">
                                                                        <strong>📅 Effective Period:</strong><br>
                                                                        {{ $shift['effective_from'] ? \Carbon\Carbon::parse($shift['effective_from'])->format('M d, Y') : 'Start' }}
                                                                        →
                                                                        {{ $shift['effective_until'] ? \Carbon\Carbon::parse($shift['effective_until'])->format('M d, Y') : 'End' }}
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <button
                                                        wire:click="removeShiftFromEmployee({{ $shift['id'] }})"
                                                        wire:confirm="Are you sure you want to remove this shift assignment?"
                                                        class="btn btn-sm btn-outline-danger ms-3"
                                                        title="Remove shift">
                                                        <svg width="16" height="16" fill="none" stroke="currentColor"
                                                             stroke-width="2"
                                                             viewBox="0 0 24 24">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14H6L5 6"/>
                                                            <path d="M10 11v6"/>
                                                            <path d="M14 11v6"/>
                                                            <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <!-- Summary Info -->
                                    <div class="alert alert-info mt-3 mb-0">
                                        <div class="d-flex align-items-center">
                                            <svg width="20" height="20" class="me-2" fill="currentColor"
                                                 viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                        stroke-width="2"/>
                                                <path d="M12 16v-4m0-4h.01" stroke="currentColor" stroke-width="2"/>
                                            </svg>
                                            <div>
                                                <strong>Total Assigned
                                                    Shifts: {{ count($employeeMultiShifts) }}</strong>
                                                <p class="mb-0 small">The system will use priority to determine the
                                                    active shift when multiple shifts overlap.</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 pt-4 border-top mt-4">
                    <button wire:click="$set('showMultiShiftModal', false)" class="btn btn-secondary btn-lg flex-fill">
                        <svg width="16" height="16" class="me-2" fill="currentColor" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
                            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('#shift-tabs button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    let mappedTab;
                    switch (tabId) {
                        case 'tab-shift-settings-tab':
                            mappedTab = 'shift_settings';
                            break;
                        case 'tab-assign-employee-tab':
                            mappedTab = 'assign_employee';
                            break;
                        default:
                            mappedTab = 'shift_settings';
                    }

                    // Changed event name from 'tabChanged' to 'shiftTabChanged'
                    Livewire.dispatch('shiftTabChanged', {tabId: mappedTab});
                });
            });
        });
    </script>
@endpush
