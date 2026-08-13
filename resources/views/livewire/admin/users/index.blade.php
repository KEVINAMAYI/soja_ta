<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public $organizationId;
    public string $search = '';
    public string $roleFilter = 'All';
    public bool $showUserModal = false;
    public ?int $editingUserId = null;
    public bool $accountActive = true;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user && $user->employee && $user->employee->organization_id) {
            $this->organizationId = $user->employee->organization_id;
            $this->org = Organization::findOrFail($this->organizationId);
            return;
        }

        abort(403, 'No organization found for this user.');
    }

    protected function usersQuery(): Builder
    {
        return User::query()
            ->with(['roles:id,name', 'employee:id,user_id,organization_id,phone,active,is_user'])
            ->whereHas('employee', function ($query) {
                $query->where('organization_id', $this->organizationId)
                    ->where('is_user', true);
            });
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->editingUserId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', Rule::in(['Admin', 'Employee', 'Supervisor'])],
        ];
    }

    public function openAddUserModal(): void
    {
        $this->resetForm();
        $this->showUserModal = true;
        $this->dispatch('show-user-modal');
    }

    public function openEditUserModal(int $id): void
    {
        $user = $this->usersQuery()->whereKey($id)->firstOrFail();

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) ($user->employee?->phone ?? '');
        $this->role = $user->roles->first()?->name ?? '';
        $this->accountActive = (bool) ($user->employee?->active ?? true);

        $this->showUserModal = true;
        $this->dispatch('show-user-modal');
    }

    public function closeUserModal(): void
    {
        $this->showUserModal = false;
        $this->resetForm();
        $this->dispatch('hide-user-modal');
    }

    public function saveUser(): void
    {
        $validated = $this->validate();

        DB::transaction(function () use ($validated) {
            if ($this->editingUserId) {
                $user = $this->usersQuery()->whereKey($this->editingUserId)->firstOrFail();

                $user->update([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                ]);

                if ($user->employee) {
                    $user->employee->update([
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'phone' => $validated['phone'] ?: null,
                    ]);
                }

                $user->syncRoles([$validated['role']]);

                return;
            }

            $departmentId = auth()->user()?->employee?->department_id
                ?? Department::where('organization_id', $this->organizationId)->value('id');

            if (! $departmentId) {
                throw ValidationException::withMessages([
                    'role' => 'No department is configured for this organization. Please create one first.',
                ]);
            }

            $shiftId = Shift::where('organization_id', $this->organizationId)->value('id');

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(32),
            ]);

            Employee::create([
                'organization_id' => $this->organizationId,
                'department_id' => $departmentId,
                'shift_id' => $shiftId,
                'user_id' => $user->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
                'active' => true,
                'is_user' => true,
            ]);

            $user->assignRole($validated['role']);
        });

        $this->closeUserModal();

        LivewireAlert::title('Success!')->text($validated['name'] . ' saved successfully.')->success()->toast()->position('top-end')->show();
            
    }

    public function toggleUserAccess(?int $id = null): void
    {
        $targetId = $id ?? $this->editingUserId;

        if (! $targetId) {
            return;
        }

        $user = $this->usersQuery()->whereKey($targetId)->firstOrFail();

        if (! $user->employee) {
            return;
        }

        $user->update([
            'is_active' => ! ((bool) $user->is_active),
        ]);

        if ($this->editingUserId === $user->id) {
            $this->accountActive = (bool) $user->fresh()->is_active;
        }
        
        LivewireAlert::title('Success!')->text($user->name . ' updated successfully.')->success()->toast()->position('top-end')->show();
        
    }

    public function deleteUser(int $id): void
    {
        // $this->usersQuery()->whereKey($id)->delete();

        // if ($this->getPaginatedUsersProperty()->isEmpty() && $this->getPage() > 1) {
        //     $this->previousPage();
        // }

        $this->toggleUserAccess($id);
    }

    public function setRoleFilter(string $role): void
    {
        $this->roleFilter = $role;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetForm(): void
    {
        $this->editingUserId = null;
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->role = '';
        $this->accountActive = true;
    }

    #[On('discard-user-modal')]
    public function discardUserModal(): void
    {
        $this->closeUserModal();
    }

    public function getPaginatedUsersProperty()
    {
        $search = trim($this->search);

        return $this->usersQuery()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($this->roleFilter !== 'All', function (Builder $query) {
                $query->whereHas('roles', function (Builder $roleQuery) {
                    $roleQuery->where('name', $this->roleFilter);
                });
            })
            ->latest('id')
            ->paginate(10);
    }

    public function getUsersCountProperty(): int
    {
        return $this->usersQuery()->count();
    }
};
?>


