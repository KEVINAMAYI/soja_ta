<?php

use App\Models\EmployeeType;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $name, $description;
    public $editId = null;

    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:employee_types,name,' . $this->editId,
            'description' => 'nullable|string|max:1000',
        ];
    }

    public function save()
    {
        $this->validate();

        DB::beginTransaction();
        try {

            EmployeeType::create([
                'organization_id' => auth()->user()->employee->organization_id,
                'name' => $this->name,
                'description' => $this->description
            ]);
            DB::commit();

            $this->notify('Employee type added successfully.', 'success');
            $this->resetForm();
            $this->dispatch('hide-employee-type-modal', 'refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            dd($e->getMessage());
            $this->notify('Failed to add employee type.', 'error');
        }
    }

    #[On('edit-employee-type')]
    public function edit($id)
    {

        $type = EmployeeType::findOrFail($id);
        $this->editId = $id;
        $this->name = $type->name;
        $this->description = $type->description;

        $this->dispatch('show-employee-type-modal');
    }

    public function update()
    {
        $this->validate();

        DB::beginTransaction();
        try {
            EmployeeType::findOrFail($this->editId)->update(['name' => $this->name, 'description' => $this->description ]);
            DB::commit();

            $this->notify('Employee type updated successfully.', 'success');
            $this->resetForm();
            $this->dispatch('hide-employee-type-modal', 'refreshDatatable');

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            $this->notify('Failed to update employee type.', 'error');
        }
    }

    #[On('delete-employee-type')]
    public function delete($id)
    {
        DB::beginTransaction();
        try {
            EmployeeType::findOrFail($id)->delete();
            DB::commit();

            $this->notify('Employee type deleted successfully.', 'success');
            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            $this->notify('Failed to delete employee type.', 'error');
        }
    }

    #[On('discard-employee-type-modal')]
    public function discard()
    {
        $this->resetForm();
        $this->dispatch('hide-employee-type-modal');
    }

    private function resetForm()
    {
        $this->reset(['name', 'editId']);
    }

    private function notify(string $message, string $type)
    {
        LivewireAlert::title($type === 'success' ? 'Awesome!' : 'Error!')
            ->text($message)
            ->{$type}()
            ->toast()
            ->position('top-end')
            ->show();
    }
};

?>

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

            {{-- Header --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Employee Types</h5>

                <button class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#employeeTypeModal"
                        wire:click="$dispatch('discard-employee-type-modal')">
                    <i class="ti ti-plus"></i> Add Employee Type
                </button>
            </div>

            {{-- Table --}}
            <livewire:employee-type-table theme="bootstrap-4"/>
        </div>
    </div>

    {{-- Modal --}}
    <div wire:ignore.self class="modal fade" id="employeeTypeModal" tabindex="-1" aria-labelledby="employeeTypeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="{{ $editId ? 'update' : 'save' }}">
                    <div class="modal-header">
                        <h5 class="modal-title" id="employeeTypeModalLabel">
                            {{ $editId ? 'Edit Employee Type' : 'Add Employee Type' }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Type Name</label>
                            <input type="text" class="form-control" wire:model.defer="name">
                            @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" rows="3" wire:model.defer="description"></textarea>
                            @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">
                            {{ $editId ? 'Update' : 'Save' }}
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('hide-employee-type-modal', () => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('employeeTypeModal'));
            if (modal) modal.hide();
        });

        window.addEventListener('show-employee-type-modal', () => {
            const modal = new bootstrap.Modal(document.getElementById('employeeTypeModal'));
            modal.show();
        });
    </script>
@endpush






