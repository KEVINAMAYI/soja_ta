<?php

use App\Models\Employee;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\Attendancebreaklog;

new class extends Component {


    public $shifts = [];
    public $selectedShift = [];
    public $showPatternModal = false;
    public $showAddShift = false;
    public string $activeShiftTab = 'shift_settings';
    public string $tabTitle;
    public string $tabIcon;
    public $assignedStaffIds = [];
    public $availableStaff = [];
    public $assignedStaff = [];
    public $assigningShift;
    public $searchTerm = '';

    // NEW: Break management properties
    public $breaks = [];
    public $editingBreakIndex = null;
    public $showAddBreakModal = false;
    public $currentBreak = [];

    public $breakTypes = [
        ['value' => 'paid', 'label' => 'Paid Break', 'description' => 'Time counted toward working hours'],
        ['value' => 'unpaid', 'label' => 'Unpaid Break', 'description' => 'Time deducted from working hours'],
        ['value' => 'flexible', 'label' => 'Flexible Break', 'description' => 'Can be taken anytime within shift'],
    ];

    public $penaltyTypes = [
        ['value' => 'none', 'label' => 'No Penalty', 'description' => 'Track only, no automatic action'],
        ['value' => 'deduct_overtime', 'label' => 'Deduct Overtime Minutes', 'description' => 'Excess time deducted from overtime'],
        ['value' => 'flag_review', 'label' => 'Flag for Manager Review', 'description' => 'Manager must review and approve'],
        ['value' => 'auto_deduct', 'label' => 'Auto-deduct from Hours', 'description' => 'Automatically reduce worked hours'],
    ];

    public $shiftPatterns = [
        ['id' => 'weekdays', 'name' => 'Weekdays Only', 'description' => 'Monday to Friday'],
        ['id' => 'weekends', 'name' => 'Weekends Only', 'description' => 'Saturday and Sunday'],
        ['id' => 'daily', 'name' => 'Daily', 'description' => 'All 7 days of the week'],
        ['id' => 'rotating', 'name' => 'Rotating Schedule', 'description' => 'Custom rotation pattern'],
        ['id' => 'custom', 'name' => 'Custom Days', 'description' => 'Select specific days']
    ];

    public $dayAbbreviations = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    public $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function rules()
    {
        return [
            'assignedStaffIds' => 'array',
            'assignedStaffIds.*' => 'exists:employees,id',
            'currentBreak.name' => 'required|string|max:255',
            'currentBreak.type' => 'required|in:paid,unpaid,flexible',
            'currentBreak.duration_minutes' => 'required|integer|min:1|max:480',
            'currentBreak.max_duration_minutes' => 'nullable|integer|min:1|max:480',
            'currentBreak.window_start_time' => 'nullable|date_format:H:i',
            'currentBreak.window_end_time' => 'nullable|date_format:H:i',
            'currentBreak.penalty_type' => 'required|in:none,deduct_overtime,flag_review,auto_deduct',
        ];
    }

    public function mount()
    {
        $this->loadShifts();


        // After saving — now reload truth from DB
        $this->assignedStaffIds = Employee::where('shift_id', $this->selectedShift['id'])
            ->pluck('id')
            ->toArray();

        $this->assigningShift = Shift::findOrFail($this->selectedShift['id']);

        $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
            ->with('shift')
            ->get();

        $this->searchEmployees();

        $this->loadBreaks();

    }

    public function loadBreaks()
    {
        if (!isset($this->selectedShift['id'])) {
            $this->breaks = [];
            return;
        }

        $dbBreaks = ShiftBreak::where('shift_id', $this->selectedShift['id'])
            ->ordered()
            ->get();

        $this->breaks = $dbBreaks->map(function ($break) {
            return [
                'id' => $break->id,
                'name' => $break->name,
                'type' => $break->type,
                'window_start_time' => $break->window_start_time ? Carbon::parse($break->window_start_time)->format('H:i') : null,
                'window_end_time' => $break->window_end_time ? Carbon::parse($break->window_end_time)->format('H:i') : null,
                'duration_minutes' => $break->duration_minutes,
                'max_duration_minutes' => $break->max_duration_minutes,
                'penalty_type' => $break->penalty_type,
                'require_punch' => $break->require_punch,
                'notify_on_approaching' => $break->notify_on_approaching,
                'notify_minutes_before' => $break->notify_minutes_before,
                'is_mandatory' => $break->is_mandatory,
                'order' => $break->order,
                'is_active' => $break->is_active,
            ];
        })->toArray();
    }

    public function openAddBreakModal()
    {
        $this->resetBreakForm();
        $this->showAddBreakModal = true;
    }

    public function openEditBreakModal($index)
    {
        $this->editingBreakIndex = $index;
        $this->currentBreak = $this->breaks[$index];
        $this->showAddBreakModal = true;
    }

    public function resetBreakForm()
    {
        $this->currentBreak = [
            'name' => '',
            'type' => 'unpaid',
            'window_start_time' => null,
            'window_end_time' => null,
            'duration_minutes' => 30,
            'max_duration_minutes' => null,
            'penalty_type' => 'none',
            'require_punch' => false,
            'notify_on_approaching' => false,
            'notify_minutes_before' => null,
            'is_mandatory' => false,
            'is_active' => true,
        ];
        $this->editingBreakIndex = null;
    }


    private function recalculateAndSaveDuration(): void
    {
        if (!isset($this->selectedShift['id'])) {
            return;
        }

        $startTime = $this->selectedShift['startTime'] ?? null;
        $endTime = $this->selectedShift['endTime'] ?? null;

        if (!$startTime || !$endTime) {
            return;
        }

        try {

            $start = Carbon::parse($startTime);
            $end = Carbon::parse($endTime);

            // Handle overnight shifts
            if ($end->lt($start)) {
                $end->addDay();
            }

            $rawMinutes = $start->diffInMinutes($end);

            // Sum only ACTIVE, UNPAID breaks — these are the ones that eat into work time
            $unpaidBreakMinutes = ShiftBreak::where('shift_id', $this->selectedShift['id'])
                ->where('is_active', true)
                ->where('type', '!=', 'paid')
                ->sum('duration_minutes');

            $effectiveMinutes = max(0, $rawMinutes - $unpaidBreakMinutes);
            $effectiveHours = round($effectiveMinutes / 60, 2);

            // Persist to shifts table
            Shift::where('id', $this->selectedShift['id'])
                ->update(['duration_hours' => $effectiveHours]);

            // Keep the in-memory selectedShift in sync so the UI reflects it immediately
            $this->selectedShift['duration'] = $effectiveHours;

            // Also update the matching entry in $this->shifts array
            foreach ($this->shifts as &$shift) {
                if ($shift['id'] === $this->selectedShift['id']) {
                    $shift['duration'] = $effectiveHours;
                    break;
                }
            }
            unset($shift); // clear reference

        } catch (\Throwable $e) {
            // Fail silently — the next full loadShifts() will self-correct
        }
    }


    public function saveBreak()
    {
        $this->validate([
            'currentBreak.name' => 'required|string|max:255',
            'currentBreak.type' => 'required|in:paid,unpaid,flexible',
            'currentBreak.duration_minutes' => 'required|integer|min:1|max:480',
        ]);

        try {
            if ($this->editingBreakIndex !== null) {
                // Update existing break
                $breakId = $this->breaks[$this->editingBreakIndex]['id'] ?? null;

                if ($breakId) {
                    $break = ShiftBreak::find($breakId);
                    if ($break) {
                        $break->update($this->currentBreak);
                    }
                }
            } else {
                // Create new break
                $orderMax = ShiftBreak::where('shift_id', $this->selectedShift['id'])->max('order') ?? 0;

                ShiftBreak::create(array_merge($this->currentBreak, [
                    'shift_id' => $this->selectedShift['id'],
                    'order' => $orderMax + 1,
                ]));
            }

            $this->recalculateAndSaveDuration();
            $this->loadBreaks();
            $this->loadShifts(); // Refresh shift data
            $this->selectShift($this->selectedShift['id']); // Reselect to update display

            $this->showAddBreakModal = false;
            $this->resetBreakForm();

            LivewireAlert::title('Success!')
                ->text($this->editingBreakIndex !== null ? 'Break updated successfully!' : 'Break added successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to save break: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function deleteBreak($index)
    {
        try {
            $breakId = $this->breaks[$index]['id'] ?? null;

            if ($breakId) {
                $break = ShiftBreak::find($breakId);
                if ($break) {
                    $break->delete();

                    $this->recalculateAndSaveDuration();
                    $this->loadBreaks();
                    $this->loadShifts();

                    LivewireAlert::title('Success!')
                        ->text('Break deleted successfully!')
                        ->success()
                        ->toast()
                        ->position('top-end')
                        ->show();
                }
            }
        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to delete break: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function toggleBreakActive($index)
    {
        try {
            $breakId = $this->breaks[$index]['id'] ?? null;

            if ($breakId) {
                $break = ShiftBreak::find($breakId);
                if ($break) {
                    $break->update(['is_active' => !$break->is_active]);
                    $this->recalculateAndSaveDuration();
                    $this->loadBreaks();
                }
            }
        } catch (\Exception $e) {
            // Handle error silently or show notification
        }
    }

    public function moveBreakUp($index)
    {
        if ($index > 0) {
            $this->swapBreakOrder($index, $index - 1);
        }
    }

    public function moveBreakDown($index)
    {
        if ($index < count($this->breaks) - 1) {
            $this->swapBreakOrder($index, $index + 1);
        }
    }

    private function swapBreakOrder($index1, $index2)
    {
        $break1Id = $this->breaks[$index1]['id'] ?? null;
        $break2Id = $this->breaks[$index2]['id'] ?? null;

        if ($break1Id && $break2Id) {
            $break1 = ShiftBreak::find($break1Id);
            $break2 = ShiftBreak::find($break2Id);

            if ($break1 && $break2) {
                $tempOrder = $break1->order;
                $break1->update(['order' => $break2->order]);
                $break2->update(['order' => $tempOrder]);

                $this->loadBreaks();
            }
        }
    }

    public function getBreakTypeLabel($type)
    {
        return collect($this->breakTypes)->firstWhere('value', $type)['label'] ?? ucfirst($type);
    }

    public function getPenaltyTypeLabel($type)
    {
        return collect($this->penaltyTypes)->firstWhere('value', $type)['label'] ?? 'None';
    }

    /**
     * Calculate total break time
     */
    public function getTotalBreakMinutes()
    {
        return collect($this->breaks)
            ->where('is_active', true)
            ->where('type', '!=', 'paid')
            ->sum('duration_minutes');
    }

    /**
     * Calculate effective shift duration
     */
    public function getEffectiveShiftDuration()
    {
        if (!isset($this->selectedShift['startTime']) || !isset($this->selectedShift['endTime'])) {
            return 0;
        }

        try {
            $start = Carbon::parse($this->selectedShift['startTime']);
            $end = Carbon::parse($this->selectedShift['endTime']);

            if ($end->lt($start)) {
                $end->addDay();
            }

            $totalMinutes = $start->diffInMinutes($end);
            $breakMinutes = $this->getTotalBreakMinutes();
            $effectiveMinutes = max(0, $totalMinutes - $breakMinutes);

            return round($effectiveMinutes / 60, 2);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function loadShifts()
    {
        // Load from database
        $organizationId = auth()->user()->employee->organization_id;

        $dbShifts = Shift::where('organization_id', $organizationId)
            ->withCount('employees')
            ->orderBy('created_at', 'asc')
            ->get();

        $this->shifts = $dbShifts->map(function ($shift) {
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
                'employees' => $shift->employees_count,
                'pattern' => $shift->pattern_type,
                'patternDays' => $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'notifyManagers' => $shift->notify_managers_overtime,
                'mobileNotifications' => $shift->employee_mobile_notifications,
                'emailSummaries' => $shift->email_summaries,
                // New grace period fields
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

    public function selectShift($shiftId)
    {
        if (!$shiftId) return;

        $key = array_search($shiftId, array_column($this->shifts, 'id'));

        if ($key !== false) {
            $this->selectedShift = $this->shifts[$key];

            // Use find() instead of findOrFail() to handle race conditions gracefully
            $this->assigningShift = Shift::find($shiftId);

            if (!$this->assigningShift) {
                $this->loadShifts();
                return;
            }

            $this->assignedStaffIds = Employee::where('shift_id', $shiftId)
                ->pluck('id')
                ->toArray();

            $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
                ->with('shift')
                ->get();

            $this->searchEmployees();
        }
    }


// Alternative: If you want to add more flexibility
    #[On('searchStaff')]
    public function searchEmployees()
    {
        $organizationId = auth()->user()->employee->organization_id;

        $query = Employee::query()
            ->where('organization_id', $organizationId);

        // Apply search only if searchTerm exists and is not empty
        if (!empty($this->searchTerm)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->searchTerm . '%')
                    ->orWhereHas('shift', function ($sq) {
                        $sq->where('name', 'like', '%' . $this->searchTerm . '%');
                    })
                    ->orWhereHas('department', function ($sq) {
                        $sq->where('name', 'like', '%' . $this->searchTerm . '%');
                    });
            });
        }

        $this->availableStaff = $query->with('shift')->get();
    }

    public function getAssignedStaff()
    {

        $this->shiftId = $this->selectedShift['id'];
        $this->assigningShift = Shift::findOrFail($this->shiftId);

        $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
            ->with('shift')
            ->get();

    }


    public function assignStaff($staffId)
    {
        if (!in_array($staffId, $this->assignedStaffIds)) {
            $this->assignedStaffIds[] = $staffId;
        }

        $this->getAssignedStaff();

    }

    public function removeStaff($staffId)
    {
        $this->assignedStaffIds = array_values(
            array_filter($this->assignedStaffIds, fn($id) => $id != $staffId)
        );

        $this->getAssignedStaff();

    }

    public function saveAssignment()
    {

        $this->validate();

        try {

            // Get organization_id
            $organizationId = auth()->user()->employee->organization_id;
            $currentShiftId = $this->selectedShift['id'];

            Employee::where('organization_id', $organizationId)
                ->where('shift_id', $currentShiftId)
                ->whereNotIn('id', $this->assignedStaffIds)
                ->update([
                    'shift_id' => null,
                    'shift_status' => 'off_shift'
                ]);

            Employee::where('organization_id', $organizationId)
                ->whereIn('id', $this->assignedStaffIds)
                ->update([
                    'shift_id' => $currentShiftId,
                    'shift_status' => 'on_shift'
                ]);

            $this->getAssignedStaff();
            $this->searchEmployees();

            LivewireAlert::title('Awesome!')
                ->text('Staff assignment saved successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();


        } catch (\Exception $e) {

            LivewireAlert::title('Error!')
                ->text('Failed to save assignment!')
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
                // New grace period fields
                'grace_period_enabled' => $this->selectedShift['gracePeriodEnabled'] ?? true,
                'grace_period_minutes' => $this->selectedShift['gracePeriodMinutes'] ?? 15,
                'track_late_checkin' => $this->selectedShift['trackLateCheckin'] ?? true,
                'notify_on_late_checkin' => $this->selectedShift['notifyOnLateCheckin'] ?? false,
                'track_early_checkout' => $this->selectedShift['trackEarlyCheckout'] ?? true,
                'early_checkout_threshold_minutes' => $this->selectedShift['earlyCheckoutThreshold'] ?? 15,

            ]);

            // Reload shifts to reflect changes
            $this->loadShifts();

            // Re-select the current shift
            $this->selectShift($shift->id);

            LivewireAlert::title('Awesome!')
                ->text('Shift saved successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->loadShifts();

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
            // New grace period defaults
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

        LivewireAlert::title('Awesome!')
            ->text('New Shift Added successfully!')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function deleteShift()
    {
        $shift = Shift::find($this->selectedShift['id']);

        if ($shift) {

            // Check if shift has employees
            if ($shift->employees()->count() > 0) {

                LivewireAlert::title('Error!')
                    ->text('Cannot delete shift with assigned employees. Please reassign employees first.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();

                return;
            }

            $shiftId = $shift->id;
            $shift->delete();

            // Reload shifts FIRST
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
    public function calculateShiftDuration(): void
    {
        if (!isset($this->selectedShift['startTime']) || !isset($this->selectedShift['endTime'])) {
            return;
        }

        try {
            $start = \Carbon\Carbon::parse($this->selectedShift['startTime']);
            $end = \Carbon\Carbon::parse($this->selectedShift['endTime']);

            if ($end->lt($start)) {
                $end->addDay();
            }

            $rawMinutes = $start->diffInMinutes($end);

            // Re-use the same logic — unpaid active breaks reduce effective hours
            $unpaidBreakMinutes = collect($this->breaks)
                ->where('is_active', true)
                ->where('type', '!=', 'paid')
                ->sum('duration_minutes');

            $effectiveMinutes = max(0, $rawMinutes - $unpaidBreakMinutes);

            $this->selectedShift['duration'] = round($effectiveMinutes / 60, 2);

        } catch (\Exception $e) {
            // Fail silently
        }

    }

    #[On('shiftTabChanged')]
    public function shiftTabChanged($tabId)
    {

        $this->activeShiftTab = $tabId;  // ✅ CORRECT
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
            background-color: #d1e7dd;
            border-color: #badbcc;
        }

        .staff-item-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .staff-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .staff-avatar.assigned {
            background: linear-gradient(135deg, #198754 0%, #20c997 100%);
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
            background-color: #cfe2ff;
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

        .breaks-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .break-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            transition: all 0.3s ease;
        }

        .break-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .break-item.break-inactive {
            background-color: #f8f9fa;
            opacity: 0.6;
        }

        .drag-handle {
            cursor: grab;
            color: #adb5bd;
            padding: 0.25rem;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        .break-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .break-icon.paid {
            background: linear-gradient(135deg, #198754 0%, #20c997 100%);
        }

        .break-icon.unpaid {
            background: linear-gradient(135deg, #6c757d 0%, #adb5bd 100%);
        }

        .break-icon.flexible {
            background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
        }

        .toggle-switch-small {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
        }

        .toggle-switch-small input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider-small {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #6c757d;
            transition: .4s;
            border-radius: 20px;
        }

        .toggle-slider-small:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider-small {
            background-color: #0d6efd;
        }

        input:checked + .toggle-slider-small:before {
            transform: translateX(16px);
        }

        .form-check-card {
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            transition: all 0.2s ease;
            cursor: pointer;
            height: 100%;
        }

        .form-check-card:hover {
            border-color: #adb5bd;
        }

        .form-check-card input[type="radio"]:checked ~ label,
        .form-check-card:has(input[type="radio"]:checked) {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }

        .form-check-card .form-check-input {
            margin-top: 0.2rem;
        }

        /* Modal Improvements */
        .modal-content-custom {
            max-height: 90vh;
            overflow-y: auto;
        }

        /* Break Summary Cards */
        .card.border-0 {
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Alert Variants */
        .alert-info {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .break-item .row {
                font-size: 0.875rem;
            }

            .break-item .d-flex.gap-2 {
                flex-wrap: wrap;
            }

            .modal-content-custom {
                width: 95%;
                padding: 1rem;
            }
        }


        /* ===== OVERLAY ===== */
        .break-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1060;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            animation: breakFadeIn 0.2s ease;
        }

        @keyframes breakFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* ===== MODAL CONTAINER ===== */
        .break-modal-container {
            background: #ffffff;
            border-radius: 1.25rem;
            width: 100%;
            max-width: 1100px;
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0, 0, 0, 0.05);
            animation: breakSlideUp 0.25s ease;
        }

        @keyframes breakSlideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* ===== HEADER ===== */
        .break-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.75rem 2rem;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, #f8faff 0%, #ffffff 100%);
            flex-shrink: 0;
        }

        .break-modal-title-group {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .break-modal-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .break-modal-title {
            font-size: 1.375rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.2rem;
            letter-spacing: -0.02em;
        }

        .break-modal-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0;
        }

        .break-modal-close {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .break-modal-close:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #dc2626;
        }

        /* ===== FORM BODY ===== */
        .break-modal-form {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
        }

        .break-modal-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            flex: 1;
            overflow-y: auto;
        }

        .break-modal-col {
            padding: 1.75rem 2rem;
        }

        .break-modal-col:first-child {
            border-right: 1px solid #f1f5f9;
        }

        /* ===== SECTIONS ===== */
        .break-form-section {
            margin-bottom: 2rem;
        }

        .break-form-section:last-child {
            margin-bottom: 0;
        }

        .break-section-label {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 1rem;
        }

        .break-section-num {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 800;
            color: #475569;
        }

        .break-section-hint {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: -0.5rem 0 0.75rem;
        }

        /* ===== FIELDS ===== */
        .break-field-group {
            margin-bottom: 1rem;
        }

        .break-field-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .break-required {
            color: #ef4444;
            margin-left: 2px;
        }

        .break-optional {
            font-weight: 400;
            color: #94a3b8;
            font-size: 0.8em;
        }

        .break-input {
            width: 100%;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.2s;
            outline: none;
        }

        .break-input:focus {
            border-color: #6366f1;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }

        .break-input-lg {
            padding: 0.85rem 1rem;
            font-size: 1rem;
        }

        .break-field-hint {
            display: block;
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 0.35rem;
        }

        .break-error {
            display: block;
            font-size: 0.78rem;
            color: #ef4444;
            margin-top: 0.35rem;
        }

        /* ===== TYPE CARDS ===== */
        .break-type-grid {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .break-type-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
            position: relative;
        }

        .break-type-card:hover {
            border-color: #a5b4fc;
            background: #fafbff;
        }

        .break-type-card--active {
            background: #eff6ff !important;
            border-color: #6366f1 !important;
        }

        .break-type-radio {
            display: none;
        }

        .break-type-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
        }

        .break-type-card--paid .break-type-icon {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .break-type-card--unpaid .break-type-icon {
            background: linear-gradient(135deg, #64748b, #475569);
        }

        .break-type-card--flexible .break-type-icon {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }

        .break-type-text {
            flex: 1;
        }

        .break-type-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.15rem;
        }

        .break-type-desc {
            font-size: 0.78rem;
            color: #64748b;
        }

        .break-type-check {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: transparent;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .break-type-card--active .break-type-check {
            background: #6366f1;
            color: white;
        }

        /* ===== TIME ROW ===== */
        .break-time-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: end;
            gap: 0.75rem;
        }

        .break-time-separator {
            padding-bottom: 0.7rem;
            color: #cbd5e1;
            display: flex;
            align-items: center;
        }

        .break-time-input-wrap {
            position: relative;
        }

        .break-time-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .break-input-time {
            padding-left: 2.25rem;
        }

        /* ===== DURATION GRID ===== */
        .break-duration-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .break-input-suffix-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .break-input-num {
            padding-right: 3.5rem;
        }

        .break-input-suffix {
            position: absolute;
            right: 0.85rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: #94a3b8;
            pointer-events: none;
        }

        /* ===== PENALTY LIST ===== */
        .break-penalty-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .break-penalty-option {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
        }

        .break-penalty-option:hover {
            border-color: #c7d2fe;
            background: #f5f3ff;
        }

        .break-penalty-option--active {
            border-color: #6366f1 !important;
            background: #eff6ff !important;
        }

        .break-penalty-radio {
            display: none;
        }

        .break-penalty-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            flex-shrink: 0;
            transition: all 0.2s;
            position: relative;
        }

        .break-penalty-option--active .break-penalty-dot {
            border-color: #6366f1;
            background: #6366f1;
            box-shadow: inset 0 0 0 3px white;
        }

        .break-penalty-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
        }

        .break-penalty-desc {
            font-size: 0.775rem;
            color: #64748b;
        }

        /* ===== WARNING BOX ===== */
        .break-warning-box {
            display: flex;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 10px;
            margin-top: 0.75rem;
            color: #92400e;
        }

        .break-warning-box svg {
            flex-shrink: 0;
            margin-top: 2px;
            stroke: #d97706;
        }

        .break-warning-box strong {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .break-warning-box p {
            font-size: 0.8rem;
            margin: 0;
        }

        /* ===== TOGGLE LIST ===== */
        .break-toggle-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .break-toggle-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: border-color 0.2s;
            background: #f8fafc;
        }

        .break-toggle-item:hover {
            border-color: #c7d2fe;
        }

        .break-toggle-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #475569;
        }

        .break-toggle-info svg {
            flex-shrink: 0;
        }

        .break-toggle-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
        }

        .break-toggle-desc {
            font-size: 0.775rem;
            color: #64748b;
        }

        /* ===== SWITCH ===== */
        .break-switch {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .break-switch-input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .break-switch-slider {
            position: absolute;
            inset: 0;
            background: #cbd5e1;
            border-radius: 24px;
            cursor: pointer;
            transition: 0.3s;
        }

        .break-switch-slider:before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .break-switch-input:checked + .break-switch-slider {
            background: #6366f1;
        }

        .break-switch-input:checked + .break-switch-slider:before {
            transform: translateX(20px);
        }

        /* ===== NOTIFY MINUTES ===== */
        .break-notify-minutes {
            margin-top: 0.75rem;
            padding: 0.875rem 1rem;
            background: #f0f9ff;
            border: 1px dashed #7dd3fc;
            border-radius: 10px;
        }

        /* ===== FOOTER ===== */
        .break-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.25rem 2rem;
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
            flex-shrink: 0;
        }

        .break-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.75rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .break-btn-cancel {
            background: white;
            color: #64748b;
            border: 1.5px solid #e2e8f0;
        }

        .break-btn-cancel:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .break-btn-save {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
            padding: 0.7rem 2.25rem;
        }

        .break-btn-save:hover {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.45);
            transform: translateY(-1px);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .break-modal-body {
                grid-template-columns: 1fr;
            }

            .break-modal-col:first-child {
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
            }

            .break-duration-grid {
                grid-template-columns: 1fr 1fr;
            }
        }


        /* ===== BREAK SUMMARY STRIP ===== */
        .break-summary-strip {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.875rem 1.5rem;
            margin-bottom: 1.5rem;
            gap: 0;
        }

        .break-summary-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }

        .break-summary-divider {
            width: 1px;
            height: 36px;
            background: #e2e8f0;
            margin: 0 1.5rem;
            flex-shrink: 0;
        }

        .break-summary-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .break-summary-icon--blue {
            background: #eff6ff;
            color: #3b82f6;
        }

        .break-summary-icon--amber {
            background: #fffbeb;
            color: #f59e0b;
        }

        .break-summary-icon--green {
            background: #f0fdf4;
            color: #22c55e;
        }

        .break-summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .break-summary-unit {
            font-size: 0.75rem;
            font-weight: 500;
            color: #94a3b8;
        }

        .break-summary-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 1px;
        }

        .break-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
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
                                    <div class="col-md-6">
                                        <label class="form-label">Start Time</label>
                                        <input type="time"
                                               wire:input="$dispatch('time-selected')"
                                               wire:model="selectedShift.startTime"
                                               class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">End Time</label>
                                        <input type="time"
                                               wire:input="$dispatch('time-selected')"
                                               wire:model="selectedShift.endTime"
                                               class="form-control">
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


                        <!-- Break & Activity Blocks Configuration -->
                        <div class="config-section">
                            <div class="section-header">
                                <div class="d-flex justify-content-between w-100 align-items-center">
                                    <h5 class="mb-0">
                                        <svg width="20" height="20" class="text-danger me-2" fill="currentColor"
                                             viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                    stroke-width="2"/>
                                            <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2"
                                                  stroke-linecap="round"/>
                                        </svg>
                                        Break & Activity Blocks
                                    </h5>
                                    <button wire:click="openAddBreakModal" class="btn btn-sm btn-primary">
                                        <svg width="16" height="16" fill="none" stroke="white" stroke-width="2"
                                             viewBox="0 0 24 24" class="me-1">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                        Add Break
                                    </button>
                                </div>
                            </div>

                            <div class="section-body">
                                <p class="text-muted small mb-3">
                                    Configure specific time blocks for breaks, lunch, or other activities with automatic
                                    tracking and penalty rules.
                                </p>

                                @if(count($breaks) === 0)
                                    <div class="alert alert-light border text-center py-5">
                                        <svg width="48" height="48" class="text-muted mb-3" fill="none"
                                             stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="10" stroke-width="2"/>
                                            <path d="M12 6v6l4 2" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                        <h6 class="text-muted">No Breaks Configured</h6>
                                        <p class="text-muted small mb-3">Add breaks to track lunch, rest periods, or
                                            other activities during the shift.</p>
                                        <button wire:click="openAddBreakModal" class="btn btn-sm btn-primary">
                                            Add First Break
                                        </button>
                                    </div>
                                @else
                                    <!-- Break Summary -->
                                    <!-- Break Summary Strip -->
                                    <div class="break-summary-strip">
                                        <div class="break-summary-item">
                                            <div class="break-summary-icon break-summary-icon--blue">
                                                <svg width="16" height="16" fill="none" stroke="currentColor"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <path d="M12 6v6l4 2" stroke-linecap="round"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div class="break-summary-value">{{ count($breaks) }}</div>
                                                <div class="break-summary-label">Total Breaks</div>
                                            </div>
                                        </div>

                                        <div class="break-summary-divider"></div>

                                        <div class="break-summary-item">
                                            <div class="break-summary-icon break-summary-icon--amber">
                                                <svg width="16" height="16" fill="none" stroke="currentColor"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <path d="M12 6v6l4 2" stroke-linecap="round"/>
                                                    <line x1="12" y1="2" x2="12" y2="4"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div class="break-summary-value">{{ $this->getTotalBreakMinutes() }}
                                                    <span class="break-summary-unit">min</span></div>
                                                <div class="break-summary-label">Total Break Time</div>
                                            </div>
                                        </div>

                                        <div class="break-summary-divider"></div>

                                        <div class="break-summary-item">
                                            <div class="break-summary-icon break-summary-icon--green">
                                                <svg width="16" height="16" fill="none" stroke="currentColor"
                                                     stroke-width="2" viewBox="0 0 24 24">
                                                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                                                    <circle cx="12" cy="7" r="4"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <div
                                                    class="break-summary-value">{{ $this->getEffectiveShiftDuration() }}
                                                    <span class="break-summary-unit">hrs</span></div>
                                                <div class="break-summary-label">Effective Work Hours</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Breaks List -->
                                    <div class="breaks-list">
                                        @foreach($breaks as $index => $break)
                                            <div class="break-item {{ !$break['is_active'] ? 'break-inactive' : '' }}">
                                                <!-- Break Header -->
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <!-- Drag Handle -->
                                                        <div class="drag-handle">
                                                            <svg width="16" height="16" fill="currentColor"
                                                                 viewBox="0 0 24 24">
                                                                <circle cx="9" cy="5" r="1"/>
                                                                <circle cx="9" cy="12" r="1"/>
                                                                <circle cx="9" cy="19" r="1"/>
                                                                <circle cx="15" cy="5" r="1"/>
                                                                <circle cx="15" cy="12" r="1"/>
                                                                <circle cx="15" cy="19" r="1"/>
                                                            </svg>
                                                        </div>

                                                        <!-- Break Name -->
                                                        <div>
                                                            <h6 class="mb-0">{{ $break['name'] }}</h6>
                                                            <div class="small text-muted">
                                                                {{ $this->getBreakTypeLabel($break['type']) }}
                                                                @if($break['is_mandatory'])
                                                                    <span class="badge bg-danger ms-1"
                                                                          style="font-size: 0.65rem;">Mandatory</span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Break Actions -->
                                                    <div class="d-flex align-items-center gap-2">
                                                        <!-- Active Toggle -->
                                                        <label class="toggle-switch-small">
                                                            <input type="checkbox"
                                                                   wire:click="toggleBreakActive({{ $index }})"
                                                                {{ $break['is_active'] ? 'checked' : '' }}>
                                                            <span class="toggle-slider-small"></span>
                                                        </label>

                                                        <!-- Move Up -->
                                                        <button wire:click="moveBreakUp({{ $index }})"
                                                                class="btn btn-sm btn-light"
                                                            {{ $index === 0 ? 'disabled' : '' }}>
                                                            <svg width="14" height="14" fill="currentColor"
                                                                 viewBox="0 0 24 24">
                                                                <polyline points="18 15 12 9 6 15" stroke="currentColor"
                                                                          stroke-width="2" fill="none"/>
                                                            </svg>
                                                        </button>

                                                        <!-- Move Down -->
                                                        <button wire:click="moveBreakDown({{ $index }})"
                                                                class="btn btn-sm btn-light"
                                                            {{ $index === count($breaks) - 1 ? 'disabled' : '' }}>
                                                            <svg width="14" height="14" fill="currentColor"
                                                                 viewBox="0 0 24 24">
                                                                <polyline points="6 9 12 15 18 9" stroke="currentColor"
                                                                          stroke-width="2" fill="none"/>
                                                            </svg>
                                                        </button>

                                                        <!-- Edit -->
                                                        <button wire:click="openEditBreakModal({{ $index }})"
                                                                class="btn btn-sm btn-light">
                                                            <svg width="16" height="16" fill="none"
                                                                 stroke="currentColor" stroke-width="2"
                                                                 viewBox="0 0 24 24">
                                                                <path
                                                                    d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                                                <path
                                                                    d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                            </svg>
                                                        </button>


                                                        <!-- Delete -->
                                                        <button wire:click="deleteBreak({{ $index }})"
                                                                wire:confirm="Are you sure you want to delete this break?"
                                                                class="btn btn-sm btn-light text-danger">
                                                            <svg width="16" height="16" fill="none"
                                                                 stroke="currentColor" stroke-width="2"
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

                                                <!-- Break Details Table -->
                                                <div class="table-responsive mt-2">
                                                    <table class="table table-sm table-borderless mb-0"
                                                           style="font-size: 0.8rem;">
                                                        <thead>
                                                        <tr style="border-bottom: 1px solid #e9ecef;">
                                                            <th class="text-muted fw-normal ps-0">Window Time</th>
                                                            <th class="text-muted fw-normal">Duration</th>
                                                            <th class="text-muted fw-normal">Max Duration</th>
                                                            <th class="text-muted fw-normal">Penalty</th>
                                                            <th class="text-muted fw-normal">Punch Required</th>
                                                        </tr>
                                                        </thead>
                                                        <tbody>
                                                        <tr>
                                                            <td class="fw-medium ps-0">
                                                                @if($break['window_start_time'] && $break['window_end_time'])
                                                                    {{ \Carbon\Carbon::parse($break['window_start_time'])->format('h:i A') }}
                                                                    –
                                                                    {{ \Carbon\Carbon::parse($break['window_end_time'])->format('h:i A') }}
                                                                @else
                                                                    <span class="text-muted">Anytime</span>
                                                                @endif
                                                            </td>
                                                            <td class="fw-medium">{{ $break['duration_minutes'] }}min
                                                            </td>
                                                            <td class="fw-medium">{{ $break['max_duration_minutes'] ?? $break['duration_minutes'] }}
                                                                min
                                                            </td>
                                                            <td class="fw-medium">{{ $this->getPenaltyTypeLabel($break['penalty_type']) }}</td>
                                                            <td>
                                                                @if($break['require_punch'])
                                                                    <span
                                                                        class="badge rounded-pill text-bg-warning">Yes</span>
                                                                @else
                                                                    <span class="badge rounded-pill text-bg-secondary">No</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <!-- Warning/Info Messages -->
                                                @if($break['notify_on_approaching'] && $break['notify_minutes_before'])
                                                    <div class="alert alert-info mt-2 mb-0 py-2 small">
                                                        <svg width="14" height="14" class="me-1" fill="currentColor"
                                                             viewBox="0 0 24 24">
                                                            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                                        </svg>
                                                        Notify {{ $break['notify_minutes_before'] }} minutes before
                                                        break window
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
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
                                                       value="{{ $selectedShift['startTime'] }} - {{ Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}"
                                                       disabled>
                                            </div>
                                            <small class="text-muted">
                                                Grace period ends
                                                at {{ Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}
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
                                                        Ends: {{ Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}</strong>
                                                    <br>
                                                    <strong class="text-primary">Status: Check-ins
                                                        before {{ Carbon::parse($selectedShift['startTime'])->addMinutes($selectedShift['gracePeriodMinutes'])->format('H:i') }}
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

                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="mt-3 tab-pane fade {{ $activeShiftTab === 'assign_employee' ? 'show active' : '' }}"
                         id="tab-assign-employee">
                        <div class="staff-assignment-card">
                            <div class="section-header">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                </svg>
                                <h2>Assign Staff to {{ $assigningShift->name }}</h2>
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

                            <!-- Search -->
                            <h3 class="section-title">All Staff</h3>
                            <div class="search-wrapper">
                                <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <input
                                    type="text"
                                    wire:model="searchTerm"
                                    wire:keyup="$dispatch('searchStaff')"
                                    placeholder="Search by name, role, or department..."
                                    class="search-input"
                                />
                            </div>

                            <!-- All Staff List -->
                            <div class="staff-list">
                                @forelse($availableStaff as $staff)
                                    <div wire:click="assignStaff({{ $staff->id }})"
                                         class="staff-item {{ in_array($staff->id, $assignedStaffIds) ? 'disabled' : 'clickable' }}">
                                        <div class="staff-item-content">
                                            <div class="staff-avatar">
                                                {{ $this->getInitials($staff->name) }}
                                            </div>
                                            <div class="staff-info">
                                                <div class="staff-name">{{ $staff->name }}</div>
                                                <div class="staff-details">
                                                    {{ $staff->shift?->name ?? 'No Shift' }}
                                                    • {{ $staff->department->name }}
                                                </div>
                                            </div>
                                            @if(in_array($staff->id, $assignedStaffIds))
                                                <div class="assigned-badge">Assigned</div>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="empty-state">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  stroke-width="2"
                                                  d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                        </svg>
                                        <p class="empty-state-text">No staff found</p>
                                        <p class="empty-state-subtext">Try adjusting your search</p>
                                    </div>
                                @endforelse
                            </div>

                            <!-- Assigned Staff Section -->
                            <div class="assigned-section">
                                <h3 class="section-title">
                                    Assigned Staff ({{ count($assignedStaffIds) }})
                                </h3>

                                @if(count($assignedStaffIds) === 0)
                                    <div class="empty-state">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                        </svg>
                                        <p class="empty-state-text">No staff assigned yet</p>
                                        <p class="empty-state-subtext">Click on staff from above to assign them</p>
                                    </div>
                                @else
                                    <div class="assigned-list">
                                        @foreach($assignedStaff as $staff)
                                            <div class="staff-item assigned-item">
                                                <div class="staff-item-content">
                                                    <div class="staff-avatar assigned">
                                                        {{ $this->getInitials($staff->name) }}
                                                    </div>
                                                    <div class="staff-info">
                                                        <div class="staff-name">{{ $staff->name }}</div>
                                                        <div class="staff-details">
                                                            {{ $staff->shift?->name ?? 'No Shift' }}
                                                            • {{ $staff->department->name }}
                                                        </div>
                                                    </div>
                                                    <button
                                                        wire:click="removeStaff({{ $staff->id }})"
                                                        class="remove-button"
                                                    >
                                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                  stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="summary-box">
                                        <div class="summary-content">
                                            <div>
                                                <div class="summary-label">Total Staff</div>
                                                <div class="summary-value">{{ count($assignedStaffIds) }}</div>
                                            </div>
                                            <button
                                                wire:click="saveAssignment"
                                                wire:loading.attr="disabled"
                                                class="save-button"
                                            >
                                                <span wire:loading.remove
                                                      wire:target="saveAssignment">Save Assignment</span>
                                                <span wire:loading wire:target="saveAssignment">Saving...</span>
                                            </button>
                                        </div>
                                    </div>
                                @endif
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

    <!-- Add Break Modal -->
    @if($showAddBreakModal)
        <div class="break-modal-overlay" wire:click.self="$set('showAddBreakModal', false)">
            <div class="break-modal-container">

                {{-- Modal Header --}}
                <div class="break-modal-header">
                    <div class="break-modal-title-group">
                        <div class="break-modal-icon-wrap">
                            @if($editingBreakIndex !== null)
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"
                                     viewBox="0 0 24 24">
                                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            @else
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"
                                     viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2" stroke-linecap="round"/>
                                </svg>
                            @endif
                        </div>
                        <div>
                            <h3 class="break-modal-title">{{ $editingBreakIndex !== null ? 'Edit Break' : 'Add New Break' }}</h3>
                            <p class="break-modal-subtitle">Configure break rules, time window, and penalty settings</p>
                        </div>
                    </div>
                    <button wire:click="$set('showAddBreakModal', false)" class="break-modal-close">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                             viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                {{-- Modal Body --}}
                <form wire:submit.prevent="saveBreak" class="break-modal-form">
                    <div class="break-modal-body">

                        {{-- LEFT COLUMN --}}
                        <div class="break-modal-col">

                            {{-- Section: Basic Info --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">01</span>
                                    <span>Basic Information</span>
                                </div>

                                <div class="break-field-group">
                                    <label class="break-field-label">
                                        Break Name <span class="break-required">*</span>
                                    </label>
                                    <input type="text"
                                           wire:model="currentBreak.name"
                                           class="break-input break-input-lg"
                                           placeholder="e.g., Lunch Break, Tea Break, Prayer Break">
                                    @error('currentBreak.name')
                                    <span class="break-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            {{-- Section: Activity Type --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">02</span>
                                    <span>Activity Type <span class="break-required">*</span></span>
                                </div>

                                <div class="break-type-grid">
                                    @foreach($breakTypes as $type)
                                        <label
                                            class="break-type-card {{ ($currentBreak['type'] ?? '') === $type['value'] ? 'break-type-card--active' : '' }} break-type-card--{{ $type['value'] }}">
                                            <input type="radio"
                                                   wire:model.live="currentBreak.type"
                                                   value="{{ $type['value'] }}"
                                                   class="break-type-radio">
                                            <div class="break-type-icon">
                                                @if($type['value'] === 'paid')
                                                    <svg width="22" height="22" fill="none" stroke="currentColor"
                                                         stroke-width="2" viewBox="0 0 24 24">
                                                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"
                                                              stroke-linecap="round"/>
                                                    </svg>
                                                @elseif($type['value'] === 'unpaid')
                                                    <svg width="22" height="22" fill="none" stroke="currentColor"
                                                         stroke-width="2" viewBox="0 0 24 24">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <path d="M4.93 4.93l14.14 14.14"/>
                                                    </svg>
                                                @else
                                                    <svg width="22" height="22" fill="none" stroke="currentColor"
                                                         stroke-width="2" viewBox="0 0 24 24">
                                                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                                                        <polyline points="17 6 23 6 23 12"/>
                                                    </svg>
                                                @endif
                                            </div>
                                            <div class="break-type-text">
                                                <div class="break-type-name">{{ $type['label'] }}</div>
                                                <div class="break-type-desc">{{ $type['description'] }}</div>
                                            </div>
                                            <div class="break-type-check">
                                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5"
                                                          fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                                @error('currentBreak.type')
                                <span class="break-error">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Section: Time Window --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">03</span>
                                    <span>Time Window <span class="break-optional">(Optional)</span></span>
                                </div>
                                <p class="break-section-hint">Define when employees should take this break during their
                                    shift</p>

                                <div class="break-time-row">
                                    <div class="break-field-group">
                                        <label class="break-field-label">Window Start</label>
                                        <div class="break-time-input-wrap">
                                            <svg class="break-time-icon" width="16" height="16" fill="none"
                                                 stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="10"/>
                                                <path d="M12 6v6l4 2" stroke-linecap="round"/>
                                            </svg>
                                            <input type="time"
                                                   wire:model="currentBreak.window_start_time"
                                                   class="break-input break-input-time">
                                        </div>
                                    </div>
                                    <div class="break-time-separator">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                                             viewBox="0 0 24 24">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </div>
                                    <div class="break-field-group">
                                        <label class="break-field-label">Window End</label>
                                        <div class="break-time-input-wrap">
                                            <svg class="break-time-icon" width="16" height="16" fill="none"
                                                 stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="10"/>
                                                <path d="M12 6v6l4 2" stroke-linecap="round"/>
                                            </svg>
                                            <input type="time"
                                                   wire:model="currentBreak.window_end_time"
                                                   class="break-input break-input-time">
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- RIGHT COLUMN --}}
                        <div class="break-modal-col">

                            {{-- Section: Duration --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">04</span>
                                    <span>Duration Settings</span>
                                </div>

                                <div class="break-duration-grid">
                                    <div class="break-field-group">
                                        <label class="break-field-label">
                                            Allowed Duration <span class="break-required">*</span>
                                        </label>
                                        <div class="break-input-suffix-wrap">
                                            <input type="number"
                                                   wire:model="currentBreak.duration_minutes"
                                                   class="break-input break-input-num"
                                                   min="1" max="480" placeholder="30">
                                            <span class="break-input-suffix">min</span>
                                        </div>
                                        <span class="break-field-hint">Standard allowed duration</span>
                                        @error('currentBreak.duration_minutes')
                                        <span class="break-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="break-field-group">
                                        <label class="break-field-label">Maximum Duration</label>
                                        <div class="break-input-suffix-wrap">
                                            <input type="number"
                                                   wire:model="currentBreak.max_duration_minutes"
                                                   class="break-input break-input-num"
                                                   min="1" max="480" placeholder="45">
                                            <span class="break-input-suffix">min</span>
                                        </div>
                                        <span class="break-field-hint">Before penalty applies</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Section: Penalty --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">05</span>
                                    <span>Penalty & Enforcement</span>
                                </div>

                                <div class="break-field-group">
                                    <label class="break-field-label">When Time Limit Exceeded</label>
                                    <div class="break-penalty-list">
                                        @foreach($penaltyTypes as $penalty)
                                            <label
                                                class="break-penalty-option {{ ($currentBreak['penalty_type'] ?? 'none') === $penalty['value'] ? 'break-penalty-option--active' : '' }}">
                                                <input type="radio"
                                                       wire:model.live="currentBreak.penalty_type"
                                                       value="{{ $penalty['value'] }}"
                                                       class="break-penalty-radio">
                                                <div class="break-penalty-dot"></div>
                                                <div class="break-penalty-text">
                                                    <div class="break-penalty-name">{{ $penalty['label'] }}</div>
                                                    <div class="break-penalty-desc">{{ $penalty['description'] }}</div>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                @if(($currentBreak['penalty_type'] ?? '') === 'auto_deduct')
                                    <div class="break-warning-box">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                                             viewBox="0 0 24 24">
                                            <path
                                                d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                            <line x1="12" y1="9" x2="12" y2="13"/>
                                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                                        </svg>
                                        <div>
                                            <strong>Auto-Deduct Active</strong>
                                            <p>Exceeding the time limit will automatically reduce the employee's worked
                                                hours.</p>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Section: Advanced Options --}}
                            <div class="break-form-section">
                                <div class="break-section-label">
                                    <span class="break-section-num">06</span>
                                    <span>Advanced Options</span>
                                </div>

                                <div class="break-toggle-list">

                                    <label class="break-toggle-item">
                                        <div class="break-toggle-info">
                                            <svg width="18" height="18" fill="none" stroke="currentColor"
                                                 stroke-width="2" viewBox="0 0 24 24">
                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                <path d="M7 11V7a5 5 0 0110 0v4"/>
                                            </svg>
                                            <div>
                                                <div class="break-toggle-name">Require Punch Out/In</div>
                                                <div class="break-toggle-desc">Employees must explicitly punch during
                                                    this break
                                                </div>
                                            </div>
                                        </div>
                                        <div class="break-switch">
                                            <input type="checkbox" wire:model="currentBreak.require_punch"
                                                   class="break-switch-input" id="require_punch">
                                            <span class="break-switch-slider"></span>
                                        </div>
                                    </label>

                                    <label class="break-toggle-item">
                                        <div class="break-toggle-info">
                                            <svg width="18" height="18" fill="none" stroke="currentColor"
                                                 stroke-width="2" viewBox="0 0 24 24">
                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                            </svg>
                                            <div>
                                                <div class="break-toggle-name">Mandatory Break</div>
                                                <div class="break-toggle-desc">Employees are required to take this
                                                    break
                                                </div>
                                            </div>
                                        </div>
                                        <div class="break-switch">
                                            <input type="checkbox" wire:model="currentBreak.is_mandatory"
                                                   class="break-switch-input" id="is_mandatory">
                                            <span class="break-switch-slider"></span>
                                        </div>
                                    </label>

                                    <label class="break-toggle-item">
                                        <div class="break-toggle-info">
                                            <svg width="18" height="18" fill="none" stroke="currentColor"
                                                 stroke-width="2" viewBox="0 0 24 24">
                                                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                                <path d="M13.73 21a2 2 0 01-3.46 0"/>
                                            </svg>
                                            <div>
                                                <div class="break-toggle-name">Approaching Notification</div>
                                                <div class="break-toggle-desc">Alert employees before the break limit is
                                                    reached
                                                </div>
                                            </div>
                                        </div>
                                        <div class="break-switch">
                                            <input type="checkbox" wire:model="currentBreak.notify_on_approaching"
                                                   class="break-switch-input" id="notify_approaching">
                                            <span class="break-switch-slider"></span>
                                        </div>
                                    </label>

                                    @if($currentBreak['notify_on_approaching'] ?? false)
                                        <div class="break-notify-minutes">
                                            <label class="break-field-label">Notify how many minutes before?</label>
                                            <div class="break-input-suffix-wrap" style="max-width: 180px;">
                                                <input type="number"
                                                       wire:model="currentBreak.notify_minutes_before"
                                                       class="break-input break-input-num"
                                                       min="1" max="60" placeholder="5">
                                                <span class="break-input-suffix">min</span>
                                            </div>
                                        </div>
                                    @endif

                                </div>
                            </div>

                        </div>
                    </div>

                    {{-- Modal Footer --}}
                    <div class="break-modal-footer">
                        <button type="button"
                                wire:click="$set('showAddBreakModal', false)"
                                class="break-btn break-btn-cancel">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                 viewBox="0 0 24 24">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                            Cancel
                        </button>
                        <button type="submit" class="break-btn break-btn-save">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                 viewBox="0 0 24 24">
                                @if($editingBreakIndex !== null)
                                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                @else
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                @endif
                            </svg>
                            {{ $editingBreakIndex !== null ? 'Update Break' : 'Add Break' }}
                        </button>
                    </div>
                </form>
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
