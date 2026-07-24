<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role as SpatieRole;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $name, $email, $roleId, $editId = null;
    public $roles = [];
    public $departments = [];
    public $shifts = [];

    public $convertId = null;
    public $convertDepartmentId, $convertShiftId;

    public int $totalUsers = 0;
    public int $activeUsers = 0;
    public int $inactiveUsers = 0;

    public function mount(): void
    {
        if (!auth()->user()?->can('view-users')) {
            abort(403, 'Unauthorized.');
        }

        $org = auth()->user()->employee->organization;

        $this->roles = SpatieRole::where('name', '!=', 'super-admin')
            ->where('organization_id', $org->id)
            ->pluck('name', 'id');

        $this->departments = $org->departments;
        $this->shifts = $org->shifts;

        $this->loadStats();
    }

    public function loadStats(): void
    {
        $orgId = auth()->user()->employee->organization_id;

        $base = Employee::withSystemUsers()
            ->where('organization_id', $orgId)
            ->where('is_system_user', true);

        $this->totalUsers = (clone $base)->count();
        $this->activeUsers = (clone $base)->where('active', true)->count();
        $this->inactiveUsers = (clone $base)->where('active', false)->count();
    }

    public function rules(): array
    {
        $userId = $this->editId
            ? Employee::withSystemUsers()->find($this->editId)?->user_id
            : null;

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $userId,
            'roleId' => 'required|exists:roles,id',
        ];
    }

    public function store(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $org = auth()->user()->employee->organization;
            $role = SpatieRole::where('organization_id', $org->id)
                ->where('name', '!=', 'super-admin')
                ->findOrFail($this->roleId);

            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make(Str::random(40)),
            ]);

            Employee::create([
                'organization_id' => $org->id,
                'department_id' => null,
                'shift_id' => null,
                'user_id' => $user->id,
                'name' => $this->name,
                'email' => $this->email,
                'is_student' => false,
                'is_system_user' => true,
            ]);

            $user->assignRole($role->name);

            DB::commit();

            $resetLinkSent = true;
            try {
                Password::broker()->sendResetLink(
                    ['email' => $user->email],
                    fn($user, $token) => $user->sendPasswordResetNotificationWithOrganization($token, $org)
                );
            } catch (\Throwable $mailError) {
                $resetLinkSent = false;
                Log::error('Error sending password setup link to new system user: ' . $mailError->getMessage());
            }

            $this->resetForm();
            $this->dispatch('hide-users-modal');

            LivewireAlert::title('Awesome!')
                ->text($resetLinkSent
                    ? 'System user added successfully. A password setup link has been emailed to them.'
                    : 'System user added successfully, but the password setup email could not be sent. Use "Reset Password" to retry.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');
            $this->loadStats();

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error adding system user: ' . $th->getMessage());

            LivewireAlert::text('Failed to add system user.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('edit-user')]
    public function editUser($id): void
    {
        $employee = Employee::withSystemUsers()->with('user.roles')->findOrFail($id);

        $this->editId = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email;

        $currentRoleName = $employee->user?->roles->first()?->name;
        $this->roleId = SpatieRole::where('organization_id', auth()->user()->employee->organization_id)
            ->where('name', $currentRoleName)
            ->value('id');

        $this->dispatch('show-users-modal');
    }

    public function updateUser(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $employee = Employee::withSystemUsers()->findOrFail($this->editId);
            $role = SpatieRole::where('organization_id', auth()->user()->employee->organization_id)
                ->where('name', '!=', 'super-admin')
                ->findOrFail($this->roleId);

            $employee->user->update(['name' => $this->name, 'email' => $this->email]);
            $employee->update(['name' => $this->name, 'email' => $this->email]);
            $employee->user->syncRoles([$role->name]);

            DB::commit();

            $this->resetForm();
            $this->dispatch('hide-users-modal');

            LivewireAlert::title('Awesome!')
                ->text('System user updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error updating system user: ' . $th->getMessage());

            LivewireAlert::text('Failed to update system user.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('prompt-convert-to-employee')]
    public function promptConvertToEmployee($id): void
    {
        if (!auth()->user()?->can('convert-to-system-user')) {
            abort(403, 'Unauthorized.');
        }

        $employee = Employee::withSystemUsers()->findOrFail($id);
        $this->convertId = $employee->id;
        $this->convertDepartmentId = $employee->department_id;
        $this->convertShiftId = $employee->shift_id;

        $this->dispatch('show-convert-modal');
    }

    public function convertToEmployee(): void
    {
        if (!auth()->user()?->can('convert-to-system-user')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate([
            'convertDepartmentId' => 'required|exists:departments,id',
            'convertShiftId' => 'required|exists:shifts,id',
        ]);

        $employee = Employee::withSystemUsers()->findOrFail($this->convertId);

        Employee::withSystemUsers()->where('id', $employee->id)->update([
            'department_id' => $this->convertDepartmentId,
            'shift_id' => $this->convertShiftId,
            'is_system_user' => false,
        ]);

        $this->reset(['convertId', 'convertDepartmentId', 'convertShiftId']);
        $this->dispatch('hide-convert-modal');

        LivewireAlert::title('Success!')
            ->text($employee->name . ' is now a tracked employee again and has moved to the Employees page.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

        $this->dispatch('refreshDatatable');
        $this->loadStats();
    }

    #[On('toggle-user-active')]
    public function toggleActive($id): void
    {
        if (!auth()->user()?->can('deactivate-users')) {
            abort(403, 'Unauthorized.');
        }

        $employee = Employee::withSystemUsers()->findOrFail($id);
        Employee::withSystemUsers()->where('id', $employee->id)->update(['active' => !$employee->active]);

        LivewireAlert::title('Done!')
            ->text('System user ' . ($employee->active ? 'deactivated' : 'activated') . ' successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

        $this->dispatch('refreshDatatable');
        $this->loadStats();
    }

    #[On('reset-user-password')]
    public function resetPassword($id): void
    {
        if (!auth()->user()?->can('edit-users')) {
            abort(403, 'Unauthorized.');
        }

        $employee = Employee::withSystemUsers()->with('user')->findOrFail($id);
        $org = auth()->user()->employee->organization;

        try {
            Password::broker()->sendResetLink(
                ['email' => $employee->user->email],
                fn($user, $token) => $user->sendPasswordResetNotificationWithOrganization($token, $org)
            );

            LivewireAlert::title('Sent!')
                ->text('Password reset link emailed to ' . $employee->user->email)
                ->success()
                ->toast()
                ->position('top-end')
                ->show();
        } catch (\Throwable $th) {
            Log::error('Error sending password reset link: ' . $th->getMessage());
            LivewireAlert::title('Error!')
                ->text('Failed to send password reset link.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('discard-user-modal')]
    public function discardUserModal(): void
    {
        $this->dispatch('hide-users-modal');
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'email', 'roleId', 'editId']);
    }

}; ?>

<div class="row">
    <div class="col-12">

        {{-- Page header --}}
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="page-header-icon">
                    <iconify-icon icon="mdi:account-cog-outline"></iconify-icon>
                </div>
                <div>
                    <h4 class="mb-0 fw-bold">System Users</h4>
                    <p class="text-muted mb-0" style="font-size:0.85rem;">
                        Manager &amp; system accounts that can access and manage the system, without being
                        tracked for attendance.
                    </p>
                </div>
            </div>

            @can('add-users')
                <a href="javascript:void(0)" wire:click="$dispatch('show-users-modal');"
                   wire:click="resetForm()" class="btn btn-primary d-flex align-items-center px-3 py-2">
                    <iconify-icon icon="mdi:account-plus-outline" class="me-1 fs-5"></iconify-icon> Add System User
                </a>
            @endcan
        </div>

        {{-- Stat cards --}}
        <div class="row g-3 mb-4 summary-stats-row">
            <div class="col-lg-4 col-md-6 col-12">
                <div class="summary-card">
                    <div class="summary-card-icon" style="background:#ede9fe; color:#7c3aed;">
                        <iconify-icon icon="mdi:account-group"></iconify-icon>
                    </div>
                    <p class="summary-card-title">Total System Users</p>
                    <div class="summary-card-value">{{ $totalUsers }}</div>
                    <p class="summary-card-subtitle">All system/manager accounts</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-12">
                <div class="summary-card">
                    <div class="summary-card-icon" style="background:#dcfce7; color:#16a34a;">
                        <iconify-icon icon="mdi:account-check"></iconify-icon>
                    </div>
                    <p class="summary-card-title">Active</p>
                    <div class="summary-card-value">{{ $activeUsers }}</div>
                    <p class="summary-card-subtitle">Currently able to log in</p>
                    <div class="summary-card-bar">
                        <div class="summary-card-bar-fill"
                             style="width:{{ $totalUsers > 0 ? ($activeUsers/$totalUsers)*100 : 0 }}%; background:#22c55e;"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-12">
                <div class="summary-card">
                    <div class="summary-card-icon" style="background:#fee2e2; color:#dc2626;">
                        <iconify-icon icon="mdi:account-cancel"></iconify-icon>
                    </div>
                    <p class="summary-card-title">Inactive</p>
                    <div class="summary-card-value">{{ $inactiveUsers }}</div>
                    <p class="summary-card-subtitle">Deactivated accounts</p>
                </div>
            </div>
        </div>

        <div class="widget-content searchable-container list">
            <div class="card card-body">

                {{-- Livewire Table --}}
                <livewire:user-table theme="bootstrap-4"/>

            </div>

            <!-- Modal -->
            <div class="modal fade" id="usersModal" tabindex="-1" role="dialog" aria-labelledby="usersModalTitle"
                 aria-hidden="true" wire:ignore.self>
                <div class="modal-dialog modal-md modal-dialog-centered" role="document">
                    <div class="modal-content users-modal-content">
                        <div class="modal-header d-flex align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <div class="modal-header-icon">
                                    <iconify-icon
                                        icon="{{ $editId ? 'mdi:account-edit-outline' : 'mdi:account-plus-outline' }}"></iconify-icon>
                                </div>
                                <h5 class="modal-title mb-0">{{ $editId ? 'Update System User' : 'Add System User' }}</h5>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                        </div>
                        <form wire:submit.prevent="{{ $editId ? 'updateUser' : 'store' }}">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Full name</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><iconify-icon
                                                icon="mdi:account-outline"></iconify-icon></span>
                                        <input type="text" wire:model="name" class="form-control"
                                               placeholder="e.g. Jane Doe"/>
                                    </div>
                                    @error('name')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><iconify-icon
                                                icon="mdi:email-outline"></iconify-icon></span>
                                        <input type="email" wire:model="email" class="form-control"
                                               placeholder="name@company.com"/>
                                    </div>
                                    @error('email')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Role</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><iconify-icon
                                                icon="mdi:shield-account-outline"></iconify-icon></span>
                                        <select wire:model="roleId" class="form-control">
                                            <option value="">Select Role</option>
                                            @foreach ($roles as $id => $roleName)
                                                <option value="{{ $id }}">{{ ucwords(str_replace(['-', '_'], ' ', $roleName)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @error('roleId')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                                @unless($editId)
                                    <div class="users-info-note">
                                        <iconify-icon icon="mdi:information-outline"></iconify-icon>
                                        <span>A password setup link will be emailed to this address. This account
                                            won't appear in attendance, timesheets, or reports.</span>
                                    </div>
                                @endunless
                            </div>
                            <div class="modal-footer">
                                <div class="d-flex gap-2 m-0">
                                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-1">
                                        <iconify-icon icon="mdi:check"></iconify-icon>
                                        {{ $editId ? 'Save Changes' : 'Add System User' }}
                                    </button>
                                    <button type="button" wire:click="$dispatch('discard-user-modal')"
                                            class="btn btn-outline-secondary"
                                            data-bs-dismiss="modal">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Convert to Employee Modal -->
            <div class="modal fade" id="convertModal" tabindex="-1" role="dialog" aria-labelledby="convertModalTitle"
                 aria-hidden="true" wire:ignore.self>
                <div class="modal-dialog modal-md modal-dialog-centered" role="document">
                    <div class="modal-content users-modal-content">
                        <div class="modal-header d-flex align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <div class="modal-header-icon">
                                    <iconify-icon icon="mdi:arrow-u-left-top"></iconify-icon>
                                </div>
                                <h5 class="modal-title mb-0">Convert to Tracked Employee</h5>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                        </div>
                        <form wire:submit.prevent="convertToEmployee">
                            <div class="modal-body">
                                <div class="users-info-note">
                                    <iconify-icon icon="mdi:information-outline"></iconify-icon>
                                    <span>Pick a department and shift so this account can be tracked for attendance
                                        going forward. It will move from System Users to the Employees page.</span>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Department</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><iconify-icon
                                                icon="mdi:office-building-outline"></iconify-icon></span>
                                        <select wire:model="convertDepartmentId" class="form-control">
                                            <option value="">Select Department</option>
                                            @foreach ($departments as $department)
                                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @error('convertDepartmentId')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Shift</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><iconify-icon
                                                icon="mdi:clock-outline"></iconify-icon></span>
                                        <select wire:model="convertShiftId" class="form-control">
                                            <option value="">Select Shift</option>
                                            @foreach ($shifts as $shift)
                                                <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @error('convertShiftId')
                                    <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>
                            <div class="modal-footer">
                                <div class="d-flex gap-2 m-0">
                                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-1">
                                        <iconify-icon icon="mdi:check"></iconify-icon> Convert
                                    </button>
                                    <button type="button" data-bs-dismiss="modal" class="btn btn-outline-secondary">
                                        Cancel
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

@push('styles')
    <style>
        .page-header-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: #ede9fe;
            color: #7c3aed;
            flex-shrink: 0;
        }

        .summary-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.4rem 1.5rem 1.2rem;
            height: 100%;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
        }

        .summary-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 0.9rem;
        }

        .summary-card-title {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.35rem;
        }

        .summary-card-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }

        .summary-card-subtitle {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }

        .summary-card-bar {
            height: 4px;
            border-radius: 99px;
            background: #f1f5f9;
            margin-top: 0.85rem;
            overflow: hidden;
        }

        .summary-card-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.6s ease;
        }

        .summary-stats-row {
            margin-bottom: 2rem;
        }

        .users-modal-content .modal-header {
            justify-content: space-between;
        }

        .modal-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            background: #ede9fe;
            color: #7c3aed;
            flex-shrink: 0;
        }

        .users-info-note {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .users-info-note iconify-icon {
            font-size: 16px;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .user-avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            background: #ede9fe;
            color: #7c3aed;
            flex-shrink: 0;
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.addEventListener('show-users-modal', () => {
            new bootstrap.Modal(document.getElementById('usersModal')).show();
        });

        window.addEventListener('hide-users-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('usersModal'))?.hide();
        });

        window.addEventListener('show-convert-modal', () => {
            new bootstrap.Modal(document.getElementById('convertModal')).show();
        });

        window.addEventListener('hide-convert-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('convertModal'))?.hide();
        });
    </script>
@endpush
