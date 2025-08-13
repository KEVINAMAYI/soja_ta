<?php

use App\Models\User;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $name, $email, $phone, $employee_type_id, $department_id, $id_number, $active = true;
    public $editId, $employeeTypes, $departments;

    public function mount()
    {
        $this->employeeTypes = EmployeeType::all();
        $this->departments = auth()->user()->employee->organization->departments;

    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees,email,' . $this->editId,
            'phone' => 'nullable|string|max:20',
            'employee_type_id' => 'required|exists:employee_types,id',
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
            $employee = Employee::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'employee_type_id' => $this->employee_type_id,
                'organization_id' => auth()->user()->employee->organization->id,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'user_id' => $user->id,
                'department_id' => $this->department_id,
            ]);

            // ✅ Generate and save QR Code string
            $qrCodeString = $org_id . $employee->id;
            $employee->qr_code = $qrCodeString;
            $employee->save();

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
        $this->employee_type_id = $employee->employee_type_id;
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
                'employee_type_id' => $this->employee_type_id,
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
                                <input type="text" wire:model="name" class="form-control" placeholder="Name"/>
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Email -->
                            <div class="col-md-6 mb-3">
                                <input type="email" wire:model="email" class="form-control" placeholder="Email"/>
                                @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Phone -->
                            <div class="col-md-6 mb-3">
                                <input type="text" wire:model="phone" class="form-control" placeholder="Phone"/>
                                @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Employee Type -->
                            <div class="col-md-6 mb-3">
                                <select wire:model="employee_type_id" class="form-control">
                                    <option value="">Select Employee Type</option>
                                    @foreach ($employeeTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </select>
                                @error('employee_type_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Organization -->
                            <div class="col-md-6 mb-3">
                                <select wire:model="department_id" class="form-control">
                                    <option value="">Select Department</option>
                                    @foreach ($departments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                                @error('department_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Employee Number -->
                            <div class="col-md-6 mb-3">
                                <input type="text" wire:model="id_number" class="form-control"
                                       placeholder="ID Number"/>
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

</div>

@push('scripts')
    <script>
        window.addEventListener('show-employee-modal', () => {
            new bootstrap.Modal(document.getElementById('employeeModal')).show();
        });

        window.addEventListener('hide-employee-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('employeeModal'))?.hide();
        });
    </script>
@endpush






