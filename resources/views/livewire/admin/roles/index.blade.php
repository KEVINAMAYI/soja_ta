<?php

use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $roles = [];
    public $permissions = [];
    public $selectedPermissions = [];
    public $groupedPermissions = [];

    public $name, $editId = null, $search = '';

    public function mount()
    {
        if (!auth()->user()?->can('view-roles')) {
            abort(403, 'Unauthorized.');
        }

        $permissions = Permission::query()->get()->toBase();
        $this->permissions = $permissions;

        $this->groupedPermissions = $this->permissions->groupBy('category');
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:roles,name' . ($this->editId ? ',' . $this->editId : ''),
            'selectedPermissions' => 'required|array|min:1',
        ];
    }


    public function store()
    {
        $this->validate();
        try {
            DB::beginTransaction();

            $orgId = auth()->user()->employee->organization_id ?? null;
            $role = Role::create(['name' => strtolower($this->name), 'organization_id' => $orgId]);
            $role->syncPermissions($this->selectedPermissions);

            DB::commit();

            $this->resetForm();
            $this->dispatch('hide-roles-modal');

            LivewireAlert::text('Role added successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error adding role: ' . $th->getMessage());

            LivewireAlert::text('Failed to add role.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('edit-role')]
    public function editRole($id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->editId = $role->id;
        $this->name = strtolower($role->name);
        $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
        $this->dispatch('show-roles-modal');
    }

    public function updateRole()
    {
        $this->validate();
        try {
            DB::beginTransaction();

            $role = Role::findOrFail($this->editId);
            $role->update(['name' => $this->name]);
            $role->syncPermissions($this->selectedPermissions);

            DB::commit();

            $this->resetForm();
            $this->dispatch('hide-roles-modal');

            LivewireAlert::text('Role updated successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error updating role: ' . $th->getMessage());

            LivewireAlert::text('Failed to update role.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('delete-role')]
    public function deleteRole($id)
    {
        try {
            DB::beginTransaction();

            $role = Role::findOrFail($id);
            $role->delete();

            DB::commit();

            LivewireAlert::text('Role deleted successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error deleting role: ' . $th->getMessage());

            LivewireAlert::text('Failed to delete role.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('discard-role-modal')]
    public function discardRoleModal()
    {
        $this->dispatch('hide-role-modal');
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->name = null;
        $this->editId = null;
        $this->selectedPermissions = [];
    }

}; ?>

<div class="row">
    <div class="col-12">
        <div class="widget-content searchable-container list">
            <div class="card card-body">
                <div class="row">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                        <div>

                        </div>

                        <a href="javascript:void(0)" wire:click="$dispatch('show-roles-modal');"
                           wire:click="resetForm()" class="btn btn-primary d-flex align-items-center">
                            <i class="ti ti-users text-white me-1 fs-5"></i> Add Role
                        </a>
                    </div>
                </div>

                {{-- Livewire Table --}}
                <livewire:role-table theme="bootstrap-4"/>

            </div>

            <!-- Modal -->
            <div class="modal fade" id="rolesModal" tabindex="-1" role="dialog" aria-labelledby="rolesModalTitle"
                 aria-hidden="true" wire:ignore.self>
                <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header d-flex align-items-center">
                            <h5 class="modal-title">{{ $editId ? 'Update' : 'Add' }} Role</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                        </div>
                        <form wire:submit.prevent="{{ $editId ? 'updateRole' : 'store' }}">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <input type="text" wire:model.live="name" class="form-control"
                                           placeholder="Role Name"/>
                                    @error('name')
                                    <small class="text-error">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fs-4 my-2 fw-semibold"></label>
                                    <div class="row gy-1">
                                        @foreach ($groupedPermissions as $module => $perms)
                                            <div class="col-12 my-3">
                                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-1 mb-1"
                                                    style="font-size: 0.85rem;">
                                                    {{ ucfirst($module) }}
                                                </h6>
                                                <div class="row gx-2 gy-1">
                                                    @foreach ($perms as $perm)
                                                        <div class="col-6 col-md-2">
                                                            <div class="form-check form-check-inline">
                                                                <input type="checkbox"
                                                                       value="{{ $perm->name }}"
                                                                       wire:model.live="selectedPermissions"
                                                                       id="perm-{{ Str::slug($perm->name) }}"
                                                                       class="form-check-input"/>
                                                                <label class="form-check-label"
                                                                       for="perm-{{ Str::slug($perm->name) }}"
                                                                       style="font-size: 0.8rem;">
                                                                    {{ ucwords(str_replace(['-', '_'], ' ', $perm->name)) }}
                                                                </label>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @error('selectedPermissions')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>
                            <div class="modal-footer">
                                <div class="d-flex gap-6 m-0">
                                    <button type="submit" class="btn btn-success">
                                        {{ $editId ? 'Save' : 'Add' }}
                                    </button>
                                    <button type="button" wire:click="$dispatch('discard-role-modal')"
                                            class="btn btn-outline-danger"
                                            data-bs-dismiss="modal">
                                        Discard
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>


        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('show-roles-modal', () => {
            new bootstrap.Modal(document.getElementById('rolesModal')).show();
        });

        window.addEventListener('hide-roles-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('rolesModal'))?.hide();
        });
    </script>
@endpush
