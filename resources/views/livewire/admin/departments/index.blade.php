<?php

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $name;
    public $editId;

    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:departments,name,' . $this->editId,
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
                'organization_id' => $org->id
            ]);

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

    #[On('edit-department')]
    public function editDepartment($id)
    {
        $dept = Department::findOrFail($id);
        $this->editId = $id;
        $this->name = $dept->name;

        $this->dispatch('show-department-modal');
    }

    public function updateDepartment()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            Department::findOrFail($this->editId)->update(['name' => $this->name]);

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
                            <label for="name" class="form-label">Department Name</label>
                            <input type="text" wire:model="name" id="name" class="form-control"
                                   placeholder="Department Name"/>
                            @error('name') <small class="text-danger">{{ $message }}</small> @enderror
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
