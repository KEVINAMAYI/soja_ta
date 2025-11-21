<?php

use App\Models\Department;
use App\Models\Leave;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\Reactive;

new class extends Component {

    public $departments, $employees, $leaves;
    public $department_id, $from_date, $to_date;
    public $employee_id, $leave_type, $start_date, $end_date, $reason, $contact_during_leave, $emergency_contact, $handover_to;
    public $editId = null;
    public $search = '';
    public $status = '';

    #[Reactive]
    public $isReporting = null; // Default value can be null

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
        $this->leaves = Leave::where('organization_id', $org->id)
            ->latest()
            ->get();
    }


    #[On('filterChanged')]
    public function filterLeaves()
    {


        $org = auth()->user()->employee->organization;

        $query = Leave::where('organization_id', $org->id)
            ->with(['employee', 'department'])
            ->latest();

        if ($this->department_id) {
            $query->where('department_id', $this->department_id);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->from_date) {
            $query->whereDate('start_date', '>=', $this->from_date);
        }

        if ($this->to_date) {
            $query->whereDate('end_date', '<=', $this->to_date);
        }

        if ($this->leave_type) {
            $query->where('leave_type', $this->leave_type);
        }

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            })
                ->orWhere('leave_type', 'like', '%' . $this->search . '%')
                ->orWhere('reason', 'like', '%' . $this->search . '%');
        }

        $this->leaves = $query->get();
    }

    public function resetForm()
    {
        $this->reset([
            'employee_id', 'department_id', 'leave_type', 'start_date', 'end_date', 'reason',
            'contact_during_leave', 'emergency_contact', 'handover_to', 'editId'
        ]);
    }

    public function clearFilters()
    {
        $this->reset(['search', 'department_id', 'status', 'from_date', 'to_date', 'leave_type']);
        $this->dispatch('filterChanged'); // trigger filtering after clearing
    }


    public function editLeave($id)
    {
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

        // Open modal programmatically
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

            $data = $this->only([
                'employee_id', 'department_id', 'leave_type', 'start_date', 'end_date', 'reason',
                'contact_during_leave', 'emergency_contact', 'handover_to'
            ]);
            $data['expected_resumption'] = date('Y-m-d', strtotime($this->end_date . ' +1 day'));
            $data['organization_id'] = $org->id;

            if ($this->editId) {
                // Update existing leave
                $leave = Leave::findOrFail($this->editId);
                $leave->update($data);

                // Update status if user can manage employees
                if (auth()->user()->can('view-employees')) {
                    $leave->status = $this->status;
                    $leave->save();
                }

                $message = 'Leave request updated successfully.';
            } else {
                // Create new leave
                $data['status'] = 'pending';
                Leave::create($data);
                $message = 'Leave request saved successfully.';
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
                ->text('Something went wrong while saving the leave: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function deleteLeave($id)
    {
        $leave = Leave::findOrFail($id);
        $leave->delete();

        $org = auth()->user()->employee->organization;
        $this->getData($org);

        LivewireAlert::title('Awesome!')
            ->text('Leave request deleted successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

    }


}
?>

<div>

    @if(!$isReporting)
        <livewire:admin.system-settings.bread-crumb
            title="Leave Requests"
            :items="[
        [
         'label' => 'Dashboard',
         'url' => route('dashboard'),
         'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
       ],
       [
         'label' => 'Leave',
         'icon' => '<iconify-icon icon=\'mdi:exit-run\' class=\'fs-5\'></iconify-icon>',
       ],
    ]"
        />
    @endif

    <div class="card card-body">
        <div class="mb-3 row align-items-end g-3">
            {{-- SEARCH INPUT --}}
            <div class="col-4 mb-2">
                <label class="form-label">Search</label>
                <input type="text"
                       class="form-control"
                       placeholder="Search by employee, type or reason..."
                       wire:model.lazy="search"
                       wire:keyup="$dispatch('filterChanged')">
            </div>

            {{-- DEPARTMENT FILTER --}}
            <div class="col-4 mb-2">
                <label class="form-label">Department</label>
                <select class="form-control" wire:model="department_id" wire:change="$dispatch('filterChanged')">
                    <option value="">All</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- DATE RANGE --}}
            <div class="col-4 mb-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" wire:model="from_date" wire:change="$dispatch('filterChanged')">
            </div>

            <div class="col-4 mb-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" wire:model="to_date" wire:change="$dispatch('filterChanged')">
            </div>

            {{-- STATUS FILTER --}}
            <div class="col-4 mb-2">
                <label class="form-label">Status</label>
                <select class="form-control" wire:model="status" wire:change="$dispatch('filterChanged')">
                    <option value="">All</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            {{--LEAVE TYPE FILTER--}}
            <div class="col-4 mb-2">
                <label class="form-label">Leave Type</label>
                <select class="form-control" wire:model="leave_type" wire:change="$dispatch('filterChanged')">
                    <option value="">-- Select Leave Type --</option>
                    <option value="Annual Leave">Annual Leave</option>
                    <option value="Sick Leave">Sick Leave</option>
                    <option value="Maternity Leave">Maternity Leave</option>
                    <option value="Paternity Leave">Paternity Leave</option>
                    <option value="Compassionate Leave">Compassionate Leave</option>
                    <option value="Study Leave">Study Leave</option>
                    <option value="Unpaid Leave">Unpaid Leave</option>
                </select>
            </div>

            <div class="col-12 mt-3 text-end d-flex justify-content-end gap-2">

                <button class="btn btn-outline-danger d-flex align-items-center gap-2" wire:click="clearFilters">
                    <!-- Use a "close" or "filter-remove" icon instead of a calendar -->
                    <iconify-icon icon="mdi:filter-remove-outline" class="fs-5"></iconify-icon>
                    <span>Clear Filters</span>
                </button>


                <a href="{{ route('leaves.create') }}" class="btn btn-primary">+ New Request</a>

            </div>

        </div>


        {{-- LEAVE TABLE --}}
        <table class="table mr-3 align-middle">
            <thead class="table-light">
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Leave Type</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Expected Resumption</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($leaves as $leave)
                <tr>
                    <td>
                        @php $employee = $leave->employee; @endphp

                        <div class="align-items-start"> {{-- <-- add padding-start --}}

                            {{-- Employee Details --}}
                            <div class="d-flex flex-column">
                                <span class="fw-semibold text-dark">{{ $employee->name }}</span>

                                @if($employee->title)
                                    <small class="text-secondary d-block">{{ $employee->title }}</small>
                                @endif

                                @if($employee->email)
                                    <small class="text-muted d-block">
                                        <i class="ti ti-mail me-1 text-info"></i>{{ $employee->email }}
                                    </small>
                                @endif

                                @if($employee->id_number)
                                    <small class="text-muted d-block">
                                        <i class="ti ti-id me-1 text-success"></i>ID: {{ $employee->id_number }}
                                    </small>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>{{ $leave->department->name ?? '-' }}</td>
                    <td>{{ $leave->leave_type }}</td>
                    <td>{{ $leave->start_date->format('d/m/Y') }}</td>
                    <td>{{ $leave->end_date->format('d/m/Y') }}</td>
                    <td>
                        @if($leave->status == 'approved')
                            <span class="badge bg-success">Approved</span>
                        @elseif($leave->status == 'pending')
                            <span class="badge bg-warning text-dark">Pending</span>
                        @else
                            <span class="badge bg-danger">Rejected</span>
                        @endif
                    </td>
                    <td>{{ $leave->expected_resumption?->format('d/m/Y') ?? '-' }}</td>
                    <td class="text-center">
                        <div class="ms-auto">
                            <div class="dropdown dropstart">
                                <a href="javascript:void(0)" class="link" id="leave-actions-{{ $leave->id }}"
                                   data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="ti ti-dots fs-6 text-dark"></i>
                                </a>
                                <ul class="dropdown-menu" aria-labelledby="leave-actions-{{ $leave->id }}">
                                    <!-- Edit Leave -->
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="javascript:void(0)"
                                           wire:click="editLeave({{ $leave->id }})">
                                            <iconify-icon icon="mdi:pencil-outline"
                                                          class="text-warning w-4 h-4"></iconify-icon>
                                            <span>Edit</span>
                                        </a>
                                    </li>

                                    <!-- Delete Leave -->
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                           href="javascript:void(0)"
                                           onclick="confirm('Are you sure you want to delete this leave?') || event.stopImmediatePropagation()"
                                           wire:click="deleteLeave({{ $leave->id }})">
                                            <iconify-icon icon="mdi:delete-outline"
                                                          class="text-danger w-4 h-4"></iconify-icon>
                                            <span>Delete</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">No records found</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        {{-- MODAL --}}
        <div class="modal fade" id="leaveModal" tabindex="-1" wire:ignore.self>
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ $editId ? 'Edit Leave Request' : 'Submit Leave Request' }}
                        </h5>
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
                                    <label class="form-label">Leave Type</label>
                                    <select wire:model="leave_type" class="form-control">
                                        <option value="">-- Select Leave Type --</option>
                                        <option value="Annual Leave">Annual Leave</option>
                                        <option value="Sick Leave">Sick Leave</option>
                                        <option value="Maternity Leave">Maternity Leave</option>
                                        <option value="Paternity Leave">Paternity Leave</option>
                                        <option value="Compassionate Leave">Compassionate Leave</option>
                                        <option value="Study Leave">Study Leave</option>
                                        <option value="Unpaid Leave">Unpaid Leave</option>
                                    </select>
                                    @error('leave_type')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>


                                <div class="col-md-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" wire:model="start_date" class="form-control">
                                    @error('start_date') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" wire:model="end_date" class="form-control">
                                    @error('end_date') <small class="text-danger">{{ $message }}</small>@enderror
                                </div>

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

                                @if(auth()->user()->can('view-employees') && $editId)
                                    <div class="col-md-12">
                                        <label class="form-label">Status</label>
                                        <select wire:model="status" class="form-control">
                                            <option value="pending">Pending</option>
                                            <option value="approved">Approved</option>
                                            <option value="rejected">Rejected</option>
                                        </select>
                                    </div>
                                @endif

                            </div>
                        </div>

                        <div class="modal-footer">
                            <button class="btn btn-success" type="submit">
                                {{ $editId ? 'Update' : 'Submit' }}
                            </button>
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



