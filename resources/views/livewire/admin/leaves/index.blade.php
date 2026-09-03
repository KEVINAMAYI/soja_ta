<?php

use App\Models\Leave;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Attendance;
use App\Models\LeaveAlternativeDate;
use App\Models\LeaveType;
use App\Notifications\LeaveRequestAlternative;
use App\Services\LeaveApprovalService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $departments, $employees, $leaves, $leaveTypes;
    public $department_id, $from_date, $to_date;
    public $employee_id, $leave_type, $start_date, $end_date, $reason, $contact_during_leave, $emergency_contact, $handover_to;
    public $editId = null;
    public $search = '';
    public $status = '';
    public $recordType = 'all'; // all, leave, sick_off, off_shift
    public $editingHasActiveApprovalChain = false;
    public $editingCurrentLevel = null;
    public $loadedEmployee = null;

    public $isReporting = null;

    public $viewingRecord = null;
    public $viewingBalance = null;
    public $approvalComment = '';
    public $proposeNewDates = false;
    public $proposed_start_date, $proposed_end_date;

    public function mount($isReporting = null)
    {
        $this->isReporting = $isReporting;
        $org = auth()->user()->employee->organization;
        $this->getData($org);

        // If the URL includes modal/query params (for example from an emailed link),
        // open the details modal for the requested leave after mount.
        $reviewModal = request()->query('review_modal');
        $leaveId = request()->query('leave_id');

        if (in_array($reviewModal, ['details', 'leaveDetailsModal'], true) && $leaveId) {
            // Defensive checks: ensure the leave exists, belongs to the same org
            // as the current user, and that the user has permission to view
            // or approve leave requests.
            $leave = Leave::find($leaveId);
            $user = auth()->user();

            if ($leave && $user && $user->employee && $leave->organization_id === $user->employee->organization_id) {
                if ($user->can('view-employees') || $user->can('approve-leave-requests')) {
                    $this->viewLeaveDetails((int) $leaveId, 'leave');
                }
            }
        }
    }

    public function viewLeaveDetails($id, $type)
    {
        if ($type === 'leave') {
            $this->viewingRecord = Leave::with([
                'employee.department',
                'approvalLogs.approverUser',
                'approvalLogs.levelApprovers',
                'approvalLogs.actionedBy',
                'activeApprovalLog',
            ])->findOrFail($id);

            $this->viewingBalance = null;
            if ($this->viewingRecord->leave_type_id) {
                $balance = \App\Models\LeaveBalance::where('employee_id', $this->viewingRecord->employee_id)
                    ->where('leave_type_id', $this->viewingRecord->leave_type_id)
                    ->where('year', $this->viewingRecord->start_date->year)
                    ->first();

                if ($balance) {
                    $this->viewingBalance = $balance->remainingDays();
                }
            }
        } else {
            $this->viewingRecord = Employee::findOrFail($id);
            $this->viewingBalance = null;
        }

        $this->approvalComment = '';
        $this->proposeNewDates = false;
        $this->proposed_start_date = null;
        $this->proposed_end_date = null;

        $this->dispatch('show-details-modal');
    }

    public function updatedProposeNewDates($enabled)
    {
        if ($enabled && $this->viewingRecord instanceof Leave) {
            $this->proposed_start_date = $this->viewingRecord->start_date->format('Y-m-d');
            $this->proposed_end_date = $this->viewingRecord->end_date->format('Y-m-d');
        }
    }

    public function approveFromDetails()
    {
        if ($this->saveNewLeaveDatesWhenApproving()) {
            $org = auth()->user()->employee->organization;
            $this->getData($org);
            $this->viewingRecord = null;
            $this->dispatch('hide-details-modal');
            return; // exit if new leave dates were proposed and notification sent
        }
        $this->actionFromDetails('approve');
    }

    public function rejectFromDetails()
    {
        if (trim($this->approvalComment) === '') {
            LivewireAlert::title('Comment required')
                ->text('Please add a note explaining the rejection.')
                ->warning()
                ->toast()
                ->position('top-end')
                ->show();
            return;
        }
        $this->actionFromDetails('reject');
    }

    private function actionFromDetails(string $action)
    {
        try {
            $leave = $this->viewingRecord;

            if ($this->proposeNewDates && $this->proposed_start_date && $this->proposed_end_date) {
                $leave->update([
                    'start_date' => $this->proposed_start_date,
                    'end_date' => $this->proposed_end_date,
                    'expected_resumption' => Carbon::parse($this->proposed_end_date)->addDay()->format('Y-m-d'),
                ]);
            }

            $notes = $this->approvalComment !== '' ? $this->approvalComment : null;

            $leave = $action === 'approve'
                ? app(LeaveApprovalService::class)->approve($leave, auth()->user(), $notes)
                : app(LeaveApprovalService::class)->reject($leave, auth()->user(), $notes);

            $org = auth()->user()->employee->organization;
            $this->getData($org);
            $this->viewingRecord = null;
            $this->dispatch('hide-details-modal');

            LivewireAlert::title($action === 'approve' ? 'Approved!' : 'Rejected!')
                ->text($action === 'approve'
                    ? ($leave->status === 'approved' ? 'Leave fully approved.' : 'Approved — advanced to level ' . $leave->current_level . '.')
                    : 'Leave request rejected.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        } catch (\Throwable $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to action leave request: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function getData($org)
    {
        $this->departments = Department::where('organization_id', $org->id)->get();
        $this->employees = Employee::where('organization_id', $org->id)->get();
        $this->leaveTypes = LeaveType::where('organization_id', $org->id)->get();
        $this->filterLeaves();
    }

    #[On('filterChanged')]
    public function filterLeaves()
    {
        $org = auth()->user()->employee->organization;

        // Get regular leaves
        $leaveQuery = Leave::where('organization_id', $org->id)
            ->with(['employee.department', 'activeApprovalLog.approverUser'])
            ->latest();

        if ($this->department_id) {
            $leaveQuery->where('department_id', $this->department_id);
        }

        if ($this->status) {
            $leaveQuery->where('status', $this->status);
        }

        if ($this->from_date) {
            $leaveQuery->whereDate('start_date', '>=', $this->from_date);
        }

        if ($this->to_date) {
            $leaveQuery->whereDate('end_date', '<=', $this->to_date);
        }

        if ($this->leave_type) {
            $leaveQuery->where('leave_type', $this->leave_type);
        }

        if ($this->search) {
            $leaveQuery->whereHas('employee', function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            })
                ->orWhere('leave_type', 'like', '%' . $this->search . '%')
                ->orWhere('reason', 'like', '%' . $this->search . '%');
        }

        // Get employees with off-shift or sick-off status
        $employeeQuery = Employee::where('organization_id', $org->id)
            ->whereNotNull('shift_status')
            ->whereIn('shift_status', ['off_shift', 'sick_off'])
            ->with('department');

        if ($this->department_id) {
            $employeeQuery->where('department_id', $this->department_id);
        }

        if ($this->from_date) {
            $employeeQuery->whereDate('start_off_shift_date', '>=', $this->from_date);
        }

        if ($this->to_date) {
            $employeeQuery->whereDate('end_off_shift_date', '<=', $this->to_date);
        }

        if ($this->search) {
            $employeeQuery->where('name', 'like', '%' . $this->search . '%');
        }

        // Combine and format results
        $leaves = collect();

        if ($this->recordType === 'all' || $this->recordType === 'leave') {
            $regularLeaves = $leaveQuery->get()->map(function ($leave) {
                return [
                    'id' => $leave->id,
                    'type' => 'leave',
                    'employee' => $leave->employee,
                    'leave_type' => $leave->leave_type,
                    'start_date' => $leave->start_date,
                    'end_date' => $leave->end_date,
                    'status' => $leave->status,
                    'expected_resumption' => $leave->expected_resumption,
                    'num_of_days' => $leave->num_of_days,
                    'reason' => $leave->reason,
                    'original' => $leave
                ];
            });
            $leaves = $leaves->merge($regularLeaves);
        }

        if ($this->recordType === 'all' || $this->recordType === 'sick_off' || $this->recordType === 'off_shift') {
            $shiftRecords = $employeeQuery->get()->map(function ($employee) {
                $statusMap = [
                    'sick_off' => 'Sick Off',
                    'off_shift' => 'Off Shift'
                ];

                // Only include if recordType matches or is 'all'
                if ($this->recordType !== 'all' && $employee->shift_status !== $this->recordType) {
                    return null;
                }

                return [
                    'id' => $employee->id,
                    'type' => $employee->shift_status,
                    'employee' => $employee,
                    'leave_type' => $statusMap[$employee->shift_status] ?? $employee->shift_status,
                    'start_date' => $employee->start_off_shift_date ? Carbon::parse($employee->start_off_shift_date) : null,
                    'end_date' => $employee->end_off_shift_date ? Carbon::parse($employee->end_off_shift_date) : null,
                    'status' => 'active',
                    'expected_resumption' => $employee->end_off_shift_date ? Carbon::parse($employee->end_off_shift_date)->addDay() : null,
                    'num_of_days' => $employee->start_off_shift_date && $employee->end_off_shift_date
                        ? Carbon::parse($employee->start_off_shift_date)->diffInDays(Carbon::parse($employee->end_off_shift_date)) + 1
                        : null,
                    'reason' => null,
                    'original' => $employee
                ];
            })->filter();

            $leaves = $leaves->merge($shiftRecords);
        }

        $this->leaves = $leaves->sortByDesc(function ($item) {
            return $item['start_date'];
        })->values();
    }

    public function resetForm()
    {
        $this->reset([
            'employee_id', 'department_id', 'leave_type', 'start_date', 'end_date', 'reason',
            'contact_during_leave', 'emergency_contact', 'handover_to', 'editId',
            'editingHasActiveApprovalChain', 'editingCurrentLevel',
        ]);

        $this->start_date = now()->format('Y-m-d');
    }

    public function clearFilters()
    {
        $this->reset(['search', 'department_id', 'status', 'from_date', 'to_date', 'leave_type', 'recordType']);
        $this->dispatch('filterChanged');
    }

    public function editLeave($id, $type)
    {
        if ($type === 'leave') {
            $leave = Leave::findOrFail($id);

            $this->editId = $leave->id;
            $this->employee_id = $leave->employee_id;
            $this->department_id = $leave->department_id;
            $this->leave_type = $leave->leave_type;
            $this->start_date = $leave->start_date->format('Y-m-d');
            $this->end_date = $leave->end_date->format('Y-m-d');
            $this->reason = $leave->reason;
            $this->contact_during_leave = $leave->contact_during_leave;
            $this->emergency_contact = $leave->emergency_contact;
            $this->handover_to = $leave->handover_to;
            $this->status = $leave->status;

            // A leave mid-flight through a multi-level approval chain must not
            // have its status short-circuited via this manual edit form — it
            // can only be advanced/finalized via the Approve/Reject actions.
            $this->editingHasActiveApprovalChain = (bool)$leave->activeApprovalLog;
            $this->editingCurrentLevel = $leave->current_level;
        } else {
            // Editing off-shift or sick-off
            $employee = Employee::findOrFail($id);

            $this->editId = "emp_{$employee->id}";
            $this->employee_id = $employee->id;
            $this->department_id = $employee->department_id;
            $this->leave_type = $type === 'sick_off' ? 'Sick Off' : 'Off Shift';
            $this->start_date = $employee->start_off_shift_date ? Carbon::parse($employee->start_off_shift_date)->format('Y-m-d') : now()->format('Y-m-d');
            $this->end_date = $employee->end_off_shift_date ? Carbon::parse($employee->end_off_shift_date)->format('Y-m-d') : now()->format('Y-m-d');
        }

        $this->dispatch('show-leave-modal');
    }

    public function saveNewLeaveDates(): bool {
        // check if leave start and end dates have changed for the existing leave record and if so, delete any attendance records in that range
        if ($this->editId && !str_starts_with($this->editId, 'emp_')) {
            $leave1 = Leave::findOrFail($this->editId);
            $startDateDiff = $leave1->start_date->diffInDays($this->start_date);
            $endDateDiff = $leave1->end_date->diffInDays($this->end_date);

            if ($startDateDiff != 0 || $endDateDiff != 0) {
                
                $new_num_of_days = $leave1->leaveType()?->first()?->calculateNumberOfDaysFromLeaveStartAndEndDates(Carbon::parse($this->start_date), Carbon::parse($this->end_date))['effective_leave_days'];
                
                $remaining = $this->getLeaveTypeRemainingDays($new_num_of_days, $leave1, $this->start_date);

                if ($remaining !== null && ($remaining < $new_num_of_days)) { 

                    $this->clearFilters();
                    $this->resetForm();
                    $this->dispatch('hide-leave-modal');

                    LivewireAlert::title('Oh no!')
                    ->text("The proposed new leave dates exceed the remaining leave balance.")
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();

                    return true; // avoid saving leave update
                }
                // save change request to db
                $leaveAlternativeDate = LeaveAlternativeDate::updateOrCreate(
                    [
                        'leave_id' => $leave1->id,
                    ],
                    [
                        'new_start_date' => $this->start_date,
                        'new_end_date' => $this->end_date,
                        'new_num_of_days' => $new_num_of_days,
                        'status' => 'pending',
                        'created_by' => auth()->user()->id,
                    ]
                );

                // make accept url
                $acceptUrl = \App\Services\GuestRoute::makeAnyUrlGuestLoginRedirect(
                    'leave.update.guest.login',
                    null,
                    ['leave_id' => $leave1->id, 'action' => 'accept'],
                    $leave1->employee->email
                );

                // make reject url
                $rejectUrl = \App\Services\GuestRoute::makeAnyUrlGuestLoginRedirect(
                    'leave.update.guest.login',
                    null,
                    ['leave_id' => $leave1->id, 'action' => 'reject'],
                    $leave1->employee->email
                );

                $leave_email_date = [
                    'employeeName' => $leave1->employee->name,
                    'leaveTypeName' => $leave1->leaveType->name,
                    'originalStartDate' => $leave1->start_date->format('d M Y'),
                    'originalEndDate' => $leave1->end_date->format('d M Y'),
                    'newStartDate' => Carbon::parse($leaveAlternativeDate->new_start_date)->format('d M Y'),
                    'newEndDate' => Carbon::parse($leaveAlternativeDate->new_end_date)->format('d M Y'),
                    'newNumberOfDays' => $leaveAlternativeDate->new_num_of_days,
                    'companyName' => $leave1->employee->organization->name ?? config('app.name'),
                    'acceptUrl' => $acceptUrl,
                    'rejectUrl' => $rejectUrl,
                ];


                Notification::route('mail', $leave1->employee->email)
                    ->notify(new LeaveRequestAlternative($leave_email_date));
                
                $this->clearFilters();
                $this->resetForm();
                $this->dispatch('hide-leave-modal');

                LivewireAlert::title('Awesome!')
                    ->text("User notified of the proposed new leave dates. Awaiting their approval or rejection.")
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();

                return true; // no need for more execution when this is send to user to agree or reject
            }
        }

        return false;
    }


    public function saveNewLeaveDatesWhenApproving(): bool {
        // check if leave start and end dates have changed for the existing leave record and if so, delete any attendance records in that range
        $leave = $this->viewingRecord;
        if ($leave && $this->proposeNewDates && $this->proposed_start_date && $this->proposed_end_date) {
            
            $startDateDiff = $leave->start_date->diffInDays($this->proposed_start_date);
            $endDateDiff = $leave->end_date->diffInDays($this->proposed_end_date);

            if ($startDateDiff != 0 || $endDateDiff != 0) {

                $new_num_of_days = $leave->leaveType()?->first()?->calculateNumberOfDaysFromLeaveStartAndEndDates(Carbon::parse($this->proposed_start_date), Carbon::parse($this->proposed_end_date))['effective_leave_days'];

                $remaining = $this->getLeaveTypeRemainingDays($new_num_of_days, $leave, $this->proposed_start_date);

                if ($remaining !== null && ($remaining < $new_num_of_days)) { 

                    $this->clearFilters();
                    $this->resetForm();
                    $this->dispatch('hide-leave-modal');

                    LivewireAlert::title('Oh no!')
                    ->text("The proposed new leave dates exceed the remaining leave balance.")
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();

                    return true; // avoid saving leave update
                }
                // save change request to db
                $leaveAlternativeDate = LeaveAlternativeDate::updateOrCreate(
                    [
                        'leave_id' => $leave->id,
                    ],
                    [
                        'new_start_date' => $this->proposed_start_date,
                        'new_end_date' => $this->proposed_end_date,
                        'new_num_of_days' => $new_num_of_days,
                        'status' => 'pending',
                        'intended_action' => 'approve',
                        'created_by' => auth()->user()->id,
                    ]
                );

               
                // make accept url
                $acceptUrl = \App\Services\GuestRoute::makeAnyUrlGuestLoginRedirect(
                    'leave.update.guest.login',
                    null,
                    ['leave_id' => $leave->id, 'action' => 'accept'],
                    $leave->employee->email
                );

                // make reject url
                $rejectUrl = \App\Services\GuestRoute::makeAnyUrlGuestLoginRedirect(
                    'leave.update.guest.login',
                    null,
                    ['leave_id' => $leave->id, 'action' => 'reject'],
                    $leave->employee->email
                );

                $leave_email_date = [
                    'employeeName' => $leave->employee->name,
                    'leaveTypeName' => $leave->leaveType->name,
                    'originalStartDate' => $leave->start_date->format('d M Y'),
                    'originalEndDate' => $leave->end_date->format('d M Y'),
                    'newStartDate' => Carbon::parse($leaveAlternativeDate->new_start_date)->format('d M Y'),
                    'newEndDate' => Carbon::parse($leaveAlternativeDate->new_end_date)->format('d M Y'),
                    'newNumberOfDays' => $leaveAlternativeDate->new_num_of_days,
                    'companyName' => $leave->employee->organization->name ?? config('app.name'),
                    'acceptUrl' => $acceptUrl,
                    'rejectUrl' => $rejectUrl,
                ];

                Notification::route('mail', $leave->employee->email)
                    ->notify(new LeaveRequestAlternative($leave_email_date));
                
                $this->clearFilters();
                $this->resetForm();
                $this->dispatch('hide-leave-modal');

                LivewireAlert::title('Awesome!')
                    ->text("User notified of the proposed new leave dates. Awaiting their approval or rejection.")
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();

                return true; // no need for more execution when this is send to user to agree or reject
            }
        }

        return false;
    }


    public function getLeaveTypeRemainingDays(int $new_number_of_days, Leave $leave2, $startDate) {

        Log::info("Calculating remaining leave days for employee ID, leave type: " . ($chosenLeaveType->name ?? 'N/A') . ", new number of days: $new_number_of_days, start date: $startDate");
        $service = app(LeaveApprovalService::class);
        $employeeId = $this->employee_id;

        if (!$employeeId) {
            return null; // Return null if no employee is selected or leave type is not set
        }

        $employee = $this->loadedEmployee;

        if (!$employee) {
            $employee = Employee::find($employeeId);
            $this->loadedEmployee = $employee;
        }

        Log::info("LEAVE DETAILS ARE: " . json_encode($leave2));

        $chosenLeaveType = LeaveType::find($leave2->leave_type_id);

        if (!$chosenLeaveType) {
            Log::warning("Leave type not found for employee ID: $employeeId");
            return null; // Return null if the leave type is not found
        }



        $remaining = $service->checkBalance($employee, $chosenLeaveType, (float)$new_number_of_days, Carbon::parse($startDate)->year)['remaining'] ?? null;

        if ($remaining === null) {
            Log::warning("Remaining leave balance could not be determined for employee ID: $employeeId, leave type: " . ($chosenLeaveType->name ?? 'N/A') . ", new number of days: $new_number_of_days, start date: $startDate");
            return null; // Return null if the remaining balance could not be determined
        }

        Log::info("Remaining leave balance for employee ID: $employeeId, leave type: " . ($chosenLeaveType->name ?? 'N/A') . ", new number of days: $new_number_of_days, start date: $startDate, remaining: $remaining");
        return $remaining;

    }

    public function saveLeave()
    {
        if ($this->saveNewLeaveDates()) {
            return; // exit if new leave dates were proposed and notification sent
        }
        
        try {
            DB::beginTransaction();

            $this->validate([
                'employee_id' => 'required|exists:employees,id',
                'department_id' => 'required|exists:departments,id',
                'leave_type' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $org = auth()->user()->employee->organization;
            $employee = Employee::findOrFail($this->employee_id);

            if ($this->leave_type === 'Off Shift' || $this->leave_type === 'Sick Off') {
                // Converting TO Off Shift/Sick Off OR updating existing Off Shift/Sick Off

                // If this was previously a regular leave record, delete it
                if ($this->editId && !str_starts_with($this->editId, 'emp_')) {
                    $leave = Leave::findOrFail($this->editId);
                    $leave->delete();
                }

                // Update employee shift status
                $employee->update([
                    'shift_status' => $this->leave_type === 'Sick Off' ? 'sick_off' : 'off_shift',
                    'start_off_shift_date' => $this->start_date,
                    'end_off_shift_date' => $this->end_date,
                ]);

                $message = $this->leave_type . ' record saved successfully.';

            } else {
                // Converting TO regular leave OR updating existing regular leave

                // If this was previously Off Shift/Sick Off, clear the employee status
                if ($this->editId && str_starts_with($this->editId, 'emp_')) {
                    $employee->update([
                        'shift_status' => 'on_shift',
                        'start_off_shift_date' => null,
                        'end_off_shift_date' => null,
                    ]);
                }

                // Handle regular leave
                $data = $this->only([
                    'employee_id', 'department_id', 'leave_type', 'start_date', 'end_date', 'reason',
                    'contact_during_leave', 'emergency_contact', 'handover_to'
                ]);
                $data['expected_resumption'] = Carbon::parse($this->end_date)->addDay()->format('Y-m-d');
                $data['organization_id'] = $org->id;

                if ($this->editId && !str_starts_with($this->editId, 'emp_')) {
                    // Updating existing leave record
                    $leave = Leave::findOrFail($this->editId);
                    $leave->update($data);

                    // Manual status editing is only allowed when there's no active
                    // multi-level approval chain in progress — otherwise this would
                    // let someone skip straight to "approved"/"rejected" without the
                    // remaining levels ever actually approving. Leaves going through
                    // the chain must be advanced via the Approve/Reject actions.
                    if (auth()->user()->can('view-employees') && !$leave->activeApprovalLog) {
                        $leave->status = $this->status;
                        $leave->save();
                    }

                    $message = 'Leave request updated successfully.';
                } else {
                    // Creating new leave record
                    $data['status'] = 'pending';
                    Leave::create($data);
                    $message = 'Leave request saved successfully.';
                }
            }

            $this->getData($org);
            DB::commit();

            $this->clearFilters();
            $this->resetForm();
            $this->dispatch('hide-leave-modal');

            LivewireAlert::title('Awesome!')
                ->text($message)
                ->success()
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

    public function deleteRecord($id, $type)
    {
        try {
            DB::beginTransaction();

            if ($type === 'leave') {
                $leave = Leave::findOrFail($id);

                // Handle attendance records
                $this->handleAttendanceOnDeletion(
                    $leave->employee_id,
                    $leave->start_date->format('Y-m-d'),
                    $leave->end_date->format('Y-m-d'),
                    'leave'
                );

                $leave->delete();
                $message = 'Leave request deleted successfully.';

            } else {
                // Handle off-shift or sick-off deletion
                $employee = Employee::findOrFail($id);

                if ($employee->shift_status && in_array($employee->shift_status, ['off_shift', 'sick_off'])) {
                    $this->handleAttendanceOnDeletion(
                        $employee->id,
                        $employee->start_off_shift_date,
                        $employee->end_off_shift_date,
                        $employee->shift_status
                    );

                    $employee->update([
                        'shift_status' => null,
                        'start_off_shift_date' => null,
                        'end_off_shift_date' => null,
                    ]);

                    $message = ucfirst(str_replace('_', ' ', $type)) . ' record removed successfully.';
                }
            }

            $org = auth()->user()->employee->organization;
            $this->getData($org);

            DB::commit();

            LivewireAlert::title('Success!')
                ->text($message)
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            DB::rollBack();
            LivewireAlert::title('Error!')
                ->text('Failed to delete record: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function approveLeave($id)
    {
        $this->actionLeave($id, 'approve');
    }

    public function rejectLeave($id)
    {
        $this->actionLeave($id, 'reject');
    }

    private function actionLeave($id, string $action)
    {
        try {
            $leave = Leave::findOrFail($id);

            $leave = $action === 'approve'
                ? app(LeaveApprovalService::class)->approve($leave, auth()->user())
                : app(LeaveApprovalService::class)->reject($leave, auth()->user());

            $org = auth()->user()->employee->organization;
            $this->getData($org);

            LivewireAlert::title($action === 'approve' ? 'Approved!' : 'Rejected!')
                ->text($action === 'approve'
                    ? ($leave->status === 'approved' ? 'Leave fully approved.' : 'Approved — advanced to level ' . $leave->current_level . '.')
                    : 'Leave request rejected.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        } catch (\Throwable $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to action leave request: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    private function handleAttendanceOnDeletion($employeeId, $startDate, $endDate, $recordType)
    {
        // Delete all attendance records in the date range
        Attendance::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->delete();

        // Reset employee shift_status to on_shift
        $employee = Employee::find($employeeId);
        if ($employee) {
            $employee->update([
                'shift_status' => 'on_shift', // or null, depending on your default state
                'start_off_shift_date' => null,
                'end_off_shift_date' => null,
            ]);
        }

    }
}; ?>

@push('styles')
    <style>
        #leaveDetailsModal .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        #leaveDetailsModal .modal-header {
            padding: 24px 24px 12px;
        }

        #leaveDetailsModal .ld-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #eef1ff;
            color: #3949ab;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            flex-shrink: 0;
        }

        #leaveDetailsModal .ld-name {
            font-size: 17px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        #leaveDetailsModal .ld-meta {
            font-size: 12.5px;
            color: #9ca3af;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 4px;
        }

        #leaveDetailsModal .ld-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        #leaveDetailsModal .ld-status-pill {
            font-size: 11.5px;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 20px;
            white-space: nowrap;
        }

        #leaveDetailsModal .ld-status-pill.pending {
            background: #fef3c7;
            color: #92400e;
        }

        #leaveDetailsModal .ld-status-pill.approved {
            background: #d1fae5;
            color: #065f46;
        }

        #leaveDetailsModal .ld-status-pill.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        #leaveDetailsModal .ld-status-pill.cancelled {
            background: #f3f4f6;
            color: #4b5563;
        }

        #leaveDetailsModal .modal-body {
            padding: 8px 24px 24px;
        }

        #leaveDetailsModal .ld-detail-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            padding: 16px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 18px;
        }

        #leaveDetailsModal .ld-detail-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: 6px;
            display: block;
        }

        #leaveDetailsModal .ld-detail-value {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a2e;
        }

        #leaveDetailsModal .ld-detail-sub {
            font-size: 11.5px;
            color: #9ca3af;
            margin-top: 2px;
        }

        #leaveDetailsModal .ld-type-badge {
            display: inline-block;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 6px;
        }

        #leaveDetailsModal .ld-section-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: 8px;
        }

        #leaveDetailsModal .ld-reason-text {
            font-size: 13.5px;
            color: #374151;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        /* Timeline */
        #leaveDetailsModal .ld-timeline {
            list-style: none;
            padding: 0;
            margin: 0;
            position: relative;
        }

        #leaveDetailsModal .ld-timeline-item {
            display: flex;
            gap: 14px;
            position: relative;
            padding-bottom: 22px;
        }

        #leaveDetailsModal .ld-timeline-item:last-child {
            padding-bottom: 0;
        }

        #leaveDetailsModal .ld-timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 13px;
            top: 28px;
            bottom: -4px;
            width: 2px;
            background: #e5e7eb;
        }

        #leaveDetailsModal .ld-step-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            z-index: 1;
        }

        #leaveDetailsModal .ld-step-icon.success {
            background: #d1fae5;
            color: #10b981;
        }

        #leaveDetailsModal .ld-step-icon.primary {
            background: #dbeafe;
            color: #3b82f6;
        }

        #leaveDetailsModal .ld-step-icon.danger {
            background: #fee2e2;
            color: #ef4444;
        }

        #leaveDetailsModal .ld-step-icon.secondary {
            background: #f3f4f6;
            color: #9ca3af;
        }

        #leaveDetailsModal .ld-step-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #1a1a2e;
        }

        #leaveDetailsModal .ld-step-time {
            font-size: 11.5px;
            color: #9ca3af;
        }

        #leaveDetailsModal .ld-step-level {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 4px;
        }

        #leaveDetailsModal .ld-step-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 5px;
            margin-top: 2px;
        }

        #leaveDetailsModal .ld-step-badge.success {
            background: #d1fae5;
            color: #065f46;
        }

        #leaveDetailsModal .ld-step-badge.primary {
            background: #dbeafe;
            color: #1e40af;
        }

        #leaveDetailsModal .ld-step-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }

        #leaveDetailsModal .ld-step-badge.secondary {
            background: #f3f4f6;
            color: #6b7280;
        }

        #leaveDetailsModal .ld-step-note {
            background: #f9fafb;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12.5px;
            color: #4b5563;
            margin-top: 8px;
        }

        #leaveDetailsModal .ld-reviewing-banner {
            background: #eff6ff;
            color: #1e40af;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 8px;
            margin: 20px 0 16px;
        }

        #leaveDetailsModal .ld-propose-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        #leaveDetailsModal .ld-propose-toggle-label {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
        }

        #leaveDetailsModal .ld-propose-toggle-sub {
            font-size: 11.5px;
            color: #9ca3af;
            font-weight: 400;
            margin-top: 2px;
        }

        #leaveDetailsModal .ld-comment-label {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 6px;
            display: block;
        }

        #leaveDetailsModal textarea.ld-comment-box {
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            font-size: 13px;
            resize: none;
        }

        #leaveDetailsModal .modal-footer {
            padding: 16px 24px 24px;
            border-top: none;
            gap: 10px;
        }

        #leaveDetailsModal .btn-ld-reject {
            border: 1.5px solid #e5e7eb;
            color: #374151;
            background: #fff;
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 20px;
            font-size: 13.5px;
        }

        #leaveDetailsModal .btn-ld-reject:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        #leaveDetailsModal .btn-ld-approve {
            background: #bf1e24;
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 20px;
            font-size: 13.5px;
        }

        #leaveDetailsModal .btn-ld-approve:hover {
            background: #a3181d;
            color: #fff;
        }

        #leaveDetailsModal .form-switch .form-check-input {
            width: 40px;
            height: 22px;
        }

        #leaveDetailsModal .ld-meta {
            font-size: 12.5px;
            color: #6b7280; /* was #9ca3af */
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 4px;
        }

        #leaveDetailsModal .ld-detail-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #6b7280; /* was #9ca3af */
            margin-bottom: 6px;
            display: block;
        }

        #leaveDetailsModal .ld-detail-sub {
            font-size: 11.5px;
            color: #6b7280; /* was #9ca3af */
            margin-top: 2px;
        }

        #leaveDetailsModal .ld-section-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #6b7280; /* was #9ca3af */
            margin-bottom: 8px;
        }

        #leaveDetailsModal .ld-step-time {
            font-size: 11.5px;
            color: #6b7280; /* was #9ca3af */
        }

        #leaveDetailsModal .ld-step-level {
            font-size: 12px;
            color: #6b7280; /* was #9ca3af */
            margin-bottom: 4px;
        }

        #leaveDetailsModal .ld-propose-toggle-sub {
            font-size: 11.5px;
            color: #6b7280; /* was #9ca3af */
            font-weight: 400;
            margin-top: 2px;
        }

    </style>
