<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $name, $email, $phone, $employee_type_id, $department_id, $id_number, $active = true;
    public $editId, $employeeTypes, $departments;
    public $roleId;
    public $shifts;
    public $shift_id;
    public $role;
    public $employeeId;
    public $search = '';
    public $workLocations = [];
    public $selectedLocation = null;
    public $start_date;
    public $end_date;

    public function mount($roleId = null)
    {
        $this->roleId = $roleId;

        if ($roleId) {
            $this->role = Role::find($roleId);
        }

        $this->departments = auth()->user()->employee->organization->departments;
        $this->shifts = auth()->user()->employee->organization->shifts;

    }

    #[On('assign-work-location')]
    public function setEmployee($id)
    {
        $this->employeeId = $id;
        $this->reset(['search', 'workLocations', 'selectedLocation']);
        $this->dispatch('show-work-location-modal');
    }

    #[On('search-work-location')]
    public function searchLocation()
    {
        if (strlen($this->search) > 1) {
            $this->workLocations = WorkLocation::query()
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('address', 'like', "%{$this->search}%")
                ->limit(10)
                ->get();
        } else {
            $this->workLocations = [];
        }
    }

    public function selectWorkLocation($id)
    {
        $this->selectedLocation = WorkLocation::find($id);
        $this->search = $this->selectedLocation->name;
        $this->workLocations = [];
    }


    public function assignWorkLocation()
    {

        $this->validate([
            'employeeId' => 'required|exists:employees,id',
            'selectedLocation.id' => 'required|exists:work_locations,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);


        // create new assignment
        EmployeeAssignment::create([
            'employee_id' => $this->employeeId,
            'work_location_id' => $this->selectedLocation->id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_current' => true
        ]);

        $this->dispatch('hide-work-location-modal');

        LivewireAlert::title('Awesome!')
            ->text('Employee Assigned a work location successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

        $this->reset(['search', 'workLocations', 'selectedLocation']);
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees,email,' . $this->editId,
            'phone' => 'nullable|string|max:20',
            'shift_id' => 'required|exists:shifts,id',
            'department_id' => 'required|exists:departments,id',
            'id_number' => 'required|string|unique:employees,id_number,' . $this->editId,
            'active' => 'boolean',
        ];
    }

    public function createEmployee()
    {
        $this->validate();

        try {

            DB::beginTransaction();

            $org_id = auth()->user()->employee->organization->id;

            // Create the user
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make('password'), // Consider generating or asking for a secure password
            ]);

            // Create the employee
            Employee::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'shift_id' => $this->shift_id,
                'organization_id' => auth()->user()->employee->organization->id,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'user_id' => $user->id,
                'department_id' => $this->department_id,
            ]);

            // Assign the supervisor role
            $user->assignRole('employee');

            //create token to be used for APis
            $user->createToken('Api Token')->plainTextToken;

            DB::commit();

            $this->dispatch('hide-employee-modal');

            LivewireAlert::title('Awesome!')
                ->text('Employee created successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');


        } catch (\Exception $e) {


            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while creating the employee.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }


    #[On('edit-employee')]
    public function editEmployee($id)
    {

        $employee = Employee::findOrFail($id);
        $this->editId = $id;

        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->phone = $employee->phone;
        $this->shift_id = $employee->shift_id;
        $this->department_id = $employee->department_id;
        $this->id_number = $employee->id_number;
        $this->active = $employee->active;

        $this->dispatch('show-employee-modal');

    }

    public function updateEmployee()
    {
        $this->validate();

        try {

            DB::beginTransaction();

            $employee = Employee::findOrFail($this->editId);

            $employee->update([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'shift_id' => $this->shift_id,
                'department_id' => $this->department_id,
                'id_number' => $this->id_number,
                'active' => $this->active,
            ]);

            // Optionally update the related user
            if ($employee->user) {
                $employee->user->update([
                    'name' => $this->name,
                    'email' => $this->email,
                ]);
            }

            DB::commit();
            $this->dispatch('hide-employee-modal');

            LivewireAlert::title('Awesome!')
                ->text('Employee edited successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while updating the employee.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    #[On('delete-employee')]
    public function deleteEmployee($id)
    {
        try {
            DB::beginTransaction();

            $employee = Employee::findOrFail($id);
            $employee->user()->delete();
            $employee->delete();

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Employee deleted successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {

            DB::rollBack();
            logger()->error('Delete employee failed: ' . $e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Something went wrong while deleting the employee.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    #[On('discard-employee-modal')]
    public function discardEmployeeModal()
    {
        $this->dispatch('hide-employee-modal');
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset([
            'name',
            'email',
            'phone',
            'employee_type_id',
            'id_number',
            'active',
            'editId',
        ]);

        $this->active = true;
    }


    public function getBreadcrumbItemsProperty()
    {
        return [
            [
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>'
            ],
            [
                'label' => 'Employees',
                'url' => route('employees.roles.index', ['roleId' => null]),
                'icon' => '<iconify-icon icon="tabler:users" class="fs-5"></iconify-icon>'
            ],
            [
                'label' => ucfirst($this->role?->name) ?? 'All Employees',
                'icon' => match (ucfirst($this->role?->name)) {
                    'Admin' => '<iconify-icon icon="mdi:shield-account" class="fs-5"></iconify-icon>',
                    'Supervisor' => '<iconify-icon icon="mdi:account-tie" class="fs-5"></iconify-icon>',
                    'HR' => '<iconify-icon icon="mdi:account-group" class="fs-5"></iconify-icon>',
                    default => '<iconify-icon icon="tabler:user" class="fs-5"></iconify-icon>',
                }
            ]
        ];
    }


}; ?>

@push('styles')
    <style>
        .btn-outline-secondary {
            margin-left: 0.5rem !important;
            padding: 6px 16px !important;
            border-radius: 8px !important;
            font-size: 0.875rem !important;
            transition: all 0.2s ease-in-out !important;
            border-color: red !important;
        }

        .btn-outline-secondary:hover {
            background-color: #f1f1f1 !important;
            border-color: #aaa !important;
            color: #000 !important;
        }

        .btn-outline-secondary svg,
        .btn-outline-secondary svg * {
            fill: red !important;
            stroke: red !important;
        }

        .btn-outline-secondary:hover svg,
        .btn-outline-secondary:hover svg * {
            fill: white !important;
            stroke: white !important;
        }

        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

    </style>
@endpush


<div class="row">
    <div class="col-12">


        <livewire:admin.system-settings.bread-crumb
            title="{{ ucfirst($role?->name ?? 'Employees') }}"
            :items="$this->breadcrumbItems"
        />


        <div class="card card-body">

            {{-- Top Bar: Search + Create Button --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                {{-- Left side: Optional Search (if added) --}}
                <div class="mb-2">
                    {{-- Placeholder for filters/search --}}
                </div>

                {{-- Right side: Create Employee button --}}
                <div class="mb-2">
                    <a href="javascript:void(0)" id="btn-add-contact"
                       class="btn btn-primary d-flex align-items-center gap-2"
                       data-bs-toggle="modal" data-bs-target="#employeeModal">
                        <i class="ti ti-user-plus fs-5"></i>
                        Create Employee
                    </a>
                </div>
            </div>


            {{-- Livewire Table --}}
            <livewire:employee-table :roleId="$roleId ?? null" theme="bootstrap-4"/>

        </div>
    </div>

    {{-- Modal --}}
    <div class="modal fade" id="employeeModal" tabindex="-1"
         aria-labelledby="employeeModalTitle"
         aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header d-flex align-items-center">
                    <h5 class="modal-title">{{ $editId ? 'Edit Employee' : 'New Employee' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form wire:submit.prevent="{{ $editId ? 'updateEmployee' : 'createEmployee' }}">
                    <div class="modal-body">
                        <div class="row">
                            <!-- Name -->
                            <div class="col-md-6 mb-3">
                                <label for="empName" class="form-label">Full Name</label>
                                <input type="text" id="empName" wire:model="name" class="form-control"
                                       placeholder="John Doe"/>
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Email -->
                            <div class="col-md-6 mb-3">
                                <label for="empEmail" class="form-label">Email Address</label>
                                <input type="email" id="empEmail" wire:model="email" class="form-control"
                                       placeholder="john@example.com"/>
                                @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Phone -->
                            <div class="col-md-6 mb-3">
                                <label for="empPhone" class="form-label">Phone Number</label>
                                <input type="text" id="empPhone" wire:model="phone" class="form-control"
                                       placeholder="+1234567890"/>
                                @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Shift -->
                            <div class="col-md-6 mb-3">
                                <label for="empShift" class="form-label">Shift</label>
                                <select id="empShift" wire:model="shift_id" class="form-control">
                                    <option value="">Select Shift</option>
                                    @foreach ($shifts as $shift)
                                        <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                                    @endforeach
                                </select>
                                @error('shift_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Department -->
                            <div class="col-md-6 mb-3">
                                <label for="empDept" class="form-label">Department</label>
                                <select id="empDept" wire:model="department_id" class="form-control">
                                    <option value="">Select Department</option>
                                    @foreach ($departments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                                @error('department_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- ID Number -->
                            <div class="col-md-6 mb-3">
                                <label for="empIdNumber" class="form-label">Employee ID Number</label>
                                <input type="text" id="empIdNumber" wire:model="id_number" class="form-control"
                                       placeholder="EMP123456"/>
                                @error('id_number') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Active Toggle -->
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input type="checkbox" wire:model="active" class="form-check-input"
                                           id="activeToggle"/>
                                    <label for="activeToggle" class="form-check-label">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="modal-footer d-flex gap-1">
                        <button type="submit" class="btn btn-success">
                            {{ $editId ? 'Save' : 'Add' }}
                        </button>
                        <button wire:click="$dispatch('discard-employee-modal')" type="button"
                                class="btn btn-outline-danger" data-bs-dismiss="modal">Discard
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    {{--Live location Model--}}
    <div class="modal fade" id="workLocationModal" tabindex="-1"
         aria-labelledby="workLocationModalTitle"
         aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header d-flex align-items-center">
                    <h5 class="modal-title">Assign Work Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form wire:submit.prevent="assignWorkLocation">
                    <div class="modal-body">

                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                                       wire:model="start_date">

                                @error('start_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-6">
                                <label class="form-label">End Date (optional)</label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                       wire:model="end_date">

                                @error('end_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>


                        <div class="mb-3">
                            <label for="workLocationSearch" class="form-label">Search Work Location</label>
                            <input type="text" id="workLocationSearch"
                                   wire:keyup.debounce.500ms="$dispatch('search-work-location')"
                                   wire:model="search"
                                   class="form-control"
                                   placeholder="Type to search locations..."/>

                            {{-- Live search results --}}
                            @if(!empty($search) && !$selectedLocation)
                                <ul class="list-group mt-2" style="max-height: 200px; overflow-y:auto;">
                                    @forelse($workLocations as $location)
                                        <li class="list-group-item list-group-item-action"
                                            wire:click="selectWorkLocation({{ $location->id }})"
                                            style="cursor: pointer;">
                                            <strong>{{ $location->name }}</strong>
                                            <br><small class="text-muted">{{ $location->address }}</small>
                                        </li>
                                    @empty
                                        <li class="list-group-item text-muted">No locations found.</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="modal-footer d-flex gap-1">
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


</div>

@push('scripts')
    <script>

        window.addEventListener('show-work-location-modal', () => {
            new bootstrap.Modal(document.getElementById('workLocationModal')).show();
        });

        window.addEventListener('hide-work-location-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('workLocationModal'))?.hide();
        });

        window.addEventListener('show-employee-modal', () => {
            new bootstrap.Modal(document.getElementById('employeeModal')).show();
        });

        window.addEventListener('hide-employee-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('employeeModal'))?.hide();
        });
    </script>
@endpush






