<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Helpers\PhoneSanitizer;

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
    public $roleName = 'employee';
    public $roles = [];
    public $employee_title;
    public $editEmployee = null;


    public function mount($roleId = null)
    {
        $this->roleId = $roleId;
        $this->editEmployee = auth()->user()->employee;

        if ($roleId) {
            $this->role = Role::find($roleId);
        }

        $this->departments = auth()->user()->employee->organization->departments;
        $this->shifts = auth()->user()->employee->organization->shifts;
        $this->roles = Role::where('name', '!=', 'super-admin')
            ->where('organization_id', auth()->user()->employee->organization_id)
            ->pluck('name', 'id');

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
                ->where('organization_id', auth()->user()->employee->organization_id)
                ->where(function ($query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('address', 'like', "%{$this->search}%");
                })
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
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Delete existing assignment for this employee + location
        EmployeeAssignment::where('employee_id', $this->employeeId)
            ->where('work_location_id', $this->selectedLocation->id)
            ->delete();

        // Create a new assignment
        EmployeeAssignment::create([
            'employee_id' => $this->employeeId,
            'work_location_id' => $this->selectedLocation->id,
            'start_date' => $this->start_date ?? null,
            'end_date' => $this->end_date ?? null,
            'is_current' => true,
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
            'phone' => 'required|string|max:20',
            'shift_id' => 'required|exists:shifts,id',
            'department_id' => 'required|exists:departments,id',
            'id_number' => 'required|string|unique:employees,id_number,' . $this->editId,
            'active' => 'boolean',
            'roleName' => 'required|exists:roles,name', // <-- Role validation
            'employee_title' => 'nullable|string|max:255',
        ];
    }

    public function createEmployee()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $org = auth()->user()->employee->organization;

            // 1. Create the user
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make('password'),
            ]);

            $phone = PhoneSanitizer::sanitize($this->phone);

            // 2. Create the employee
            $employee = Employee::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $phone,
                'shift_id' => $this->shift_id,
                'organization_id' => $org->id,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'user_id' => $user->id,
                'department_id' => $this->department_id,
                'employee_title' => $this->employee_title, // 👈 added here
            ]);

            // 3. Assign the role (comes from UI select or default "employee")
            $user->assignRole($this->roleName);

            // 4. Create API token
            $user->createToken('Api Token')->plainTextToken;

            // 5. Assign default work location
            $defaultLocation = WorkLocation::where('organization_id', $org->id)
                ->where('is_default', true)
                ->first();

            if ($defaultLocation) {
                EmployeeAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'work_location_id' => $defaultLocation->id,
                        'start_date' => null,
                        'end_date' => null,
                        'is_current' => true,
                    ]
                );
            }

            DB::commit();

            // UI feedback
            $this->dispatch('hide-employee-modal');

            LivewireAlert::title('Awesome!')
                ->text('Employee created successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');


            // 6. Send password reset with organization context
