<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $name;
    public $editId;
    public $description;
    public $manager_id;
    public int $generateQrOnCreate = 1;
    public int $requireEmployeePhoto;
    public int $autoAssignEmployeeId;

    public function mount(): void
    {
        $org = auth()->user()->employee->organization;
        $saved = $org->settings()->where('key', 'generate_employee_qr_on_create')->value('value');
        $requireEmployeePhoto = $org->settings()->where('key', 'require_employee_photo')->value('value');
        $autoAssignEmployeeId = $org->settings()->where('key', 'auto_assign_employee_id')->value('value');
        $this->generateQrOnCreate = (int)($saved ?? 1);
        $this->requireEmployeePhoto = (int)($requireEmployeePhoto ?? 1);
        $this->autoAssignEmployeeId = (int)($autoAssignEmployeeId ?? 1);
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:departments,name,' . $this->editId,
            'description' => 'nullable|string|max:1000',
            'manager_id' => 'nullable|exists:users,id',
        ];
    }

    public function createDepartment()
    {
        $this->validate();

        try {

            DB::beginTransaction();

            $org = auth()->user()->employee->organization;

            Department::create([
                'name' => $this->name,
                'description' => $this->description,
                'manager_id' => $this->manager_id,
                'organization_id' => $org->id
            ]);


            if ($this->manager_id) {
                $manager = User::find($this->manager_id);
                if ($manager && !$manager->hasRole('department-manager')) {
                    $manager->assignRole('department-manager');
                }
            }


            DB::commit();

            $this->dispatch('hide-department-modal');

            LivewireAlert::title('Awesome!')
                ->text('Department added successfully.')
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
                ->text('Failed to add department.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function saveQrCodeSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'generate_employee_qr_on_create';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->generateQrOnCreate = $value;

        LivewireAlert::title('Success!')
            ->text('QR code generation setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveEmployeePhotoSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'require_employee_photo';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->requireEmployeePhoto = $value;

        LivewireAlert::title('Success!')
            ->text('Employee photo requirement setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveAutoAssignEmployeeIdSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'auto_assign_employee_id';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->autoAssignEmployeeId = $value;

        LivewireAlert::title('Success!')
            ->text('Auto-assign employee ID setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    #[On('edit-department')]
    public function editDepartment($id)
    {
        $dept = Department::findOrFail($id);
        $this->editId = $id;
        $this->name = $dept->name;
        $this->description = $dept->description;
        $this->manager_id = $dept->manager_id;

        $this->dispatch('show-department-modal');
    }

    public function updateDepartment()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            Department::findOrFail($this->editId)->update(
                [
                    'name' => $this->name,
                    'description' => $this->description,
                    'manager_id' => $this->manager_id,

                ]);

            if ($this->manager_id) {
                $manager = User::find($this->manager_id);
                if ($manager && !$manager->hasRole('department-manager')) {
                    $manager->assignRole('department-manager');
                }
            }

            DB::commit();

            $this->dispatch('hide-department-modal');

            LivewireAlert::title('Awesome!')
                ->text('Department updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            dd($e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Failed to update department.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('delete-department')]
    public function deleteDepartment($id)
    {
        try {
            DB::beginTransaction();

            Department::findOrFail($id)->delete();

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Department deleted successfully.')
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
                ->text('Failed to delete department.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('discard-department-modal')]
    public function discardDepartmentModal()
    {
        $this->dispatch('hide-department-modal');
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['name', 'editId']);
    }
};

?>

@push('styles')
    <style>
        .dept-shell {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
        }

        .dept-shell-header {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
        }

        .dept-shell-title {
            margin: 0 0 10px;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
        }

        .dept-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .dept-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            color: #4b5563;
            background: #f9fafb;
            line-height: 1;
        }

        .dept-chip-add {
            border-style: dashed;
            border-color: #d1d5db;
            background: #ffffff;
            color: #6b7280;
            text-decoration: none;
        }

        .dept-chip-add:hover {
            color: #111827;
            border-color: #9ca3af;
            background: #f9fafb;
        }

        .dept-defaults-title {
            margin: 0;
            padding: 14px 16px 4px;
            color: #111827;
            font-size: 18px;
            font-weight: 700;
        }

        .dept-defaults-sub {
            margin: 0;
            padding: 0 16px 12px;
            color: #9ca3af;
            font-size: 12px;
            font-weight: 600;
        }

        .dept-setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-top: 1px solid #f1f5f9;
        }

        .dept-setting-label {
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            line-height: 1.1;
        }

        .dept-setting-help {
            color: #9ca3af;
            font-size: 18px;
            margin: 4px 0 0;
            font-weight: 600;
            line-height: 1.2;
        }
        .dept-toggle {
            position: relative;
            width: 40px;
            height: 24px;
            border-radius: 999px;
            border: 0;
            padding: 0;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s ease;
        }

        .dept-toggle::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.18);
            transition: transform 0.2s ease;
        }

        .dept-toggle.is-on {
            background: #e53935;
        }

        .dept-toggle.is-on::after {
            transform: translateX(16px);
        }

        .dept-toggle.is-off {
            background: #d1d5db;
        }

        @media (max-width: 768px) {
            .dept-setting-label {
                font-size: 16px;
            }

            .dept-setting-help {
                font-size: 13px;
            }

            .dept-toggle {
                width: 45px;
                height: 30px;
            }

            .dept-toggle::after {
                width: 22px;
                height: 22px;
            }

            .dept-toggle.is-on::after {
                transform: translateX(22px);
            }
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

@php
    $departmentTags = auth()->user()->employee->organization->departments;
@endphp


<div class="row">
    <div class="col-12">
        <div class="card card-body">
            {{-- Top Bar: Search + Create Button --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                {{-- Left side: Optional Search (if added) --}}
                <div class="mb-2">
                    {{-- Placeholder for filters/search --}}
                </div>

                {{-- Right side: Create Department button --}}
                <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                    data-bs-target="#departmentModal">
                    <i class="ti ti-building fs-5"></i> Add Department
                </a>

            </div>

            {{-- Livewire Table --}}
            <livewire:department-table theme="bootstrap-4"/>

        </div>
    </div>

    <div class="modal fade" id="departmentModal" tabindex="-1"
         aria-labelledby="departmentModalTitle" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editId ? 'Edit Department' : 'New Department' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form wire:submit.prevent="{{ $editId ? 'updateDepartment' : 'createDepartment' }}">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" wire:model="name" id="name" class="form-control"
                                   placeholder="Department Name"/>
                            @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea wire:model="description" id="description" class="form-control" rows="3"
                                      placeholder="Write a short description..."></textarea>
                            @error('description') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="manager_id" class="form-label">Manager</label>
                            <select wire:model="manager_id" id="manager_id" class="form-control">
                                <option value="">Select Manager (Optional)</option>
                                @foreach(User::whereHas('employee', fn($q) => $q->where('organization_id', auth()->user()->employee->organization_id))->get() as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                            @error('manager_id') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    <div class="modal-footer d-flex gap-1">
                        <button type="submit" class="btn btn-success">
                            {{ $editId ? 'Save' : 'Add' }}
                        </button>
                        <button wire:click="$dispatch('discard-department-modal')" type="button"
                                class="btn btn-outline-danger" data-bs-dismiss="modal">
                            Discard
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@push('scripts')
    <script>
        window.addEventListener('show-department-modal', () => {
            new bootstrap.Modal(document.getElementById('departmentModal')).show();
        });

        window.addEventListener('hide-department-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('departmentModal'))?.hide();
        });
    </script>
@endpush