@endpush

<div>
    @if(!$isReporting)
        <livewire:admin.system-settings.bread-crumb
            title="Leave & Absence Management"
            :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>'],
                ['label' => 'Leave & Absence', 'icon' => '<iconify-icon icon=\'mdi:exit-run\' class=\'fs-5\'></iconify-icon>'],
            ]"
        />
    @endif

    <div class="card card-body">
        <div class="mb-3 row align-items-end g-3">
            <div class="col-md-3 mb-2">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" placeholder="Search..."
                       wire:model.lazy="search" wire:keyup="$dispatch('filterChanged')">
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">Record Type</label>
                <select class="form-control" wire:model="recordType" wire:change="$dispatch('filterChanged')">
                    <option value="all">All Records</option>
                    <option value="leave">Leave Requests</option>
                    <option value="sick_off">Sick Off</option>
                    <option value="off_shift">Off Shift</option>
                </select>
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">Department</label>
                <select class="form-control" wire:model="department_id" wire:change="$dispatch('filterChanged')">
                    <option value="">All</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">Status</label>
                <select class="form-control" wire:model="status" wire:change="$dispatch('filterChanged')">
                    <option value="">All</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                    <option value="active">Active</option>
                </select>
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" wire:model="from_date" wire:change="$dispatch('filterChanged')">
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" wire:model="to_date" wire:change="$dispatch('filterChanged')">
            </div>

            <div class="col-md-3 mb-2">
                <label class="form-label">Leave Type</label>
                <select class="form-control" wire:model="leave_type" wire:change="$dispatch('filterChanged')">
                    <option value="">All Types</option>
                    <option value="Annual Leave">Annual Leave</option>
                    <option value="Sick Leave">Sick Leave</option>
                    <option value="Maternity Leave">Maternity Leave</option>
                    <option value="Paternity Leave">Paternity Leave</option>
                    <option value="Compassionate Leave">Compassionate Leave</option>
                    <option value="Study Leave">Study Leave</option>
                    <option value="Unpaid Leave">Unpaid Leave</option>
                </select>
            </div>

            <div class="col-md-3 mb-2 d-flex align-items-end gap-2">
                <button class="btn btn-outline-danger" wire:click="clearFilters">
                    <iconify-icon icon="mdi:filter-remove-outline" class="fs-5"></iconify-icon>
                    Clear
                </button>
                <a style="margin-bottom:2px;" href="{{ route('leaves.create') }}" class="btn btn-primary">+ New
                    Request</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <th>
                        <h6 class="fw-semibold mb-0">{{ auth()->user()->employee?->organization?->is_student_record  ? "Student" : "Employee" }}</h6>
                    </th>
                    <th>
                        <h6 class="fw-semibold mb-0">Leave Types</h6>
                    </th>
                    <th>
                        <h6 class="fw-semibold mb-0">Duration</h6>
                    </th>
                    <th>
                        <h6 class="fw-semibold mb-0">Status</h6>
                    </th>
                    <th>
                        <h6 class="fw-semibold mb-0">Approval Progress</h6>
                    </th>
                    <th>
                        <h6 class="fw-semibold mb-0">Actions</h6>
                    </th>
                </tr>
                </thead>
                <tbody>
                @forelse($leaves as $record)
                    <tr>
                        <td>
                            <div class="d-flex flex-column">
                                <h6 class="fw-semibold mb-1">{{ $record['employee']->name }}</h6>
                                <span class="fw-normal text-muted d-flex align-items-center gap-1">
                                        <iconify-icon icon="tabler:user" class="text-primary" width="20"></iconify-icon>
                                        {{ $record['employee']->email ?? 'N/A' }}
                                    </span>
                                <span class="fw-normal text-muted d-flex align-items-center gap-1 mt-1">
                                        <i class='ti ti-id me-1 text-success'></i>
                                        ID: {{ $record['employee']->id_number ?? 'N/A' }}
                                    </span>
                                <span class="fw-normal text-muted d-flex align-items-center gap-1 mt-1">
                                     <i class='ti ti-building me-1'></i>
                                    {{ $record['employee']->department->name ?? 'N/A' }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <h6 class="fw-semibold mb-1">{{ $record['leave_type'] }}</h6>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                @if($record['start_date'] && $record['end_date'])
                                    <span class="fw-semibold text-success mb-1">
                                            {{ $record['start_date']->format('d M Y') }}
                                        </span>
                                    <span class="fw-semibold text-danger">
                                            {{ $record['end_date']->format('d M Y') }}
                                        </span>
                                    <small class="text-muted mt-1">
                                        ({{$record['num_of_days']}} days)Return: {{ $record['expected_resumption']?->format('d M Y') ?? '-' }}
                                    </small>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if($record['status'] == 'approved')
                                <span class="badge bg-success-subtle text-success fw-semibold">Approved</span>
                            @elseif($record['status'] == 'pending')
                                <span class="badge bg-warning-subtle text-warning fw-semibold">Pending</span>
                            @elseif($record['status'] == 'active')
                                <span class="badge bg-primary-subtle text-primary fw-semibold">Active</span>
                            @elseif($record['status'] == 'rejected')
                                <span class="badge bg-danger-subtle text-danger fw-semibold">Rejected</span>
                            @else
                                <span
                                    class="badge bg-secondary-subtle text-secondary fw-semibold">{{ ucfirst($record['status']) }}</span>
                            @endif
                        </td>
                        <td>
                            @if($record['type'] === 'leave' && $record['original']->total_levels)
                                @php $activeLog = $record['original']->activeApprovalLog; @endphp
                                @if($activeLog)
                                    <div class="d-flex flex-column">
                                        <span class="badge bg-info-subtle text-info fw-semibold mb-1">
                                            Level {{ $record['original']->current_level }} of {{ $record['original']->total_levels }}
                                        </span>
                                        <small class="text-muted">
                                            Waiting on:
                                            @if($activeLog->approver_type === 'user')
                                                {{ $activeLog->approverUser->name ?? 'Unknown user' }}
                                            @else
                                                @php
                                                    $nameToShow = $activeLog?->approverUser?->name;
                                                    if (!$nameToShow) {                                                         
                                                        $nameToShow = App\Models\JobTitle::find($activeLog->approver_role)->name;
                                                    };
                                                @endphp
                                                {{ $nameToShow ?? 'Unknown job title' }}
                                            @endif
                                        </small>
                                    </div>
                                @elseif($record['status'] === 'approved')
                                    <small class="text-muted">All {{ $record['original']->total_levels }} level(s)
                                        approved</small>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="dropdown dropstart">
                                <a href="javascript:void(0)" class="text-muted"
                                   id="dropdownMenuButton-{{ $record['id'] }}"
                                   data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="ti ti-dots-vertical fs-6"></i>
                                </a>
                                <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton-{{ $record['id'] }}">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-3"
                                           href="javascript:void(0)"
                                           wire:click="viewLeaveDetails({{ $record['id'] }}, '{{ $record['type'] }}')">
                                            <iconify-icon icon="mdi:eye-outline"
                                                          class="fs-4 text-primary"></iconify-icon>
                                            <span>View </span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-3"
                                           href="javascript:void(0)"
                                           wire:click="editLeave({{ $record['id'] }}, '{{ $record['type'] }}')">
                                            <iconify-icon icon="mdi:pencil-outline"
                                                          class="fs-4 text-warning"></iconify-icon>
                                            <span>Edit</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-3"
                                           href="javascript:void(0)"
                                           onclick="confirm('Delete this record? Attendance records will be deleted.') || event.stopImmediatePropagation()"
                                           wire:click="deleteRecord({{ $record['id'] }}, '{{ $record['type'] }}')">
                                            <iconify-icon icon="mdi:delete-outline"
                                                          class="fs-4 text-danger"></iconify-icon>
                                            <span class="text-danger">Delete</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <div class="d-flex flex-column align-items-center">
                                <iconify-icon icon="mdi:file-document-outline"
                                              class="fs-1 text-muted mb-2"></iconify-icon>
                                <h6 class="text-muted">No records found</h6>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- MODAL --}}
        <div class="modal fade" id="leaveModal" tabindex="-1" wire:ignore.self>
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editId ? 'Edit Leave' : 'Create New Leave' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form wire:submit.prevent="saveLeave">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Employee</label>
                                    <select wire:model="employee_id" class="form-control" {{$editId ? 'disabled' : ''}}>
                                        <option value="">Select employee</option>
                                        @foreach($employees as $emp)
                                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('employee_id') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <select wire:model="department_id" class="form-control"  {{$editId ? 'disabled' : ''}}>
                                        <option value="">Select department</option>
                                        @foreach($departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('department_id') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Type</label>
                                    <select wire:model="leave_type" class="form-control" {{$editId ? 'disabled' : ''}}>
                                        <option value="">Select Leave Type</option>
                                            @foreach($leaveTypes as $type)
                                                <option value="{{ $type->name }}" {{$type->name == $leave_type ? 'selected' : ''}}>{{ $type->name }}</option>
                                            @endforeach
                                    </select>
                                    @error('leave_type') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                @if(auth()->user()->can('view-employees') && $editId && !str_starts_with($editId, 'emp_'))
                                    @if($editingHasActiveApprovalChain)
                                        <div class="col-md-6">
                                            <label class="form-label">Status</label>
                                            <div class="alert alert-info mb-0 py-2 px-3 small">
                                                <iconify-icon icon="mdi:information-outline"
                                                              class="me-1"></iconify-icon>
                                                This leave is currently pending approval at
                                                level {{ $editingCurrentLevel }}. Use the Approve/Reject actions on
                                                the list instead of editing status directly.
                                            </div>
                                        </div>
                                    @else
                                        <div class="col-md-6">
                                            <label class="form-label">Status</label>
                                            <select wire:model="status" class="form-control">
                                                <option value="pending">Pending</option>
                                                <option value="approved">Approved</option>
                                                <option value="rejected">Rejected</option>
                                                <option value="cancelled">Cancelled</option>
                                            </select>
                                        </div>
                                    @endif
                                @endif

                                @if($leave_type === 'Off Shift' || $leave_type === 'Sick Off')
                                    {{-- For Off Shift and Sick Off: dates side by side --}}
                                    <div class="col-md-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="text" wire:model.live="start_date" value="{{ $this->start_date }}" id="leaveStartDate" class="form-control leave-date-input" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                        @error('start_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="text" wire:model.live="end_date" id="leaveEndDate" class="form-control leave-date-input" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                        @error('end_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>
                                @else
                                    {{-- For Leave: dates take full width --}}
                                    <div class="col-md-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="text" wire:model.live="start_date" value="{{ $this->start_date }}" id="leaveStartDate" class="form-control leave-date-input" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                        @error('start_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="text" wire:model.live="end_date" id="leaveEndDate" class="form-control leave-date-input" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                        @error('end_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>
                                @endif

                                @if($leave_type !== 'Off Shift' && $leave_type !== 'Sick Off')
                                    <div class="col-12">
                                        <label class="form-label">Reason</label>
                                        <textarea wire:model="reason" class="form-control" rows="2"
                                                  placeholder="Provide detailed reason..."></textarea>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Contact During Leave</label>
                                        <input type="text" wire:model="contact_during_leave" class="form-control">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Emergency Contact</label>
                                        <input type="text" wire:model="emergency_contact" class="form-control">
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label">Handover To</label>
                                        <input type="text" wire:model="handover_to" class="form-control">
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button class="btn btn-success" type="submit">{{ $editId ? 'Update' : 'Submit' }}</button>
                            <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- VIEW MODAL --}}
        <div class="modal fade" id="leaveDetailsModal" tabindex="-1" wire:ignore.self>
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    @if($viewingRecord && $viewingRecord instanceof \App\Models\Leave)
                        @php
                            $leave = $viewingRecord;
                            $canAct = $leave->activeApprovalLog && app(\App\Services\LeaveApprovalService::class)->canAct($leave, auth()->user());
                            $sortedLogs = $leave->approvalLogs->sortBy('level_number');
                            $activeApprovalLog = $leave->activeApprovalLog;
                            $alreadyApprovedByCurrentUser = false;

                            if ($activeApprovalLog) {
                                if ($activeApprovalLog->approver_type === 'user') {
                                    $levelSettings = \App\Services\LeaveApprovalSettings::get($leave->organization_id, $leave->department_id);
                                    $levelConfig = $levelSettings['levels'][$activeApprovalLog->level_number - 1] ?? null;
                                    $isAllApproveRule = ($levelConfig['approver_rule'] ?? 'anyone_approve') === 'all_approve';

                                    if ($isAllApproveRule) {
                                        $alreadyApprovedByCurrentUser = $activeApprovalLog->levelApprovers->contains(function ($levelApprover) {
                                            return (int) $levelApprover->level_approver_id === auth()->id();
                                        });
                                    } else {
                                        $alreadyApprovedByCurrentUser = (int) ($activeApprovalLog->actioned_by ?? 0) === auth()->id();
                                    }
                                } else {
                                    $alreadyApprovedByCurrentUser = (int) ($activeApprovalLog->actioned_by ?? 0) === auth()->id();
                                }
                            }
                        @endphp

                        <div class="modal-header border-0 pb-0">
                            <div class="d-flex align-items-center gap-3 w-100">
                                <div class="ld-avatar">
                                    {{ strtoupper(substr($leave->employee->name ?? '?', 0, 2)) }}
                                </div>
                                <div class="flex-grow-1">
                                    <p class="ld-name">{{ $leave->employee->name }}</p>
                                    <div class="ld-meta">
                                        <span><iconify-icon icon="mdi:email-outline"></iconify-icon>{{ $leave->employee->email ?? '—' }}</span>
                                        <span><iconify-icon icon="mdi:card-account-details-outline"></iconify-icon>ID: {{ $leave->employee->id_number ?? '—' }}</span>
                                        <span><iconify-icon icon="mdi:office-building-outline"></iconify-icon>{{ $leave->employee->department->name ?? '—' }}</span>
                                    </div>
                                </div>
                                <span style="margin-top:20px;" class="ld-status-pill {{ $leave->status }}">{{ ucfirst($leave->status) }}</span>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    style="position:absolute;top:20px;right:20px;"></button>
                        </div>

                        <div class="modal-body">
                            <div class="ld-detail-grid">
                                <div>
                                    <span class="ld-detail-label">Leave Type</span>
                                    <span class="ld-type-badge">{{ $leave->leave_type }}</span>
                                </div>
                                <div>
                                    <span class="ld-detail-label">Duration</span>
                                    <div class="ld-detail-value">{{ $leave->start_date->format('d M') }} - {{ $leave->end_date->format('d M Y') }}</div>
                                    <div
                                        class="ld-detail-sub">{{ $leave->num_of_days }}
                                        working day(s)
                                    </div>
                                </div>
                                <div>
                                    <span class="ld-detail-label">Return Date</span>
                                    <div
                                        class="ld-detail-value">{{ $leave->expected_resumption?->format('d M Y') ?? '—' }}</div>
                                    @if($viewingBalance !== null)
                                        <div class="ld-detail-sub">Balance: {{ $viewingBalance }} days left</div>
                                    @endif
                                </div>
                                <div>
                                    <span class="ld-detail-label">Submitted</span>
                                    <div class="ld-detail-value">{{ $leave->created_at->format('d M Y') }}</div>
                                </div>
                            </div>

                            @if($leave->reason)
                                <div class="ld-section-label">Reason for Leave</div>
                                <p class="ld-reason-text">{{ $leave->reason }}</p>
                            @endif

                            @if($leave->total_levels)
                                <div class="ld-section-label">
                                    Approval Progress — Level {{ $leave->current_level }} of {{ $leave->total_levels }}
                                </div>

                                <ul class="ld-timeline">
                                    @foreach($sortedLogs as $log)
                                        @php
                                            $stepColor = $log->status === 'approved' ? 'success'
                                                : ($log->status === 'rejected' ? 'danger'
                                                : ($log->level_number == $leave->current_level ? 'primary' : 'secondary'));
                                            $stepIcon = $log->status === 'approved' ? 'mdi:check'
                                                : ($log->status === 'rejected' ? 'mdi:close' : 'mdi:clock-outline');

                                            // Who is the designated approver for this specific log (used both for
                                            // the header name shown once closed, and for the "awaiting X" label
                                            // while still pending)
                                            if ($log->approver_type === 'user') {
                                                $approverLabel = $log->approverUser->name ?? ucfirst($log->approver_role ?? 'Approver');
                                            } else {
                                                // get job title name from job title model where id = $log->approver_role
                                                $approverLabel = \App\Models\JobTitle::find($log->approver_role)?->name ?? ucfirst($log->approver_role ?? 'Approver');
                                            }
                                            //$approverLabel = $log->approverUser->name ?? ucfirst($log->approver_role ?? 'Approver');
                                        @endphp
                                        <li class="ld-timeline-item">
                                            <div class="ld-step-icon {{ $stepColor }}">
                                                <iconify-icon icon="{{ $stepIcon }}" style="font-size:15px;"></iconify-icon>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between">
                                                    <span class="ld-step-name">{{ $approverLabel }}</span>
                                                    <span class="ld-step-time">
                    {{ $log->closed_at?->format('d M, h:i A') ?? $log->opened_at?->format('d M, h:i A') }}
                </span>
                                                </div>
                                                <div class="ld-step-level">Level {{ $log->level_number }}</div>

                                                @if($log->status === 'approved')
                                                    <span class="ld-step-badge success">Approved</span>
                                                @elseif($log->status === 'rejected')
                                                    <span class="ld-step-badge danger">Rejected</span>
                                                @elseif($log->level_number == $leave->current_level)
                                                    @php
                                                        if ($log->approver_type === 'user') {
                                                            $approverIds = [];
                                                            if (!empty($log->approver_user_ids) && is_array($log->approver_user_ids)) {
                                                                $approverIds = $log->approver_user_ids;
                                                            }
                                                            if ($log->approver_user_id) {
                                                                $approverIds[] = $log->approver_user_id;
                                                            }
                                                            $approverIds = array_values(array_unique(array_filter($approverIds)));

                                                            $approverUsers = \App\Models\User::whereIn('id', $approverIds)
                                                                ->get()
                                                                ->map(fn($user) => ['id' => $user->id, 'name' => $user->name])
                                                                ->toArray();

                                                            if (empty($approverUsers) && $log->approverUser) {
                                                                $approverUsers = [['id' => $log->approverUser->id, 'name' => $log->approverUser->name]];
                                                            }

                                                            $actedApproverIds = $log->levelApprovers->pluck('level_approver_id')->toArray();
                                                            $approverLabel = count($approverUsers) > 1
                                                                ? implode(', ', array_column($approverUsers, 'name'))
                                                                : ($approverUsers[0]['name'] ?? ucfirst($log->approver_role ?? 'Approver'));
                                                        } else {
                                                            $approverLabel = "Approver";
                                                            if ($log->approverUser && $log->approverUser->name) {
                                                                $approverLabel = $log->approverUser->name;
                                                            } else {
                                                                $approverLabel = \App\Models\JobTitle::find($log->approver_role)?->name ?? ucfirst($log->approver_role ?? 'Approver');
                                                            }
                                                            // get job title name from job title model where id = $log->approver_role
                                                            //$approverLabel = ucfirst($log->approver_role ?? 'Approver');
                                                        }
                                                    @endphp

                                                    @if($log->approver_type === 'user')
                                                        @foreach($approverUsers as $approver)
                                                            @php
                                                                $acted = in_array($approver['id'], $actedApproverIds, true);
                                                                $rejectedByThisApprover = $log->status === 'rejected'
                                                                    && $log->actionedBy
                                                                    && $log->actionedBy->id === $approver['id'];
                                                            @endphp

                                                            @if($acted)
                                                                <span class="ld-step-badge success">Approved by {{ $approver['name'] }}</span>
                                                            @elseif($rejectedByThisApprover)
                                                                <span class="ld-step-badge danger">Rejected by {{ $approver['name'] }}</span>
                                                            @elseif($canAct && auth()->user()->id === $approver['id'])
                                                                <span class="ld-step-badge primary">Awaiting your review</span>
                                                            @else
                                                                <span class="ld-step-badge primary">Awaiting {{ $approver['name'] }}'s review</span>
                                                            @endif
                                                        @endforeach
                                                    @else
                                                        @if($canAct)
                                                            <span class="ld-step-badge primary">Awaiting your review</span>
                                                        @else
                                                            <span class="ld-step-badge primary">Awaiting {{ $approverLabel }}'s review</span>
                                                        @endif
                                                    @endif
                                                @else
                                                    <span class="ld-step-badge secondary">Not yet reached</span>
                                                @endif

                                                @if($log->notes)
                                                    <div class="ld-step-note">{{ $log->notes }}</div>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if($canAct)
                                <div class="ld-reviewing-banner">
                                    You're reviewing this as Level {{ $leave->current_level }}
                                </div>

                                <div class="ld-propose-toggle">
                                    <div>
                                        <div class="ld-propose-toggle-label">Propose new dates or adjust days</div>
                                        <div class="ld-propose-toggle-sub">Suggest a change instead of approving as
                                            submitted
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               wire:model.live="proposeNewDates" id="proposeToggle">
                                    </div>
                                </div>

                                @if($proposeNewDates)
                                    <div class="row g-2 mb-3" x-data x-init="$nextTick(() => initProposedDatepickers())">
                                        <div class="col-6">
                                            <label class="form-label small">New Start Date</label>
                                            <input type="text" wire:model.live="proposed_start_date" id="proposedStartDate"
                                                   class="form-control form-control-sm leave-date-input" autocomplete="off"
                                                   placeholder="YYYY-MM-DD" readonly>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">New End Date</label>
                                            <input type="text" wire:model.live="proposed_end_date" id="proposedEndDate"
                                                   class="form-control form-control-sm leave-date-input" autocomplete="off"
                                                   placeholder="YYYY-MM-DD" readonly>
                                        </div>
                                    </div>
                                @endif

                                <label class="ld-comment-label">Add a Comment</label>
                                <textarea wire:model="approvalComment" class="form-control ld-comment-box mb-2" rows="2"
                                          placeholder="Leave a note for the requester or the next approver (optional for approval, required to reject or propose changes)"></textarea>
                            @endif
                        </div>

                        @if($canAct)
                            <div class="modal-footer">
                                <button class="btn-ld-reject" wire:click="rejectFromDetails">Reject</button>
                                <button class="btn-ld-approve" wire:click="approveFromDetails" {{ $alreadyApprovedByCurrentUser ? 'disabled' : '' }}>
                                    {{ $alreadyApprovedByCurrentUser ? 'Approved' : 'Approve' }}
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>