//            Password::broker()->sendResetLink(
//                ['email' => $user->email],
//                function ($user, $token) use ($org) {
//                    $user->sendPasswordResetNotificationWithOrganization($token, $org);
//                }
//            );

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
        $this->roleName = $employee->user->roles->first()->name ?? '';
        $this->employee_title = $employee->employee_title; // 👈 added here
        $this->dispatch('refresh-status', employee : $employee);
        $this->dispatch('show-employee-modal');

    }


    public function updateEmployee()
    {
        $this->validate();

        try {

            DB::beginTransaction();

            $employee = Employee::with('user.roles')->findOrFail($this->editId);

            $phone = PhoneSanitizer::sanitize($this->phone);

            $employee->update([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $phone,
                'shift_id' => $this->shift_id,
                'department_id' => $this->department_id,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'employee_title' => $this->employee_title, // 👈 added here
            ]);

            // 3. Remove old roles and assign the new one
            $employee->user->syncRoles([$this->roleName]);

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

    #[On('activate-employee')]
    public function activateEmployee($id)
    {
        try {
            DB::beginTransaction();

            $employee = Employee::findOrFail($id);
            $employee->active = true;
            $employee->save();


            DB::commit();

            LivewireAlert::title('Success!')
                ->text('Employee activated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');
        } catch (\Exception $e) {
            DB::rollBack();
            logger()->error('Activating employee failed: ' . $e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Something went wrong while activating the employee.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('deactivate-employee')]
    public function deactivateEmployee($id)
    {
        try {
            DB::beginTransaction();

            $employee = Employee::findOrFail($id);
            $employee->active = false;
            $employee->save();

            DB::commit();

            LivewireAlert::title('Success!')
                ->text('Employee deactivated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');
        } catch (\Exception $e) {
            DB::rollBack();
            logger()->error('Deactivating employee failed: ' . $e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Something went wrong while deactivating the employee.')
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
                'url' => route('employees.index', ['roleId' => null]),
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

        .btn-group > div > button.dropdown-toggle {
            background-color: #f4f4f5; /* Light grey background */
            border: 1px solid #cbd5e1; /* Soft border */
            color: #1e293b; /* Dark text */
            padding: 8px 8px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease-in-out;
            margin-left: 5px;
        }

        .btn-group > div > button.dropdown-toggle:hover,
        .btn-group > div > button.dropdown-toggle:focus {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #1e293b;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            outline: none;
        }


        .btn-group > .dropdown-menu {
            position: fixed !important; /* Fixed relative to viewport */
            top: 100px !important; /* Distance from top, adjust as needed */
            left: 50% !important; /* Center horizontally */
            transform: translateX(-50%) !important; /* Center by shifting left half of own width */
            width: 600px !important; /* Fixed width, you can also use max-width */
            max-width: 90vw !important; /* Responsive: max width 90% of viewport */
            padding: 24px !important; /* More padding for modal look */
            border-radius: 16px; /* Rounded corners for modal feel */
            background-color: #ffffff;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); /* Softer shadow for floating effect */
            border: 1px solid #e5e7eb;
            z-index: 1050;
            transition: all 0.3s ease-in-out;
            overflow-y: auto; /* Scroll inside if content is tall */
            max-height: 70vh; /* Limit max height */
        }


        .dropdown-menu select {
            width: 100%;
            border-radius: 8px;
            padding: 8px;
            font-size: 0.875rem;
            color: #111827;
            border: 1px solid #d1d5db;
        }


        #table-bulkActionsDropdown {
            background-color: #e14326;
            border: none;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        #table-bulkActionsDropdown:hover {
            background-color: #c2361d; /* darker shade for hover */
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(225, 67, 38, 0.4);
        }

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

        .filter-close-button {
            position: absolute;
            top: 12px;
            right: 25px;
            background: transparent;
            border: none;
            font-size: 1.7rem;
            color: #6b7280;
            cursor: pointer;
            z-index: 1100;
            transition: color 0.2s ease-in-out;
        }

        .filter-close-button:hover {
            color: #ef4444;
        }

        .dropdown-menu .dropdown-item.btn.text-center {
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            color: #374151;
            transition: all 0.2s ease-in-out;
            margin-top: 16px;
        }

        .dropdown-menu .dropdown-item.btn.text-center:hover {
            background-color: #e5e7eb;
            border-color: #cbd5e1;
            color: #1f2937;
        }

        table.dataTable td {
            vertical-align: middle !important;
        }

        .fw-semibold {
            font-weight: 600 !important;
        }

        .text-secondary {
            color: #46259a !important;
        }

        .text-muted {
            color: #adb5bd !important;
        }

        table.dataTable tbody tr:hover {
            background-color: #f8f9fa !important;
        }

        iconify-icon {
            vertical-align: middle !important;
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
            <livewire:employee-table theme="bootstrap-4"/>

        </div>
    </div>

    {{-- Modal --}}
    <div class="modal fade" id="employeeModal" tabindex="-1"
         aria-labelledby="employeeModalTitle"
         aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header d-flex align-items-center">
                    <h5 class="modal-title">{{ $editId ? 'Edit Employee' : 'New Employee' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="row p-4 ">
                    <!-- Shift Status Toggle -->
                    <div class="col-md-12 d-flex justify-content-end">
                        <livewire:admin.employees.shift-status-toggle :employeeId="$editEmployee->id"
                                                                      :shiftStatus="$editEmployee->shift_status"/>
                    </div>
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
                                       placeholder="e.g. 2512345678"/>

                                <!-- Error message -->
                                @error('phone')
                                <small class="text-danger">{{ $message }}</small>
                                @enderror

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
                                <label for="empIdNumber" class="form-label">ID Number</label>
                                <input type="text" id="empIdNumber" wire:model="id_number" class="form-control"
                                />
                                @error('id_number') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Employee Title -->
                            <div class="col-md-6 mb-3">
                                <label for="empTitle" class="form-label">Employee Title</label>
                                <input type="text" id="empTitle" wire:model="employee_title" class="form-control"
                                       placeholder="e.g. Senior Accountant, HR Assistant"/>
                                @error('employee_title') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Role -->
                            <div class="col-md-6 mb-3">
                                <label for="empRole" class="form-label">Role</label>
                                <select id="empRole" wire:model="roleName" class="form-control">
                                    <option value="">Select Role</option>
                                    @foreach ($roles as $id => $name)
                                        <option value="{{ $name }}">{{ ucfirst($name) }}</option>
                                    @endforeach
                                </select>
                                @error('role') <small class="text-danger">{{ $message }}</small> @enderror
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
                                            <strong>{{ ucfirst(str_replace('_', ' ', $location->name)) }}</strong>
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


        document.addEventListener("DOMContentLoaded", () => {
            const observer = new MutationObserver(() => {
                const dropdown = document.querySelector('.dropdown-menu[role="menu"]');

                if (dropdown && !dropdown.querySelector('.filter-close-button')) {
                    const closeBtn = document.createElement('button');
                    closeBtn.innerHTML = '&times;'; // × symbol
                    closeBtn.className = 'filter-close-button';
                    closeBtn.setAttribute('type', 'button');
                    closeBtn.setAttribute('aria-label', 'Close filter');

                    // ✅ CLICK HANDLER GOES HERE — inside the MutationObserver
                    closeBtn.onclick = () => {
                        // Close Alpine dropdown
                        document.querySelector('.dropdown-menu[role="menu"]')?.classList.remove('show');
                    };

                    // Insert as first child inside dropdown
                    dropdown.insertBefore(closeBtn, dropdown.firstChild);
                }
            });

            observer.observe(document.body, {childList: true, subtree: true});
        });

    </script>
@endpush






