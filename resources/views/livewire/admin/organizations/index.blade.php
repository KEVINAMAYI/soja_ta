<?php

use App\Models\Organization;
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
            'name' => 'required|string|max:255|unique:organizations,name,' . $this->editId,
        ];
    }

    public function createOrganization()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            Organization::create(['name' => $this->name]);

            DB::commit();

            $this->dispatch('hide-organization-modal');

            LivewireAlert::title('Awesome!')
                ->text('Organization added successfully.')
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
                ->text('Failed to add organization.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('edit-organization')]
    public function editOrganization($id)
    {
        $org = Organization::findOrFail($id);
        $this->editId = $id;
        $this->name = $org->name;

        $this->dispatch('show-organization-modal');
    }

    public function updateOrganization()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            Organization::findOrFail($this->editId)->update(['name' => $this->name]);

            DB::commit();

            $this->dispatch('hide-organization-modal');

            LivewireAlert::title('Awesome!')
                ->text('Organization updated successfully.')
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
                ->text('Failed to update organization.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('delete-organization')]
    public function deleteOrganization($id)
    {
        try {
            DB::beginTransaction();

            Organization::findOrFail($id)->delete();

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Organization deleted successfully.')
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
                ->text('Failed to delete organization.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('discard-organization-modal')]
    public function discardOrganizationModal()
    {
        $this->dispatch('hide-organization-modal');
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
                <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                   data-bs-target="#organizationModal">
                    <i class="ti ti-building fs-5"></i> Add Organization
                </a>

            </div>


            {{-- Livewire Table --}}
            <livewire:organization-table theme="bootstrap-4"/>

        </div>
    </div>

    <!-- Organization Modal -->
    <div class="modal fade" id="organizationModal" tabindex="-1"
         aria-labelledby="organizationModalTitle" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editId ? 'Edit Organization' : 'New Organization' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form wire:submit.prevent="{{ $editId ? 'updateOrganization' : 'createOrganization' }}">
                    <div class="modal-body">
                        <input type="text" wire:model="name" class="form-control" placeholder="Organization Name"/>
                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>

                    <div class="modal-footer d-flex gap-1">
                        <button type="submit" class="btn btn-success">
                            {{ $editId ? 'Save' : 'Add' }}
                        </button>
                        <button wire:click="$dispatch('discard-organization-modal')" type="button"
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
        window.addEventListener('show-organization-modal', () => {
            new bootstrap.Modal(document.getElementById('organizationModal')).show();
        });

        window.addEventListener('hide-organization-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('organizationModal'))?.hide();
        });
    </script>
@endpush







