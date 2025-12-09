<?php

use App\Models\Employee;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Shift;

new class extends Component {


    public $shifts = [];
    public $selectedShift = [];
    public $showPatternModal = false;
    public $showAddShift = false;
    public string $activeTab = 'shift_settings';
    public string $tabTitle;
    public string $tabIcon;
    public $assignedStaffIds = [];
    public $availableStaff = [];
    public $assignedStaff = [];
    public $assigningShift;
    public $searchTerm = '';

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
        ];
    }

    public $dayAbbreviations = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    public $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

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
        $key = array_search($shiftId, array_column($this->shifts, 'id'));
        if ($key !== false) {
            $this->selectedShift = $this->shifts[$key];
        }

        $this->assignedStaffIds = Employee::where('shift_id', $shiftId)
            ->pluck('id')
            ->toArray();

        $this->assigningShift = Shift::findOrFail($shiftId);

        $this->assignedStaff = Employee::whereIn('id', $this->assignedStaffIds)
            ->with('shift')
            ->get();

        $this->searchEmployees();

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

            Employee::where('organization_id', $organizationId)
                ->where('shift_id', $this->selectedShift['id'])
                ->whereNotIn('id', $this->assignedStaffIds)
                ->update(['shift_id' => null]);

            Employee::where('organization_id', $organizationId)
                ->whereIn('id', $this->assignedStaffIds)
                ->update(['shift_id' => $this->selectedShift['id']]);

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

        session()->flash('message', 'New shift created successfully!');
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

            $shift->delete();
            $this->loadShifts();

            if (count($this->shifts) > 0) {
                $this->selectedShift = $this->shifts[0];
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

            // Handle overnight shift
            if ($end->lt($start)) {
                $end->addDay();
            }

            $minutes = $start->diffInMinutes($end);

            // If your shift has a break_minutes field, subtract here:
            // $break = $this->selectedShift['break_minutes'] ?? 0;
            // $minutes -= $break;

            $this->selectedShift['duration'] = round($minutes / 60, 2);

        } catch (\Exception $e) {
            // Fail silently (optional)
        }
    }


    #[On('tabChanged')]
    public function tabChanged($tabId)
    {

        $this->activeTab = $tabId;
        $this->changeBreadcrumb();

    }

    public function changeBreadcrumb()
    {
        switch ($this->activeTab) {

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
            @if($activeTab === 'shift_settings')
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

                <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">
                    <!-- Company Information -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'shift_settings' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
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
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'assign_employee' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
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
                    <div class="mt-3 tab-pane fade {{ $activeTab === 'shift_settings' ? 'show active' : '' }}"
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
                                        <label class="form-label">Shift Duration (hours)</label>
                                        <input type="number"
                                               wire:model="selectedShift.duration"
                                               class="form-control"
                                               min="1" max="24" step="0.5">
                                    </div>
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

                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="mt-3 tab-pane fade {{ $activeTab === 'assign_employee' ? 'show active' : '' }}"
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
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    // Map Bootstrap tab IDs to your internal tab names
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

                    Livewire.dispatch('tabChanged', {tabId: mappedTab});

                });
            });
        });
    </script>
@endpush
