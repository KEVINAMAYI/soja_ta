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

    public function demoteToEmployee(?int $id = null): void
    {
        $targetId = $id ?? $this->editingUserId;

        if (! $targetId) {
            return;
        }

        $user = $this->usersQuery()->whereKey($targetId)->firstOrFail();

        if (!$user->employee) {
            return;
        }

        $user->employee->update([
            'is_user' => false
        ]);

        if ($this->editingUserId === $user->id) {
            $this->accountActive = (bool) $user->fresh()->is_active;
        }
        
        LivewireAlert::title('Success!')->text($user->name . ' Moved successfully.')->success()->toast()->position('top-end')->show();
        
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


<div class="sj-users-shell">

        <div class="toolbar">
            <div class="toolbar-left">
                <h2>Users</h2>
                <span class="count-pill">{{ $this->usersCount }}</span>
            </div>

            <div class="toolbar-right">
                <div class="search">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path></svg>
                    <input
                        type="text"
                        wire:model.live="search"
                        placeholder="Search by name or email"
                        aria-label="Search users"
                    >
                </div>

                <div class="dropdown">
                    <button class="btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M7 12h10M10 19h4"></path></svg>
                        Filter
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end sj-filter-menu">
                        <li><button type="button" class="dropdown-item {{ $roleFilter === 'All' ? 'active' : '' }}" wire:click="setRoleFilter('All')">All roles</button></li>
                        <li><button type="button" class="dropdown-item {{ $roleFilter === 'Admin' ? 'active' : '' }}" wire:click="setRoleFilter('Admin')">Admin</button></li>
                        <li><button type="button" class="dropdown-item {{ $roleFilter === 'Employee' ? 'active' : '' }}" wire:click="setRoleFilter('Employee')">Employee</button></li>
                        <li><button type="button" class="dropdown-item {{ $roleFilter === 'Supervisor' ? 'active' : '' }}" wire:click="setRoleFilter('Supervisor')">Supervisor</button></li>
                    </ul>
                </div>

                <button class="btn btn-primary" type="button" wire:click="openAddUserModal">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
                    Add user
                </button>
            </div>
        </div>

        <table class="p-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->paginatedUsers as $user)
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="avatar">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
                                <div>
                                    <div class="u-name">{{ $user->name }}</div>
                                    <div class="u-email">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-pill">{{ $user->roles->first()?->name ?? 'N/A' }}</span></td>
                        <td>
                            <span class="status {{ $user->is_active ? 'active' : 'inactive' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="last-login">{{ $user->last_login_at ?: 'Never' }}</td>
                        <td class="action-cell">
                            <div class="dropdown">
                                <button class="kebab" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions">
                                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.5"></circle><circle cx="12" cy="12" r="1.5"></circle><circle cx="12" cy="19" r="1.5"></circle></svg>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end sj-action-menu">
                                    <li class="menu-item p-2">
                                        <span class="me-0">
                                            <i class="ti ti-pencil"></i>
                                        </span>
                                        <button type="button" class="menu-item m-0" wire:click="openEditUserModal({{ $user->id }})">Edit user</button>
                                    </li>
                                    <li class="menu-item p-2">
                                        @if ($user->is_active)
                                            <span class="me-0">
                                                <i class="ti ti-lock"></i>
                                            </span>
                                        @else
                                            <span class="me-0">
                                                <i class="ti ti-lock-open"></i>
                                            </span>
                                        @endif
                                        <button type="button" class="menu-item" wire:click="toggleUserAccess({{ $user->id }})">{{ $user->is_active ? 'Deactivate user' : 'Reactivate user' }}</button>
                                    </li>
                                    <li class="menu-item p-2">
                                        <span class="me-0">
                                            <i class="ti ti-lock-off"></i>
                                        </span>
                                        <button type="button" class="menu-item" wire:click="demoteToEmployee({{ $user->id }})">Move to employees</button>
                                    </li>
                                    <li><hr class="menu-divider"></li>
                                    <li class="menu-item p-2">
                                        <span class="me-0">
                                            <i class="ti ti-trash-x-filled"></i>
                                        </span>
                                        <button type="button" class="menu-item danger" wire:click="deleteUser({{ $user->id }})">Delete user</button>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--ink-3);">No users match your search.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="footer-row">
            <span>
                @if ($this->paginatedUsers->total() > 0)
                    Showing {{ $this->paginatedUsers->firstItem() }} to {{ $this->paginatedUsers->lastItem() }} of {{ $this->paginatedUsers->total() }} users
                @else
                    Showing 0 users
                @endif
            </span>
            <div class="page-btns">
                <button class="page-btn" type="button" wire:click="previousPage" @disabled($this->paginatedUsers->onFirstPage())>&lsaquo;</button>
                <button class="page-btn current" type="button">{{ $this->paginatedUsers->currentPage() }}</button>
                <button class="page-btn" type="button" wire:click="nextPage" @disabled(! $this->paginatedUsers->hasMorePages())>&rsaquo;</button>
            </div>
        </div>

    <div class="holding-modal">
        <div class="modal fade {{ $showUserModal ? 'show d-block' : '' }}" id="userModal" tabindex="-1" role="dialog" aria-labelledby="user-modal-label" aria-hidden="{{ $showUserModal ? 'false' : 'true' }}" style="{{ $showUserModal ? 'display: block; background: rgba(15, 23, 42, 0.24);' : 'display: none;' }}">
            <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 900px;">
                <div class="modal-content user-modal-content shadow-sm border-0">
                    <div class="modal-header border-0 align-items-center px-4 pt-4 pb-2">
                        <div class="d-flex align-items-center gap-1 modal-title-wrap">
                            <div class="modal-user-icon">
                                <i class="ti ti-user-plus text-danger me-0"></i>
                                <!-- <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 8a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Zm-9 10a5.5 5.5 0 0 1 11 0v1h-11v-1Zm2 0v-.5A3.5 3.5 0 0 1 12 14.5a3.5 3.5 0 0 1 3.5 3.5v.5H8.5Zm12.5-9h-1.5v2h-2v1.5h2v2H21v-2h2V13h-2v-2h-1.5V10h1.5V8Z"/></svg> -->
                            </div>
                            <h5 id="user-modal-label" class=" mb-0 ms-0">{{ $editingUserId ? 'Edit user' : 'Add user' }}</h5>
                        </div>
                        <button type="button" class="btn-close" aria-label="Close" wire:click="closeUserModal"></button>
                    </div>

                    <div class="modal-body px-4 pb-4">
                        <p class="modal-subtitle">{{ $editingUserId ? 'Update account details and access.' : 'Create an account and assign access.' }}</p>
                        <form wire:submit.prevent="saveUser" class="user-form">
                            <div class="field">
                                <label for="user-name" >Full name</label>
                                <input id="user-name" type="text" wire:model.defer="name" placeholder="Jane Wambui" />
                                @error('name')
                                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                                @enderror
                            </div>

                            <div class="field mt-1">
                                <label for="user-email" class="">Email</label>
                                <input id="user-email" type="email" class="" wire:model.defer="email" placeholder="jane.wambui@statpak.co.ke" />
                                @error('email')
                                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                                @enderror
                            </div>

                            <div class="field mt-1">
                                <label for="user-phone" >Phone</label>
                                <input id="user-phone" type="text" wire:model.defer="phone" placeholder="+254 7XX XXX XXX" />
                            </div>

                            <div class="field mt-1">
                                <label for="user-role" >Role</label>
                                <select id="user-role" wire:model.defer="role">
                                    <option value="">Select role</option>
                                    <option value="Admin">Admin</option>
                                    <option value="Employee">Employee</option>
                                    <option value="Supervisor">Supervisor</option>
                                </select>
                                @error('role')
                                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                                @enderror
                            </div>

                            <div class="account-access-card mb-1 mt-3" @if(! $editingUserId) style="display: none;" @endif>
                                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
                                    <div>
                                        <div class="account-access-title">Account access</div>
                                        <div class="account-access-subtitle">
                                            {{ $accountActive ? 'Active - the user can currently sign in.' : 'Inactive - the user cannot currently sign in.' }}
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn account-access-btn {{ $accountActive ? 'account-access-btn-danger' : 'account-access-btn-success' }}
                                                {{ $accountActive ? 'text-primary' : 'text-success' }}"
                                        wire:click="toggleUserAccess"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"/></svg>
                                        <span>{{ $accountActive ? 'Deactivate account' : 'Reactivate account' }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="modal-foot">
                                <button type="button" class="btn" wire:click="closeUserModal">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    {{ $editingUserId ? 'Save changes' : 'Add user' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('styles')
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        .sj-users-shell {
            --sj-red: #c81e2c;
            --sj-red-dark: #9e1622;
            --sj-red-tint: #fbe9ea;
            --ink: #1b1d22;
            --ink-2: #565b66;
            --ink-3: #8b9099;
            --line: #e7e8ec;
            --line-strong: #d7d9e0;
            --surface: #ffffff;
            --page: #f5f6f8;
            --panel: #fafafb;
            --green: #1c8a4e;
            --green-tint: #e8f6ee;
            --gray-tint: #efeff1;
            --radius: 10px;
            --shadow: 0 1px 2px rgba(20, 20, 30, 0.04), 0 8px 24px rgba(20, 20, 30, 0.05);
            font-family: 'Inter', -apple-system, sans-serif;
            /* background: var(--page); */
            color: var(--ink);
            /* border: 1px solid var(--line);
            border-radius: 12px;*/
            padding: 0; 
        }


        .sj-users-shell .icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            vertical-align: -3px;
        }

        .sj-users-shell .pageheader {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            /* padding: 20px 24px; */
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .sj-users-shell .pageheader h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.01em;
        }

        .sj-users-shell .crumbs {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            color: var(--ink-3);
        }

        .sj-users-shell .sep {
            color: var(--ink-3);
            font-size: 13px;
        }

        .sj-users-shell .crumb-pill {
            background: var(--sj-red-tint);
            color: var(--sj-red);
            border-radius: 100px;
            padding: 7px 14px;
            font-weight: 600;
        }

        .sj-users-shell .sectionnav {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 0 14px;
        }

        .sj-users-shell .section-tab {
            padding: 17px 16px;
            font-size: 14.5px;
            font-weight: 600;
            color: var(--ink-2);
            border-bottom: 2.5px solid transparent;
        }

        .sj-users-shell .section-tab.active {
            color: var(--sj-red);
            border-bottom-color: var(--sj-red);
        }

        .sj-users-shell .tabnav {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-top: none;
            padding: 0 14px;
        }

        .sj-users-shell .tab {
            padding: 14px 12px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--ink-2);
            border-bottom: 2px solid transparent;
        }

        .sj-users-shell .tab.active {
            color: var(--sj-red);
            border-bottom-color: var(--sj-red);
            font-weight: 600;
        }

        .sj-users-shell .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
        }

        .sj-users-shell .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }

        .sj-users-shell .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sj-users-shell .toolbar h2 {
            font-size: 17px;
            font-weight: 600;
            margin: 0;
        }

        .sj-users-shell .count-pill {
            background: var(--gray-tint);
            color: var(--ink-2);
            font-size: 12px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 100px;
        }

        .sj-users-shell .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            justify-content: flex-end;
        }

        .sj-users-shell .search {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 220px;
            background: var(--panel);
        }

        .sj-users-shell .search input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            width: 100%;
            color: var(--ink);
            font-family: inherit;
        }

        .sj-users-shell .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            padding: 9px 14px;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink);
            transition: background 0.12s ease, border-color 0.12s ease;
        }

        .sj-users-shell .btn:hover {
            background: var(--panel);
        }

        .sj-users-shell .btn-primary {
            background: var(--sj-red);
            border-color: var(--sj-red);
            color: #fff;
        }

        .sj-users-shell .btn-primary:hover {
            background: var(--sj-red-dark);
            border-color: var(--sj-red-dark);
            color: #fff;
        }

        .sj-users-shell .sj-filter-menu,
        .sj-users-shell .sj-action-menu {
            border: 1px solid var(--line);
            border-radius: 9px;
            box-shadow: var(--shadow);
            padding: 5px;
            min-width: 190px;
        }

        .sj-users-shell table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .sj-users-shell thead th {
            text-align: left;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--ink-3);
            padding: 12px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
        }

        .sj-users-shell tbody td {
            padding: 14px 22px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            color: var(--ink);
        }

        .sj-users-shell tbody tr:hover {
            background: #fcfcfd;
        }

        .sj-users-shell .user-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .sj-users-shell .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11.5px;
            font-weight: 700;
            background: var(--sj-red-tint);
            color: var(--sj-red-dark);
            flex-shrink: 0;
        }

        .sj-users-shell .u-name {
            font-weight: 600;
            font-size: 13.5px;
        }

        .sj-users-shell .u-email {
            color: var(--ink-2);
            font-size: 12.5px;
            margin-top: 1px;
        }

        .sj-users-shell .role-pill {

            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;

            background: var(--gray-tint);
            color: var(--ink-2);
        }

        .sj-users-shell .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 100px;
        }

        .sj-users-shell .status::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .sj-users-shell .status.active {
            background: var(--green-tint);
            color: var(--green);
        }

        .sj-users-shell .status.active::before {
            background: var(--green);
        }

        .sj-users-shell .status.inactive {
            background: var(--gray-tint);
            color: var(--ink-2);
        }

        .sj-users-shell .status.inactive::before {
            background: var(--ink-3);
        }

        .sj-users-shell .last-login {
            color: var(--ink-3);
            font-size: 12.5px;
            white-space: nowrap;
        }

        .sj-users-shell .action-cell {
            text-align: right;
        }

        .sj-users-shell .kebab {
            width: 32px;
            height: 32px;
            border-radius: 7px;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink-2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .kebab:hover {
            background: var(--panel);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .sj-users-shell .menu-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0;
            font-size: 13px;
            border-radius: 6px;
            color: var(--ink);
            border: none;
            background: transparent;
            text-align: left;
        }

        .sj-users-shell .menu-item:hover {
            background: var(--panel);
        }

        .sj-users-shell .menu-item.danger {
            color: var(--sj-red);
        }

        .sj-users-shell .menu-divider {
            height: 1px;
            background: var(--line);
            margin: 5px 2px;
            border: 0;
        }

        .sj-users-shell .footer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 22px;
            font-size: 12.5px;
            color: var(--ink-3);
        }

        .sj-users-shell .page-btns {
            display: flex;
            gap: 6px;
        }

        .sj-users-shell .page-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            font-size: 12.5px;
            color: var(--ink-2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .page-btn.current {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
            font-weight: 600;
        }

        .sj-users-shell .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 28, 0.44);
            align-items: center;
            justify-content: center;
            z-index: 100;
            padding: 20px;
        }

        .sj-users-shell .overlay.open {
            display: flex;
        }


        .sj-users-shell .holding-modal .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);


            background: var(--surface);
            border-radius: 14px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.22);
            max-height: 80vh; 
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sj-users-shell .modal::-webkit-scrollbar {
            display: none;
        }

        .sj-users-shell .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px 0;
        }

        .sj-users-shell .title-row {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .sj-users-shell .title-row .icon {
            width: 19px;
            height: 19px;
            color: var(--sj-red);
        }

        .sj-users-shell .modal-head h2 {
            font-size: 1px;
            font-weight: 600;
            margin: 0;
        }

        .sj-users-shell .modal-close {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            border-radius: 7px;
            color: var(--ink-3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sj-users-shell .modal-close:hover {
            background: var(--panel);
            color: var(--ink);
        }

        .sj-users-shell .modal-sub {
            font-size: 10px;
            color: var(--ink-2);
            padding: 6px 22px 18px;
            margin: 0;
        }

        .sj-users-shell .modal-body {
            padding: 0 22px 6px;
            display: flex;
            flex-direction: column;
            /* gap: 14px; */
        }

        .sj-users-shell .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink-2);
            margin-bottom: 6px;
        }

        .sj-users-shell .field input,
        .sj-users-shell .field select {
            width: 100%;
            padding: 9px 11px;
            border-radius: 8px;
            border: 1px solid var(--line-strong);
            font-family: inherit;
            font-size: 13.5px;
            color: var(--ink);
            background: var(--surface);
            outline: none;
        }

        .sj-users-shell .field input:focus,
        .sj-users-shell .field select:focus {
            border-color: var(--sj-red);
        }

        .sj-users-shell .form-error {
            font-size: 12.5px;
            color: var(--sj-red);
            margin: 6px 0 0;
        }

        .sj-users-shell .account-actions {
            display: none;
            flex-direction: column;
            gap: 10px;
            margin-top: 2px;
        }

        .sj-users-shell .account-actions.show {
            display: flex;
        }

        .sj-users-shell .account-action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 12px 14px;
        }

        .sj-users-shell .aa-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .sj-users-shell .aa-sub {
            font-size: 12px;
            color: var(--ink-2);
            margin-top: 2px;
        }

        .sj-users-shell .account-action-row .btn {
            white-space: nowrap;
            padding: 8px 12px;
            font-size: 12.5px;
        }

        .sj-users-shell .is-deactivate {
            color: var(--sj-red);
            border-color: var(--sj-red-tint);
        }

        .sj-users-shell .is-deactivate:hover {
            background: var(--sj-red-tint);
        }

        .sj-users-shell .is-reactivate {
            color: var(--green);
            border-color: var(--green-tint);
        }

        .sj-users-shell .is-reactivate:hover {
            background: var(--green-tint);
        }

        .sj-users-shell .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 20px 22px 22px;
        }

        @media (max-width: 640px) {
            .sj-users-shell .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .sj-users-shell .toolbar-right {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }

            .sj-users-shell .search {
                width: 100%;
            }

            .sj-users-shell .footer-row {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .sj-users-shell thead {
                display: none;
            }

            .sj-users-shell table,
            .sj-users-shell tbody,
            .sj-users-shell tr,
            .sj-users-shell td {
                display: block;
                width: 100%;
            }

            .sj-users-shell tbody tr {
                border-bottom: 1px solid var(--line);
                padding: 12px 4px;
            }

            .sj-users-shell tbody td {
                border-bottom: none;
                padding: 6px 18px;
            }

            .sj-users-shell .action-cell {
                text-align: left;
            }

            .sj-users-shell .pageheader {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .sj-users-shell .sectionnav {
                overflow-x: auto;
            }
        }
    </style>
@endpush


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
