<?php

use App\Models\Leave;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Attendance;
use App\Services\LeaveApprovalService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $departments, $employees, $leaves;
    public $department_id, $from_date, $to_date;
    public $employee_id, $leave_type, $start_date, $end_date, $reason, $contact_during_leave, $emergency_contact, $handover_to;
    public $editId = null;
    public $search = '';
    public $status = '';
    public $recordType = 'all'; // all, leave, sick_off, off_shift
    public $editingHasActiveApprovalChain = false;
    public $editingCurrentLevel = null;

    public $isReporting = null;

    public function mount($isReporting = null)
    {
        $this->isReporting = $isReporting;
        $org = auth()->user()->employee->organization;
        $this->getData($org);
    }

    public function getData($org)
    {
        $this->departments = Department::where('organization_id', $org->id)->get();
        $this->employees = Employee::where('organization_id', $org->id)->get();
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
            $this->editingHasActiveApprovalChain = (bool) $leave->activeApprovalLog;
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

    public function saveLeave()
    {
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
                                        Return: {{ $record['expected_resumption']?->format('d M Y') ?? '-' }}
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
                                                {{ ucfirst($activeLog->approver_role) }} role
                                            @endif
                                        </small>
                                    </div>
                                @elseif($record['status'] === 'approved')
                                    <small class="text-muted">All {{ $record['original']->total_levels }} level(s) approved</small>
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
                                    @if($record['type'] === 'leave' && $record['status'] === 'pending' && $record['original']->activeApprovalLog && app(\App\Services\LeaveApprovalService::class)->canAct($record['original'], auth()->user()))
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-3"
                                                   href="javascript:void(0)"
                                                   wire:click="approveLeave({{ $record['id'] }})">
                                                    <iconify-icon icon="mdi:check-circle-outline"
                                                                  class="fs-4 text-success"></iconify-icon>
                                                    <span class="text-success">Approve (Level {{ $record['original']->current_level }})</span>
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-3"
                                                   href="javascript:void(0)"
                                                   onclick="confirm('Reject this leave request?') || event.stopImmediatePropagation()"
                                                   wire:click="rejectLeave({{ $record['id'] }})">
                                                    <iconify-icon icon="mdi:close-circle-outline"
                                                                  class="fs-4 text-danger"></iconify-icon>
                                                    <span class="text-danger">Reject</span>
                                                </a>
                                            </li>
                                    @endif
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
                        <h5 class="modal-title">{{ $editId ? 'Edit Record' : 'Create New Record' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form wire:submit.prevent="saveLeave">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Employee</label>
                                    <select wire:model="employee_id" class="form-control">
                                        <option value="">Select employee</option>
                                        @foreach($employees as $emp)
                                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('employee_id') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <select wire:model="department_id" class="form-control">
                                        <option value="">Select department</option>
                                        @foreach($departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('department_id') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Type</label>
                                    <select wire:model="leave_type" class="form-control">
                                        <option value="">-- Select Type --</option>
                                        <option value="Annual Leave">Annual Leave</option>
                                        <option value="Sick Leave">Sick Leave</option>
                                        <option value="Sick Off">Sick Off</option>
                                        <option value="Off Shift">Off Shift</option>
                                        <option value="Maternity Leave">Maternity Leave</option>
                                        <option value="Paternity Leave">Paternity Leave</option>
                                        <option value="Compassionate Leave">Compassionate Leave</option>
                                        <option value="Study Leave">Study Leave</option>
                                        <option value="Unpaid Leave">Unpaid Leave</option>
                                    </select>
                                    @error('leave_type') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                @if(auth()->user()->can('view-employees') && $editId && !str_starts_with($editId, 'emp_'))
                                    @if($editingHasActiveApprovalChain)
                                        <div class="col-md-6">
                                            <label class="form-label">Status</label>
                                            <div class="alert alert-info mb-0 py-2 px-3 small">
                                                <iconify-icon icon="mdi:information-outline" class="me-1"></iconify-icon>
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
                                        <input type="date" wire:model="start_date" class="form-control">
                                        @error('start_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="date" wire:model="end_date" class="form-control">
                                        @error('end_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>
                                @else
                                    {{-- For Leave: dates take full width --}}
                                    <div class="col-md-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" wire:model="start_date" class="form-control">
                                        @error('start_date') <small class="text-danger">{{ $message }}</small>@enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="date" wire:model="end_date" class="form-control">
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
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('show-leave-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveModal')).show();
        });

        window.addEventListener('hide-leave-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveModal'))?.hide();
        });
    </script>
@endpush