<div class="user-management-wrapper">
    <div class="user-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="d-flex align-items-center gap-2 fw-bold fs-1 user-title">
            <span>Users</span>
            <span class="count-badge">{{ $this->usersCount }}</span>
        </div>

        <div class="d-flex align-items-center gap-3 user-toolbar">
            <div class="search-box position-relative">
                <span class="search-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 0 1 5.944 12.444l4.361 4.36 1.414-1.414-4.36-4.361A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/></svg>
                </span>
                <input
                    type="text"
                    wire:model.live="search"
                    class="form-control search-input"
                    placeholder="Search by name or email"
                    aria-label="Search users"
                />
            </div>

            <div class="dropdown">
                <button class="btn filter-btn dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v2H4zm4 6h8v2H8zm3 6h2v2h-2z"/></svg>
                    <span>Filter</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button type="button" class="dropdown-item {{ $roleFilter === 'All' ? 'active' : '' }}" wire:click="setRoleFilter('All')">All roles</button></li>
                    <li><button type="button" class="dropdown-item {{ $roleFilter === 'Admin' ? 'active' : '' }}" wire:click="setRoleFilter('Admin')">Admin</button></li>
                    <li><button type="button" class="dropdown-item {{ $roleFilter === 'Employee' ? 'active' : '' }}" wire:click="setRoleFilter('Employee')">Employee</button></li>
                    <li><button type="button" class="dropdown-item {{ $roleFilter === 'Supervisor' ? 'active' : '' }}" wire:click="setRoleFilter('Supervisor')">Supervisor</button></li>
                </ul>
            </div>

            <button type="button" class="btn btn-danger add-user-btn" wire:click="openAddUserModal">
                <span class="plus-sign">+</span>
                <span>Add user</span>
            </button>
        </div>
    </div>

    <div class="users-table-wrap">
        <table class="table users-table align-middle mb-0">
            <thead>
                <tr>
                    <th class="text-uppercase">Name</th>
                    <th class="text-uppercase">Role</th>
                    <th class="text-uppercase">Status</th>
                    <th class="text-uppercase">Last login</th>
                    <th class="text-uppercase text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->paginatedUsers as $user)
                    <tr>
                        <td>
                            <div class="user-info d-flex align-items-center gap-3">
                                <div class="user-avatar" style="background: #eaeefc; color: #1f2937;">
                                    {{ strtoupper(substr($user->name, 0, 2)) }}
                                </div>
                                <div class="user-meta">
                                    <div class="user-name">{{ $user->name }}</div>
                                    <div class="user-email">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="role-pill">{{ $user->roles->first()?->name ?? 'N/A' }}</span>
                        </td>
                        <td>
                            <span class="status-pill {{ ($user->employee?->active ?? false) ? 'active' : 'inactive' }}">
                                <span class="status-dot"></span>
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="last-login">{{ $user->last_login_at ? $user->last_login_at : 'Never' }}</td>
                        <td class="text-end">
                            <div class="dropdown table-actions">
                                <button class="action-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions">
                                    <span></span><span></span><span></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li class="d-flex align-items-center dropdown-item">
                                        <span class="me-0">
                                            <i class="ti ti-pencil"></i>
                                        </span>
                                        <button type="button" class="btn p-0 ms-1 fw-bold dropdown-item" wire:click="openEditUserModal({{ $user->id }})">Edit user</button></li>
                                    
                                    <li class="d-flex align-items-center dropdown-item">
                                        @if ($user->is_active)
                                            <span class="me-0">
                                                <i class="ti ti-lock"></i>
                                            </span>
                                        @else
                                            <span class="me-0">
                                                <i class="ti ti-lock-open"></i>
                                            </span>
                                        @endif
                                        <button type="button" class="btn p-0 ms-1 fw-bold dropdown-item" wire:click="toggleUserAccess({{ $user->id }})">
                                            {{ $user->is_active ? 'Deactivate user' : 'Reactivate user' }}
                                        </button>
                                    </li>

                                    <!-- Add line break -->
                                    <li><hr class="dropdown-divider"></li>
                                    <li class="d-flex align-items-center dropdown-item text-danger">
                                        <span class="me-0">
                                            <i class="ti ti-trash-x-filled"></i>
                                        </span>

                                        <button
                                            type="button"
                                            class="text-danger btn p-0 ms-1 fw-bold"
                                            wire:click="deleteUser({{ $user->id }})">
                                            Delete user
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">No users match your search.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="users-pagination d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 px-3 py-3 border-top bg-white">
            <div class="pagination-summary text-muted small">
                @if ($this->paginatedUsers->total() > 0)
                    Showing {{ $this->paginatedUsers->firstItem() }} to {{ $this->paginatedUsers->lastItem() }} of {{ $this->paginatedUsers->total() }} users
                @else
                    Showing 0 users
                @endif
            </div>

            <div class="d-flex align-items-center gap-2">
                <button
                    type="button"
                    class="btn btn-sm users-page-btn"
                    wire:click="previousPage"
                    @disabled($this->paginatedUsers->onFirstPage())
                >
                    Previous
                </button>

                <span class="small text-muted px-1">Page {{ $this->paginatedUsers->currentPage() }} of {{ $this->paginatedUsers->lastPage() }}</span>

                <button
                    type="button"
                    class="btn btn-sm users-page-btn"
                    wire:click="nextPage"
                    @disabled(! $this->paginatedUsers->hasMorePages())
                >
                    Next
                </button>
            </div>
        </div>
    </div>

    <div class="modal fade {{ $showUserModal ? 'show d-block' : '' }}" id="userModal" tabindex="-1" role="dialog" aria-labelledby="user-modal-label" aria-hidden="{{ $showUserModal ? 'false' : 'true' }}" style="{{ $showUserModal ? 'display: block; background: rgba(15, 23, 42, 0.24);' : 'display: none;' }}">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 900px;">
            <div class="modal-content user-modal-content shadow-sm border-0">
                <div class="modal-header border-0 align-items-center px-4 pt-4 pb-2">
                    <div class="d-flex align-items-center gap-3 modal-title-wrap">
                        <div class="modal-user-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 8a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Zm-9 10a5.5 5.5 0 0 1 11 0v1h-11v-1Zm2 0v-.5A3.5 3.5 0 0 1 12 14.5a3.5 3.5 0 0 1 3.5 3.5v.5H8.5Zm12.5-9h-1.5v2h-2v1.5h2v2H21v-2h2V13h-2v-2h-1.5V10h1.5V8Z"/></svg>
                        </div>
                        <h2 id="user-modal-label" class="modal-title mb-0">{{ $editingUserId ? 'Edit user' : 'Add user' }}</h2>
                    </div>
                    <button type="button" class="btn-close" aria-label="Close" wire:click="closeUserModal"></button>
                </div>

                <div class="modal-body px-4 pb-4">
                    <p class="modal-subtitle">{{ $editingUserId ? 'Update account details and access.' : 'Create an account and assign access.' }}</p>
                    <form wire:submit.prevent="saveUser" class="user-form">
                        <div class="mb-3">
                            <label for="user-name" class="form-label d-block mb-2">Full name</label>
                            <input id="user-name" type="text" class="form-control user-field" wire:model.defer="name" placeholder="Jane Wambui" />
                            @error('name')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="user-email" class="form-label d-block mb-2">Email</label>
                            <input id="user-email" type="email" class="form-control user-field" wire:model.defer="email" placeholder="jane.wambui@statpak.co.ke" />
                            @error('email')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="user-phone" class="form-label d-block mb-2">Phone</label>
                            <input id="user-phone" type="text" class="form-control user-field" wire:model.defer="phone" placeholder="+254 7XX XXX XXX" />
                        </div>

                        <div class="mb-4">
                            <label for="user-role" class="form-label d-block mb-2">Role</label>
                            <select id="user-role" class="form-select user-field" wire:model.defer="role">
                                <option value="">Select role</option>
                                <option value="Admin">Admin</option>
                                <option value="Employee">Employee</option>
                                <option value="Supervisor">Supervisor</option>
                            </select>
                            @error('role')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="account-access-card mb-4" @if(! $editingUserId) style="display: none;" @endif>
                            <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
                                <div>
                                    <div class="account-access-title">Account access</div>
                                    <div class="account-access-subtitle">
                                        {{ $accountActive ? 'Active - the user can currently sign in.' : 'Inactive - the user cannot currently sign in.' }}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="btn account-access-btn {{ $accountActive ? 'account-access-btn-danger' : 'account-access-btn-success' }}"
                                    wire:click="toggleUserAccess"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"/></svg>
                                    <span>{{ $accountActive ? 'Deactivate account' : 'Reactivate account' }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-3 pt-2">
                            <button type="button" class="btn btn-outline-secondary user-cancel-btn" wire:click="closeUserModal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger user-submit-btn">
                                {{ $editingUserId ? 'Save changes' : 'Add user' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>


@push('scripts')
    <script>
        window.addEventListener('show-user-modal', () => {
            const modal = document.getElementById('userModal');
            if (!modal) return;
            const bootstrapModal = new bootstrap.Modal(modal, {
                backdrop: 'static',
                keyboard: false,
            });
            bootstrapModal.show();
        });

        window.addEventListener('hide-user-modal', () => {
            const modal = document.getElementById('userModal');
            if (!modal) return;
            const instance = bootstrap.Modal.getInstance(modal);
            instance ? instance.hide() : null;
        });
    </script>
@endpush

@push('styles')
    <style>
        .user-management-wrapper {
            background: #f5f5f5;
            border-radius: 18px;
            padding: 28px 26px 10px;
            border: 1px solid #e5e5e5;
        }

        .user-title {
            font-size: 2rem;
            color: #1f2937;
            letter-spacing: -0.03em;
        }

        .count-badge {
            min-width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #e5e7eb;
            color: #1f2937;
            font-size: 1.25rem;
            padding: 0 10px;
            font-weight: 700;
        }

        .user-toolbar {
            flex-wrap: wrap;
        }

        .search-box {
            width: min(430px, 52vw);
        }

        .search-input {
            border-radius: 12px;
            border: 1px solid #d3d7dc;
            background: #f9fafb;
            height: 48px;
            padding-left: 46px;
            font-size: 1.1rem;
            color: #111827;
        }

        .search-input:focus {
            border-color: #c7ccd4;
            box-shadow: none;
            background: #fff;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #475467;
        }

        .search-icon svg {
            width: 100%;
            height: 100%;
            fill: currentColor;
        }

        .filter-btn {
            height: 48px;
            min-width: 112px;
            border-radius: 12px;
            border: 1px solid #d3d7dc;
            background: #fff;
            color: #1f2937;
            font-weight: 600;
        }

        .filter-btn svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .filter-btn::after {
            margin-left: 10px;
        }

        .add-user-btn {
            height: 48px;
            border-radius: 12px;
            padding: 0 20px;
            font-size: 1.08rem;
            font-weight: 700;
            background: #d91c1c;
            border: 0;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .add-user-btn:hover {
            background: #c11313;
        }

        .plus-sign {
            font-size: 2rem;
            line-height: 1;
            margin-top: -2px;
        }

        .users-table-wrap {
            background: #f8f8f8;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .users-table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .users-table thead th {
            background: #f3f4f6;
            color: #667085;
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            font-weight: 700;
            padding: 1.1rem 1.2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .users-table tbody td {
            background: #fff;
            padding: 1rem 1.2rem;
            border-bottom: 1px solid #eceef1;
            vertical-align: middle;
            font-size: 1.05rem;
        }

        .users-table tbody tr:hover td {
            background: #fafbfc;
        }

        .users-pagination {
            background: #fff;
        }

        .users-page-btn {
            min-width: 90px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid #d4d9e1;
            color: #344054;
            font-weight: 600;
            background: #fff;
        }

        .users-page-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .users-page-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .user-info {
            min-width: 260px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            border: 1px solid rgba(15, 23, 42, 0.06);
        }

        .user-meta {
            line-height: 1.25;
        }

        .user-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: #111827;
        }

        .user-email {
            font-size: 0.76rem;
            color: #4b5563;
            margin-top: 4px;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef0f3;
            color: #475467;
            border-radius: 999px;
            min-width: 90px;
            height: 34px;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0 14px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 120px;
            height: 34px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 0 12px;
            border: 1px solid transparent;
        }

        .status-pill.active {
            background: #dff5e7;
            color: #1f8c4d;
            border-color: rgba(31, 140, 77, 0.12);
        }

        .status-pill.inactive {
            background: #eef0f3;
            color: #475467;
            border-color: rgba(71, 84, 103, 0.12);
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
            display: inline-block;
        }

        .last-login {
            color: #475467;
            font-weight: 500;
        }

        .table-actions {
            display: inline-block;
        }

        .action-menu-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid #e5e7eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 0;
        }

        .action-menu-btn span {
            width: 4px;
            height: 4px;
            background: #475467;
            border-radius: 50%;
            display: block;
        }

        .user-modal-content {
            border-radius: 22px;
            overflow: hidden;
        }

        .modal-title-wrap {
            padding: 8px 0 0;
        }

        .modal-user-icon {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
        }

        .modal-user-icon svg {
            width: 100%;
            height: 100%;
            fill: currentColor;
        }

        .modal-title {
            font-size: 2.15rem;
            font-weight: 700;
            color: #1f2937;
            letter-spacing: -0.02em;
        }

        .modal-subtitle {
            font-size: 1.1rem;
            color: #475467;
            margin-bottom: 1.4rem;
        }

        .user-form label {
            font-size: 1.02rem;
            font-weight: 600;
            color: #1f2937;
        }

        .user-field {
            width: 100%;
            min-height: 48px;
            border-radius: 12px;
            border: 1px solid #d2d6dc;
            background: #fff;
            padding: 0.8rem 1rem;
            font-size: 1.05rem;
            color: #111827;
        }

        .user-field:focus {
            border-color: #a7b0bc;
            box-shadow: none;
        }

        .user-cancel-btn {
            border-radius: 12px;
            min-width: 120px;
            height: 48px;
            border: 1px solid #d6dbe2;
            background: #fff;
            color: #1f2937;
            font-weight: 700;
        }

        .user-submit-btn {
            border-radius: 12px;
            min-width: 140px;
            height: 48px;
            background: #d91c1c;
            border: 0;
            color: #fff;
            font-weight: 700;
        }

        .user-submit-btn:hover {
            background: #c11313;
        }

        .account-access-card {
            border: 1px solid #d9dee6;
            border-radius: 14px;
            padding: 14px 16px;
            background: #fcfcfd;
        }

        .account-access-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #101828;
            line-height: 1.2;
        }

        .account-access-subtitle {
            margin-top: 4px;
            color: #344054;
            font-size: 1.02rem;
            line-height: 1.35;
            max-width: 360px;
        }

        .account-access-btn {
            border-radius: 12px;
            min-height: 44px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .account-access-btn svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .account-access-btn-danger {
            color: #d92d20;
            background: #fff5f5;
            border-color: #f5c8c5;
        }

        .account-access-btn-danger:hover {
            color: #b32318;
            border-color: #efb0ac;
            background: #ffeded;
        }

        .account-access-btn-success {
            color: #067647;
            background: #ecfdf3;
            border-color: #b7ebcd;
        }

        .account-access-btn-success:hover {
            color: #085d3a;
            border-color: #9fe3be;
            background: #e2f9ed;
        }

        @media (max-width: 767.98px) {
            .user-management-wrapper {
                padding: 18px 14px 8px;
            }

            .search-box {
                width: 100%;
                min-width: 100%;
            }

            .user-toolbar {
                width: 100%;
            }

            .filter-btn,
            .add-user-btn {
                width: 100%;
                justify-content: center;
            }

            .users-table-wrap {
                overflow-x: auto;
            }

            .users-table {
                min-width: 760px;
            }
        }
    </style>
@endpush
