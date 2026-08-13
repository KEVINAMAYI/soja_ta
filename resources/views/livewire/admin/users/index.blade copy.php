<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;

new class extends Component {
    public array $users = [];
    public string $search = '';
    public string $roleFilter = 'All';
    public bool $showUserModal = false;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = '';
    public $filteredUsersProperty;

    public function mount(): void
    {
        $this->users = [
            [
                'id' => 1,
                'name' => 'Grace Wanjiru',
                'email' => 'grace.wanjiru@statpak.co.ke',
                'role' => 'Admin',
                'status' => 'Active',
                'last_login' => '12 Aug, 08:14',
                'initials' => 'GW',
                'color' => '#dfeee5',
                'dot' => '#3abf7b',
            ],
            [
                'id' => 2,
                'name' => 'Brian Otieno',
                'email' => 'brian.otieno@statpak.co.ke',
                'role' => 'Employee',
                'status' => 'Active',
                'last_login' => '12 Aug, 07:52',
                'initials' => 'BO',
                'color' => '#fbe0e7',
                'dot' => '#3abf7b',
            ],
            [
                'id' => 3,
                'name' => 'Faith Achieng',
                'email' => 'faith.achieng@statpak.co.ke',
                'role' => 'Supervisor',
                'status' => 'Active',
                'last_login' => '11 Aug, 17:30',
                'initials' => 'FA',
                'color' => '#f6e8f5',
                'dot' => '#3abf7b',
            ],
            [
                'id' => 4,
                'name' => 'Kevin Mwangi',
                'email' => 'kevin.mwangi@statpak.co.ke',
                'role' => 'Admin',
                'status' => 'Active',
                'last_login' => '12 Aug, 09:01',
                'initials' => 'KM',
                'color' => '#f8dfe3',
                'dot' => '#3abf7b',
            ],
            [
                'id' => 5,
                'name' => 'Lucy Nyambura',
                'email' => 'lucy.nyambura@statpak.co.ke',
                'role' => 'Employee',
                'status' => 'Inactive',
                'last_login' => '30 Jul, 14:22',
                'initials' => 'LN',
                'color' => '#e9f0ff',
                'dot' => '#98a2b3',
            ],
        ];

        $this->filteredUsersProperty = $this->getFilteredUsersProperty();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'in:Admin,Employee,Supervisor'],
        ];
    }

    public function openAddUserModal(): void
    {
        $this->resetForm();
        $this->showUserModal = true;
        $this->dispatch('show-user-modal');
    }

    public function closeUserModal(): void
    {
        $this->showUserModal = false;
        $this->resetForm();
        $this->dispatch('hide-user-modal');
    }

    public function addUser(): void
    {
        $this->validate();

        $this->users = [
            [
                'id' => time(),
                'name' => $this->name,
                'email' => $this->email,
                'role' => $this->role,
                'status' => 'Active',
                'last_login' => 'Just now',
                'initials' => strtoupper(substr($this->name, 0, 2)),
                'color' => '#eaeefc',
                'dot' => '#3abf7b',
            ],
            ...$this->users,
        ];

        $this->closeUserModal();
    }

    public function deleteUser(int $id): void
    {
        $this->users = array_values(array_filter($this->users, fn ($user) => (int) $user['id'] !== $id));
    }

    public function setRoleFilter(string $role): void
    {
        $this->roleFilter = $role;
    }

    public function resetForm(): void
    {
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->role = '';
    }

    #[On('discard-user-modal')]
    public function discardUserModal(): void
    {
        $this->closeUserModal();
    }

    public function getFilteredUsersProperty(): array
    {
        $search = strtolower(trim($this->search));

        return array_values(array_filter($this->users, function ($user) use ($search) {
            $matchesSearch = $search === ''
                || str_contains(strtolower($user['name']), $search)
                || str_contains(strtolower($user['email']), $search);

            $matchesRole = $this->roleFilter === 'All'
                || $user['role'] === $this->roleFilter;

            return $matchesSearch && $matchesRole;
        }));
    }
};
?>


<div class="user-management-wrapper">
    <!-- <div class="user-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="d-flex align-items-center gap-2 fw-bold fs-1 user-title">
            <span>Users</span>
            <span class="count-badge">{{ count($users) }}</span>
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
    </div> -->

    <!-- <div class="users-table-wrap">
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
                @forelse ($this->filteredUsersProperty as $user)
                    <tr>
                        <td>
                            <div class="user-info d-flex align-items-center gap-3">
                                <div class="user-avatar" style="background: {{ $user['color'] }}; color: #1f2937;">
                                    {{ $user['initials'] }}
                                </div>
                                <div class="user-meta">
                                    <div class="user-name">{{ $user['name'] }}</div>
                                    <div class="user-email">{{ $user['email'] }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="role-pill">{{ $user['role'] }}</span>
                        </td>
                        <td>
                            <span class="status-pill {{ strtolower($user['status']) }}">
                                <span class="status-dot"></span>
                                {{ $user['status'] }}
                            </span>
                        </td>
                        <td class="last-login">{{ $user['last_login'] }}</td>
                        <td class="text-end">
                            <div class="dropdown table-actions">
                                <button class="action-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions">
                                    <span></span><span></span><span></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" wire:click="">View profile</button></li>
                                    <li><button type="button" class="dropdown-item" wire:click="">Edit user</button></li>
                                    <li><button type="button" class="dropdown-item text-danger" wire:click="deleteUser({{ $user['id'] }})">Delete user</button></li>
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
    </div> -->


    <!-- User modal -->
    <!-- <div class="modal fade {{ $showUserModal ? 'show d-block' : '' }}" id="userModal" tabindex="-1" role="dialog" aria-labelledby="user-modal-label" aria-hidden="{{ $showUserModal ? 'false' : 'true' }}" style="{{ $showUserModal ? 'display: block; background: rgba(15, 23, 42, 0.24);' : 'display: none;' }}">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 900px;">
            <div class="modal-content user-modal-content shadow-sm border-0">
                <div class="modal-header border-0 align-items-center px-4 pt-4 pb-2">
                    <div class="d-flex align-items-center gap-3 modal-title-wrap">
                        <div class="modal-user-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 8a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Zm-9 10a5.5 5.5 0 0 1 11 0v1h-11v-1Zm2 0v-.5A3.5 3.5 0 0 1 12 14.5a3.5 3.5 0 0 1 3.5 3.5v.5H8.5Zm12.5-9h-1.5v2h-2v1.5h2v2H21v-2h2V13h-2v-2h-1.5V10h1.5V8Z"/></svg>
                        </div>
                        <h2 id="user-modal-label" class="modal-title mb-0">Add user</h2>
                    </div>
                    <button type="button" class="btn-close" aria-label="Close" wire:click="closeUserModal"></button>
                </div>

                <div class="modal-body px-4 pb-4">
                    <p class="modal-subtitle">Create an account and assign access.</p>
                    <form wire:submit.prevent="addUser" class="user-form">
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

                        <div class="d-flex justify-content-end gap-3 pt-2">
                            <button type="button" class="btn btn-outline-secondary user-cancel-btn" wire:click="closeUserModal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger user-submit-btn">
                                Add user
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div> -->
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
            font-size: 1.05rem;
            font-weight: 700;
            color: #111827;
        }

        .user-email {
            font-size: 0.96rem;
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