@push('scripts')
    <script>
        function initLeaveDatepickers() {
            const $startInput = $('#leaveStartDate');
            const $endInput = $('#leaveEndDate');

            const setLivewireDateValue = (field, value) => {
                const modal = document.getElementById('leaveModal');
                const componentRoot = modal?.closest('[wire\\:id]');
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

            if (!$startInput.length || !$endInput.length || typeof $.fn.datepicker === 'undefined') {
                return;
            }

            if ($startInput.data('datepicker')) {
                $startInput.datepicker('destroy');
            }

            if ($endInput.data('datepicker')) {
                $endInput.datepicker('destroy');
            }

            const startValue = $startInput.val();
            const endValue = $endInput.val();
            const tomorrow = new Date();
            tomorrow.setHours(0, 0, 0, 0);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const minimumDate = startValue || tomorrow;

            $startInput.datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true,
                startDate: minimumDate,
            }).on('changeDate', function (e) {
                const selected = e.format('yyyy-mm-dd');
                syncDateValue($startInput, selected);
                setLivewireDateValue('start_date', selected);
                $endInput.datepicker('setStartDate', selected);

                if ($endInput.val() && $endInput.val() < selected) {
                    syncDateValue($endInput, selected);
                    $endInput.datepicker('update', selected);
                    setLivewireDateValue('end_date', selected);
                }
            });

            $endInput.datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true,
                startDate: minimumDate,
            }).on('changeDate', function (e) {
                const selected = e.format('yyyy-mm-dd');
                syncDateValue($endInput, selected);
                setLivewireDateValue('end_date', selected);
            });

            if (startValue) {
                $startInput.datepicker('update', startValue);
                $endInput.datepicker('setStartDate', startValue);
            }

            if (endValue) {
                $endInput.datepicker('update', endValue);
            }
        }

        function initProposedDatepickers() {
            const $startInput = $('#proposedStartDate');
            const $endInput = $('#proposedEndDate');

            const setLivewireDateValue = (field, value) => {
                const modal = document.getElementById('leaveDetailsModal');
                const componentRoot = modal?.closest('[wire\\:id]');
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

            if (!$startInput.length || !$endInput.length || typeof $.fn.datepicker === 'undefined') {
                return;
            }

            if ($startInput.data('datepicker')) {
                $startInput.datepicker('destroy');
            }

            if ($endInput.data('datepicker')) {
                $endInput.datepicker('destroy');
            }

            const startValue = $startInput.val();
            const endValue = $endInput.val();
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const todayValue = today.toISOString().slice(0, 10);
            const effectiveStartValue = startValue && startValue >= todayValue ? startValue : todayValue;
            const effectiveEndValue = endValue && endValue >= effectiveStartValue ? endValue : effectiveStartValue;

            if (effectiveStartValue !== startValue) {
                syncDateValue($startInput, effectiveStartValue);
                setLivewireDateValue('proposed_start_date', effectiveStartValue);
            }

            if (effectiveEndValue !== endValue) {
                syncDateValue($endInput, effectiveEndValue);
                setLivewireDateValue('proposed_end_date', effectiveEndValue);
            }

            $startInput.datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true,
                startDate: today,
            }).on('changeDate', function (e) {
                const selected = e.format('yyyy-mm-dd');
                syncDateValue($startInput, selected);
                setLivewireDateValue('proposed_start_date', selected);
                $endInput.datepicker('setStartDate', selected);

                if ($endInput.val() && $endInput.val() < selected) {
                    syncDateValue($endInput, selected);
                    $endInput.datepicker('update', selected);
                    setLivewireDateValue('proposed_end_date', selected);
                }
            });

            $endInput.datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true,
                startDate: effectiveStartValue,
            }).on('changeDate', function (e) {
                const selected = e.format('yyyy-mm-dd');
                syncDateValue($endInput, selected);
                setLivewireDateValue('proposed_end_date', selected);
            });

            if (effectiveStartValue) {
                $startInput.datepicker('update', effectiveStartValue);
                $endInput.datepicker('setStartDate', effectiveStartValue);
            }

            if (effectiveEndValue) {
                $endInput.datepicker('update', effectiveEndValue);
            }
        }

        window.addEventListener('show-leave-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveModal')).show();
            initLeaveDatepickers();
        });

        window.addEventListener('hide-leave-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveModal'))?.hide();
        });

        window.addEventListener('show-details-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveDetailsModal')).show();
        });

        window.addEventListener('hide-details-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveDetailsModal'))?.hide();
        });

    </script>
@endpush
