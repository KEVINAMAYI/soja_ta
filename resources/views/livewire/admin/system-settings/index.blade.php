<?php

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Services\CheckInApprovalSettings;
use App\Services\LeaveApprovalSettings;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;
    public string $activeTab = 'qr_code'; // default
    public string $tabTitle = '';
    public string $tabIcon = '';
    public array $breadcrumbItems = [];
    public array $availableRoles = [];


    // ── Check-in Approval tab state ──────────────────────────────────────
    public array $approval = [];
    public array $availableDepartments = [];

    // ── Leave Approval tab state ─────────────────────────────────────────
    public array $leaveApproval = [];
    public array $availableUsers = [];
    public string $leaveApprovalScope = 'organization'; // 'organization' | 'department'
    public $leaveApprovalDepartmentId = '';
    public bool $leaveApprovalHasOverride = false;
    public array $leaveApprovalOverriddenDepartmentIds = [];

    // ── Leave Types tab state ─────────────────────────────────────────────
    public $ltName;
    public $ltCode;
    public $ltIcon;
    public $ltAnnualEntitlementDays;
    public $ltIsActive = true;
    public $ltEditId;

    public function mount()
    {
        $orgId = auth()->user()->employee?->organization_id;
        $org = Organization::find($orgId);

        $this->settings = $org->settings->mapWithKeys(function ($item) {
            $value = $item->type === 'json'
                ? (is_array($item->value) ? $item->value : json_decode($item->value, true))
                : $item->value;
            return [$item->key => $value];
        })->toArray();

        $this->settings['generate_employee_qr_on_create'] = isset($this->settings['generate_employee_qr_on_create'])
            ? (bool)$this->settings['generate_employee_qr_on_create']
            : false;

        // ── Load check-in approval settings ──
        $this->approval = CheckInApprovalSettings::get($orgId);

        // ── Load leave approval settings ──
        $this->leaveApproval = LeaveApprovalSettings::get($orgId);
        $this->leaveApprovalOverriddenDepartmentIds = LeaveApprovalSettings::departmentIdsWithOverride($orgId);

        $this->availableDepartments = Department::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($d) => ['id' => $d->id, 'name' => $d->name])
            ->toArray();

        $this->changeSystemSettingsBreadcrumb();

        $this->availableRoles = \Spatie\Permission\Models\Role::where('organization_id', $orgId)
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        $this->availableUsers = User::whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name])
            ->toArray();
    }

    #[On('systemSettingsTabChanged')]
    public function systemSettingsTabChanged($tabId)
    {
        $this->activeTab = $tabId;
        $this->changeSystemSettingsBreadcrumb();
    }

    public function storeSettings()
    {
        DB::beginTransaction();

        try {
            $orgId = auth()->user()->employee?->organization_id;

            foreach ($this->settings as $key => $value) {
                $setting = OrganizationSetting::firstOrNew([
                    'organization_id' => $orgId,
                    'key' => $key
                ]);

                $setting->type = ($key === 'generate_employee_qr_on_create') ? 'boolean' : $setting->type ?? 'string';

                $setting->value = $value;
                $setting->save();
            }

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Settings updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while updating settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    // ── Check-in Approval tab actions ────────────────────────────────────

    public function addEmail(int $windowIndex): void
    {
        $this->approval['windows'][$windowIndex]['notify_email_addresses'][] = '';
    }

    public function removeEmail(int $windowIndex, int $emailIndex): void
    {
        unset($this->approval['windows'][$windowIndex]['notify_email_addresses'][$emailIndex]);
        $this->approval['windows'][$windowIndex]['notify_email_addresses'] =
            array_values($this->approval['windows'][$windowIndex]['notify_email_addresses']);
    }

    public function addPhone(int $windowIndex): void
    {
        $this->approval['windows'][$windowIndex]['notify_sms_numbers'][] = '';
    }

    public function removePhone(int $windowIndex, int $phoneIndex): void
    {
        unset($this->approval['windows'][$windowIndex]['notify_sms_numbers'][$phoneIndex]);
        $this->approval['windows'][$windowIndex]['notify_sms_numbers'] =
            array_values($this->approval['windows'][$windowIndex]['notify_sms_numbers']);
    }

    public function saveApprovalSettings(): void
    {
        $this->validate([
            'approval.enabled'                          => 'boolean',
            'approval.auto_reject_after_minutes'        => 'nullable|integer|min:1|max:1440',
            'approval.department_ids'                   => 'array',
            'approval.windows'                          => 'array|size:3',
            'approval.windows.*.enabled'                => 'boolean',
            'approval.windows.*.min_minutes_late'       => 'required|integer|min:0|max:1440',
            'approval.windows.*.approver_role'          => 'nullable|string|max:100',
            'approval.windows.*.timeout_minutes'        => 'required|integer|min:1|max:1440',
            'approval.windows.*.on_timeout'             => 'required|in:approve,reject,escalate',
            'approval.windows.*.notify_email'           => 'boolean',
            'approval.windows.*.notify_email_addresses.*' => 'nullable|email',
            'approval.windows.*.notify_sms'             => 'boolean',
            'approval.windows.*.notify_sms_numbers.*'   => 'nullable|string|max:20',
        ]);

        $orgId = auth()->user()->employee?->organization_id;

        // Window 3 cannot escalate further — force a terminal action.
        if (($this->approval['windows'][2]['on_timeout'] ?? null) === 'escalate') {
            $this->approval['windows'][2]['on_timeout'] = 'reject';
        }

        CheckInApprovalSettings::save($orgId, $this->approval);

        LivewireAlert::title('Saved!')
            ->text('Check-in approval settings updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    // ── Leave Approval tab actions ───────────────────────────────────────

    public function addLeaveApprovalEmail(int $levelIndex): void
    {
        $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'][] = '';
    }

    public function removeLeaveApprovalEmail(int $levelIndex, int $emailIndex): void
    {
        unset($this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'][$emailIndex]);
        $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'] =
            array_values($this->leaveApproval['levels'][$levelIndex]['notify_email_addresses']);
    }

    /**
     * Switch between editing the organization-wide default and a single
     * department's override — only one is ever shown/edited at a time.
     */
    public function setLeaveApprovalScope(string $scope): void
    {
        $this->leaveApprovalScope = $scope;
        $this->refreshLeaveApprovalForm();
    }

    public function setLeaveApprovalDepartment($departmentId): void
    {
        $this->leaveApprovalDepartmentId = $departmentId;
        $this->refreshLeaveApprovalForm();
    }

    private function refreshLeaveApprovalForm(): void
    {
        $orgId = auth()->user()->employee?->organization_id;

        if ($this->leaveApprovalScope === 'department' && $this->leaveApprovalDepartmentId) {
            $deptId = (int) $this->leaveApprovalDepartmentId;
            $this->leaveApprovalHasOverride = LeaveApprovalSettings::hasDepartmentOverride($orgId, $deptId);
            $this->leaveApproval = LeaveApprovalSettings::get($orgId, $deptId);
        } else {
            $this->leaveApprovalHasOverride = false;
            $this->leaveApproval = LeaveApprovalSettings::get($orgId);
        }
    }

    public function saveLeaveApprovalSettings(): void
    {
        $this->validate([
            'leaveApproval.enabled'                              => 'boolean',
            'leaveApproval.levels'                               => 'array|size:3',
            'leaveApproval.levels.*.enabled'                     => 'boolean',
            'leaveApproval.levels.*.approver_type'               => 'required|in:role,user',
            'leaveApproval.levels.*.approver_role'               => 'nullable|string|max:100',
            'leaveApproval.levels.*.approver_user_id'            => 'nullable|integer|exists:users,id',
            'leaveApproval.levels.*.notify_email'                => 'boolean',
            'leaveApproval.levels.*.notify_email_addresses.*'    => 'nullable|email',
        ]);

        $orgId = auth()->user()->employee?->organization_id;

        if ($this->leaveApprovalScope === 'department') {
            if (!$this->leaveApprovalDepartmentId) {
                LivewireAlert::title('Select a department')
                    ->text('Choose a department before saving a department-specific chain.')
                    ->warning()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            LeaveApprovalSettings::save($orgId, $this->leaveApproval, (int) $this->leaveApprovalDepartmentId);
            $this->leaveApprovalHasOverride = true;
            $this->leaveApprovalOverriddenDepartmentIds = LeaveApprovalSettings::departmentIdsWithOverride($orgId);
        } else {
            LeaveApprovalSettings::save($orgId, $this->leaveApproval);
        }

        LivewireAlert::title('Saved!')
            ->text('Leave approval settings updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    /**
     * Remove the selected department's override so it falls back to the
     * organization-wide default again.
     */
    public function resetLeaveApprovalDepartmentOverride(): void
    {
        $orgId = auth()->user()->employee?->organization_id;
        $deptId = (int) $this->leaveApprovalDepartmentId;

        LeaveApprovalSettings::resetDepartmentOverride($orgId, $deptId);

        $this->leaveApprovalHasOverride = false;
        $this->leaveApprovalOverriddenDepartmentIds = LeaveApprovalSettings::departmentIdsWithOverride($orgId);
        $this->leaveApproval = LeaveApprovalSettings::get($orgId, $deptId);

        LivewireAlert::title('Reset!')
            ->text('This department now uses the organization-wide default.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    // ── Leave Types tab actions ──────────────────────────────────────────

    public function getLeaveTypesListProperty()
    {
        $orgId = auth()->user()->employee?->organization_id;

        return LeaveType::where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
    }

    public function leaveTypeRules(): array
    {
        $orgId = auth()->user()->employee?->organization_id;

        return [
            'ltName' => 'required|string|max:255',
            'ltCode' => 'required|string|max:100|alpha_dash|unique:leave_types,code,' . $this->ltEditId . ',id,organization_id,' . $orgId,
            'ltIcon' => 'nullable|string|max:10',
            'ltAnnualEntitlementDays' => 'nullable|numeric|min:0|max:999',
            'ltIsActive' => 'boolean',
        ];
    }

    public function createLeaveType(): void
    {
        $this->validate($this->leaveTypeRules());

        try {
            DB::beginTransaction();

            $orgId = auth()->user()->employee?->organization_id;

            LeaveType::create([
                'organization_id' => $orgId,
                'name' => $this->ltName,
                'code' => $this->ltCode,
                'icon' => $this->ltIcon,
                'annual_entitlement_days' => $this->ltAnnualEntitlementDays,
                'is_active' => $this->ltIsActive,
            ]);

            DB::commit();

            $this->dispatch('hide-leave-type-modal');

            LivewireAlert::title('Awesome!')
                ->text('Leave type added successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetLeaveTypeForm();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Failed to add leave type.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('edit-leave-type')]
    public function editLeaveTypeHandler($id): void
    {
        $type = LeaveType::findOrFail($id);
        $this->ltEditId = $id;
        $this->ltName = $type->name;
        $this->ltCode = $type->code;
        $this->ltIcon = $type->icon;
        $this->ltAnnualEntitlementDays = $type->annual_entitlement_days;
        $this->ltIsActive = $type->is_active;

        $this->dispatch('show-leave-type-modal');
    }

    public function updateLeaveType(): void
    {
        $this->validate($this->leaveTypeRules());

        try {
            DB::beginTransaction();

            LeaveType::findOrFail($this->ltEditId)->update([
                'name' => $this->ltName,
                'code' => $this->ltCode,
                'icon' => $this->ltIcon,
                'annual_entitlement_days' => $this->ltAnnualEntitlementDays,
                'is_active' => $this->ltIsActive,
            ]);

            DB::commit();

            $this->dispatch('hide-leave-type-modal');

            LivewireAlert::title('Awesome!')
                ->text('Leave type updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetLeaveTypeForm();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Failed to update leave type.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('delete-leave-type')]
    public function deleteLeaveTypeHandler($id): void
    {
        try {
            DB::beginTransaction();

            LeaveType::findOrFail($id)->delete();

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Leave type deleted successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetLeaveTypeForm();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Failed to delete leave type.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('discard-leave-type-modal')]
    public function discardLeaveTypeModal(): void
    {
        $this->dispatch('hide-leave-type-modal');
        $this->resetLeaveTypeForm();
    }

    public function resetLeaveTypeForm(): void
    {
        $this->reset(['ltName', 'ltCode', 'ltIcon', 'ltAnnualEntitlementDays', 'ltEditId']);
        $this->ltIsActive = true;
    }

    public function changeSystemSettingsBreadcrumb()
    {
        switch ($this->activeTab) {
            case 'qr_code':
                $this->tabTitle = 'QR Code Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:qrcode-scan" class="fs-5"></iconify-icon>';
                break;

            case 'shift_management':
                $this->tabTitle = 'Shift Management';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock-outline" class="fs-5"></iconify-icon>';
                break;

            case 'checkin_approval':
                $this->tabTitle = 'Check-in Approval';
                $this->tabIcon = '<iconify-icon icon="mdi:shield-check-outline" class="fs-5"></iconify-icon>';
                break;

            case 'leave_approval':
                $this->tabTitle = 'Leave Approval';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-check-outline" class="fs-5"></iconify-icon>';
                break;

            case 'leave_types':
                $this->tabTitle = 'Leave Types';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-cog-outline" class="fs-5"></iconify-icon>';
                break;

            default:
                $this->tabTitle = 'Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:cog-outline" class="fs-5"></iconify-icon>';
                break;
        }

        $this->breadcrumbItems = [
            [
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
            ],
            [
                'label' => 'System Settings',
                'url' => '#',
                'icon' => '<iconify-icon icon="mdi:cog-outline" class="fs-5"></iconify-icon>',
            ],
            [
                'label' => $this->tabTitle,
                'icon' => $this->tabIcon,
            ],
        ];
    }

}; ?>

<div class="container-fluid">

    <livewire:admin.system-settings.bread-crumb
        :title="$tabTitle"
        :items="$breadcrumbItems"
    />

    <div class="card">
        <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'qr_code' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-qr-code-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-qr-code"
                    type="button"
                    role="tab"
                    aria-controls="tab-qr-code"
                    aria-selected="false">
                    <i class="ti ti-qrcode me-2 fs-6"></i>
                    <span class="d-none d-md-block">QR Code</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'shift_management' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-shift-management-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-shift-management"
                    type="button"
                    role="tab"
                    aria-controls="tab-shift-management"
                    aria-selected="false">
                    <i class="ti ti-calendar-time me-2 fs-6"></i>
                    <span class="d-none d-md-block">{{ auth()->user()->employee?->organization?->is_student_record ? 'Session' : 'Shift' }} Management</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'checkin_approval' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-checkin-approval-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-checkin-approval"
                    type="button"
                    role="tab"
                    aria-controls="tab-checkin-approval"
                    aria-selected="false">
                    <i class="ti ti-shield-check me-2 fs-6"></i>
                    <span class="d-none d-md-block">Check-in Approval</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'leave_approval' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-leave-approval-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-leave-approval"
                    type="button"
                    role="tab"
                    aria-controls="tab-leave-approval"
                    aria-selected="false">
                    <i class="ti ti-calendar-check me-2 fs-6"></i>
                    <span class="d-none d-md-block">Leave Approval</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'leave_types' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-leave-types-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-leave-types"
                    type="button"
                    role="tab"
                    aria-controls="tab-leave-types"
                    aria-selected="false">
                    <i class="ti ti-calendar-cog me-2 fs-6"></i>
                    <span class="d-none d-md-block">Leave Types</span>
                </button>
            </li>

        </ul>

        <div class="card-body">
            <div class="tab-content" id="pills-tabContent">

                {{-- QR Code Settings Tab --}}
                <div class="tab-pane fade {{ $activeTab === 'qr_code' ? 'show active' : '' }}" id="tab-qr-code">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-4">QR Code Settings</h4>

                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="generateQrOnCreate"
                                               wire:model.defer="settings.generate_employee_qr_on_create">
                                        <label class="form-check-label" for="generateQrOnCreate">
                                            Generate QR code when adding a new employee
                                        </label>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                        <button wire:click="storeSettings" class="btn btn-primary">Save</button>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Shift Management Tab --}}
                <div class="tab-pane fade {{ $activeTab === 'shift_management' ? 'show active' : '' }}"
                     id="tab-shift-management">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <livewire:admin.shifts.create/>
                        </div>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════════
                     Check-in Approval Tab
                ════════════════════════════════════════════════════════ --}}
                <div class="tab-pane fade {{ $activeTab === 'checkin_approval' ? 'show active' : '' }}"
                     id="tab-checkin-approval">

                    <div class="row justify-content-center">
                        <div class="col-lg-12">

                            {{-- Approval system master toggle --}}
                            <div class="card border shadow-none mb-4">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:shield-check-outline"
                                                          class="fs-4 text-primary"></iconify-icon>
                                            Approval system
                                        </h5>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="approvalEnabled"
                                                   wire:model="approval.enabled"
                                                   style="width:3em;height:1.5em;">
                                            <label class="form-check-label fw-semibold ms-2" for="approvalEnabled">
                                                Enabled for this tenant
                                            </label>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label fw-semibold text-uppercase small text-muted">
                                                Policy Scope — Departments
                                            </label>

                                            <div x-data="{ open: false }" class="position-relative">
                                                <button type="button"
                                                        @click="open = !open"
                                                        class="form-control text-start d-flex align-items-center justify-content-between"
                                                        style="cursor:pointer;">
                                                    <span class="text-muted small">
                                                        @if(empty($approval['department_ids']))
                                                            All departments
                                                        @else
                                                            {{ count($approval['department_ids']) }} department(s)
                                                            selected
                                                        @endif
                                                    </span>
                                                    <iconify-icon icon="mdi:chevron-down"></iconify-icon>
                                                </button>

                                                <div x-show="open"
                                                     @click.outside="open = false"
                                                     x-cloak
                                                     class="position-absolute w-100 bg-white border rounded-3 shadow-sm mt-1"
                                                     style="z-index:999;max-height:220px;overflow-y:auto;">

                                                    @if(empty($availableDepartments))
                                                        <div class="p-3 text-muted small">No departments found.</div>
                                                    @else
                                                        <div class="p-2">
                                                            {{-- Clear all --}}
                                                            <button type="button"
                                                                    wire:click="$set('approval.department_ids', [])"
                                                                    class="btn btn-sm btn-link text-danger p-0 mb-2 d-block">
                                                                Clear all (apply to all departments)
                                                            </button>

                                                            @foreach($availableDepartments as $dept)
                                                                <label
                                                                    class="d-flex align-items-center gap-2 px-2 py-1 rounded hover-bg"
                                                                    style="cursor:pointer;font-size:.875rem;">
                                                                    <input type="checkbox"
                                                                           value="{{ $dept['id'] }}"
                                                                           wire:model.live="approval.department_ids"
                                                                           class="form-check-input m-0">
                                                                    {{ $dept['name'] }}
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            <small class="text-muted d-block mt-1">
                                                @if(empty($approval['department_ids']))
                                                    No departments selected — policy applies to <strong>all
                                                        departments</strong>.
                                                @else
                                                    {{ count($approval['department_ids']) }} department(s) in scope.
                                                @endif
                                            </small>
                                        </div>
                                    </div>
                                    <div class="mt-3 mb-0 d-flex align-items-start gap-2 small rounded-3 p-3"
                                         style="background:#fff8e1;border:1px solid #fde68a;">
                                        <iconify-icon icon="mdi:clock-alert-outline"
                                                      class="fs-5 flex-shrink-0"
                                                      style="color:#f59e0b;margin-top:1px;"></iconify-icon>
                                        <span style="color:#92400e;line-height:1.6;">
        Clock-ins beyond the <strong>shift's grace period</strong> automatically
        create an approval request and notify approvers.
    </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Approval windows --}}
                            <div class="card border shadow-none mb-4">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:clock-outline" class="fs-4 text-primary"></iconify-icon>
                                            Approval windows
                                        </h5>
                                        <span class="text-muted small">Entry window is chosen by how late the employee is</span>
                                    </div>

                                    {{-- ── Routing explainer ── --}}
                                    <div class="alert alert-light border mb-4 py-2 px-3 d-flex gap-2 align-items-start small">
                                        <iconify-icon icon="mdi:information-outline" class="fs-5 flex-shrink-0 text-primary mt-1"></iconify-icon>
                                        <span>
                When someone checks in late, the system picks the <strong>highest-threshold enabled window</strong>
                whose <em>Min. minutes late</em> is still ≤ their actual lateness.
                If lateness reaches the <strong>Auto-reject</strong> ceiling, the request is rejected immediately
                with no window opened.
            </span>
                                    </div>

                                    {{-- ── Auto-reject ceiling ── --}}
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold text-uppercase small text-muted d-flex align-items-center gap-1">
                                                <iconify-icon icon="mdi:cancel" class="text-danger"></iconify-icon>
                                                Auto-reject if late by (minutes)
                                            </label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" min="1" max="1440"
                                                       class="form-control"
                                                       placeholder="e.g. 60 — leave blank to disable"
                                                       wire:model="approval.auto_reject_after_minutes">
                                                <span class="input-group-text">min</span>
                                            </div>
                                            <small class="text-muted">
                                                Leave blank to disable. When set, any check-in at or beyond this threshold is
                                                <span class="text-danger fw-semibold">auto-rejected</span> immediately — no window is opened.
                                            </small>
                                        </div>
                                    </div>

                                    {{-- ── Three window columns ── --}}
                                    <div class="row g-3">
                                        @foreach($approval['windows'] as $i => $window)
                                            @php
                                                $windowLabel = 'WINDOW ' . ($i + 1);
                                                $isLast      = $i === 2;
                                                $autoReject  = $approval['auto_reject_after_minutes'] ?? null;
                                                $thisMin     = $window['min_minutes_late'] ?? 0;

                                                // Build a human-readable routing hint for this window
                                                // e.g. "Handles 20 – 39 min late" or "Handles 40+ min late"
                                                $nextMin  = null;
                                                for ($j = $i + 1; $j < 3; $j++) {
                                                    if (!empty($approval['windows'][$j]['enabled'])) {
                                                        $nextMin = $approval['windows'][$j]['min_minutes_late'] ?? null;
                                                        break;
                                                    }
                                                }
                                                $ceiling = $autoReject ?? null;

                                                if ($nextMin !== null) {
                                                    $rangeHint = "Handles {$thisMin} – " . ($nextMin - 1) . " min late";
                                                } elseif ($ceiling !== null) {
                                                    $rangeHint = "Handles {$thisMin} – " . ($ceiling - 1) . " min late";
                                                } else {
                                                    $rangeHint = "Handles {$thisMin}+ min late";
                                                }
                                            @endphp

                                            <div class="col-lg-4 col-12">
                                                <div class="border rounded-3 p-3 h-100 {{ $window['enabled'] ? 'border-danger-subtle' : '' }}"
                                                     style="{{ $window['enabled'] ? 'border-color:#dc3545 !important;' : '' }}">

                                                    {{-- Window header --}}
                                                    <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="fw-bold small {{ $window['enabled'] ? 'text-danger' : 'text-muted' }}">
                                {{ $windowLabel }}
                            </span>
                                                        <div class="form-check form-switch m-0">
                                                            <input class="form-check-input" type="checkbox" role="switch"
                                                                   id="window{{ $i }}Enabled"
                                                                   wire:model="approval.windows.{{ $i }}.enabled">
                                                        </div>
                                                    </div>

                                                    {{-- Routing hint badge --}}
                                                    @if($window['enabled'])
                                                        <div class="mb-3">
                                <span class="badge bg-primary-subtle text-primary fw-normal small">
                                    <iconify-icon icon="mdi:clock-fast" class="me-1"></iconify-icon>
                                    {{ $rangeHint }}
                                </span>
                                                        </div>
                                                    @else
                                                        <div class="mb-3">
                                                            <span class="badge bg-secondary-subtle text-secondary fw-normal small">Disabled</span>
                                                        </div>
                                                    @endif

                                                    {{-- ── Min. minutes late (entry threshold) ── --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold d-flex align-items-center gap-1">
                                                            <iconify-icon icon="mdi:clock-start"></iconify-icon>
                                                            Min. minutes late
                                                        </label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" min="0" max="1440"
                                                                   class="form-control"
                                                                   wire:model="approval.windows.{{ $i }}.min_minutes_late"
                                                                {{ $window['enabled'] ? '' : 'disabled' }}>
                                                            <span class="input-group-text">min</span>
                                                        </div>
                                                        <small class="text-muted">
                                                            Route here when late by <strong>≥ {{ $thisMin }} min</strong>
                                                            @if($i === 0)(catch-all if no higher window matches)@endif.
                                                        </small>
                                                    </div>

                                                    {{-- Approver Role --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold">Approver Role</label>
                                                        <select class="form-select form-select-sm"
                                                                wire:model="approval.windows.{{ $i }}.approver_role"
                                                            {{ $window['enabled'] ? '' : 'disabled' }}>
                                                            <option value="">— Select Role —</option>
                                                            @foreach($availableRoles as $role)
                                                                <option value="{{ $role }}">{{ $role }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>

                                                    {{-- Timeout --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold">
                                                            Timeout (minutes)
                                                        </label>
                                                        <input type="number" min="1" max="1440"
                                                               class="form-control form-control-sm"
                                                               wire:model="approval.windows.{{ $i }}.timeout_minutes"
                                                            {{ $window['enabled'] ? '' : 'disabled' }}>
                                                    </div>

                                                    {{-- On Timeout --}}
                                                    <div class="mb-3">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold">On Timeout</label>
                                                        <div class="btn-group btn-group-sm w-100" role="group">
                                                            @php
                                                                $options = $isLast
                                                                    ? ['approve' => 'Approve', 'reject' => 'Reject']
                                                                    : ['approve' => 'Approve', 'reject' => 'Reject', 'escalate' => 'Escalate ↑'];
                                                            @endphp
                                                            @foreach($options as $value => $optLabel)
                                                                @php
                                                                    $active     = $window['on_timeout'] === $value;
                                                                    $colorClass = match($value) {
                                                                        'approve'  => $active ? 'btn-success'  : 'btn-outline-success',
                                                                        'reject'   => $active ? 'btn-danger'   : 'btn-outline-danger',
                                                                        'escalate' => $active ? 'btn-primary'  : 'btn-outline-primary',
                                                                    };
                                                                @endphp
                                                                <button type="button"
                                                                        class="btn {{ $colorClass }}"
                                                                        wire:click="$set('approval.windows.{{ $i }}.on_timeout', '{{ $value }}')"
                                                                    {{ $window['enabled'] ? '' : 'disabled' }}>
                                                                    {{ $optLabel }}
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>

                                                    {{-- Notifications --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold d-flex align-items-center gap-1">
                                                            <iconify-icon icon="mdi:bell-outline"></iconify-icon>
                                                            Notify Via
                                                        </label>

                                                        {{-- Email --}}
                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                <span class="d-flex align-items-center gap-1 small">
                                    <iconify-icon icon="mdi:email-outline"></iconify-icon> Email
                                </span>
                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input" type="checkbox" role="switch"
                                                                       wire:model="approval.windows.{{ $i }}.notify_email"
                                                                    {{ $window['enabled'] ? '' : 'disabled' }}>
                                                            </div>
                                                        </div>

                                                        @if($window['notify_email'])
                                                            @foreach($window['notify_email_addresses'] as $ei => $email)
                                                                <div class="input-group input-group-sm mb-1">
                                                                    <input type="email" class="form-control"
                                                                           placeholder="approver@example.com"
                                                                           wire:model="approval.windows.{{ $i }}.notify_email_addresses.{{ $ei }}">
                                                                    <button class="btn btn-outline-secondary" type="button"
                                                                            wire:click="removeEmail({{ $i }}, {{ $ei }})">
                                                                        <iconify-icon icon="mdi:close"></iconify-icon>
                                                                    </button>
                                                                </div>
                                                            @endforeach
                                                            <button type="button" class="btn btn-sm btn-link p-0 mb-2"
                                                                    wire:click="addEmail({{ $i }})">
                                                                + Add email
                                                            </button>
                                                        @endif

                                                        {{-- SMS --}}
                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                <span class="d-flex align-items-center gap-1 small">
                                    <iconify-icon icon="mdi:message-text-outline"></iconify-icon> SMS
                                </span>
                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input" type="checkbox" role="switch"
                                                                       wire:model="approval.windows.{{ $i }}.notify_sms"
                                                                    {{ $window['enabled'] ? '' : 'disabled' }}>
                                                            </div>
                                                        </div>

                                                        @if($window['notify_sms'])
                                                            @foreach($window['notify_sms_numbers'] as $pi => $phone)
                                                                <div class="input-group input-group-sm mb-1">
                                                                    <input type="text" class="form-control"
                                                                           placeholder="+254 7xx xxx xxx"
                                                                           wire:model="approval.windows.{{ $i }}.notify_sms_numbers.{{ $pi }}">
                                                                    <button class="btn btn-outline-secondary" type="button"
                                                                            wire:click="removePhone({{ $i }}, {{ $pi }})">
                                                                        <iconify-icon icon="mdi:close"></iconify-icon>
                                                                    </button>
                                                                </div>
                                                            @endforeach
                                                            <button type="button" class="btn btn-sm btn-link p-0"
                                                                    wire:click="addPhone({{ $i }})">
                                                                + Add phone
                                                            </button>
                                                        @endif
                                                    </div>

                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="d-flex align-items-center justify-content-end gap-2 mt-4">
                                        <button wire:click="saveApprovalSettings" class="btn btn-primary">
                                            <iconify-icon icon="mdi:content-save-outline" class="me-1"></iconify-icon>
                                            Save Approval Settings
                                        </button>
                                    </div>

                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════════
                     Leave Approval Tab
                ════════════════════════════════════════════════════════ --}}
                <div class="tab-pane fade {{ $activeTab === 'leave_approval' ? 'show active' : '' }}"
                     id="tab-leave-approval">

                    <div class="row justify-content-center">
                        <div class="col-lg-12">

                            {{-- Scope: exactly one of organization-wide or a single department is
                                 ever being edited at a time, to avoid confusion about which config
                                 is active. --}}
                            <div class="card border shadow-none mb-4">
                                <div class="card-body p-4">
                                    <h5 class="mb-3 d-flex align-items-center gap-2">
                                        <iconify-icon icon="mdi:sitemap-outline" class="fs-4 text-primary"></iconify-icon>
                                        Scope
                                    </h5>

                                    <div class="btn-group w-100 mb-3" role="group">
                                        <button type="button"
                                                class="btn {{ $leaveApprovalScope === 'organization' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                wire:click="setLeaveApprovalScope('organization')">
                                            Organization-wide
                                        </button>
                                        <button type="button"
                                                class="btn {{ $leaveApprovalScope === 'department' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                wire:click="setLeaveApprovalScope('department')">
                                            Specific Department
                                        </button>
                                    </div>

                                    @if($leaveApprovalScope === 'department')
                                        <label class="form-label small text-uppercase text-muted fw-semibold">Department</label>
                                        <select class="form-select" wire:model="leaveApprovalDepartmentId"
                                                wire:change="setLeaveApprovalDepartment($event.target.value)">
                                            <option value="">— Select Department —</option>
                                            @foreach($availableDepartments as $dept)
                                                <option value="{{ $dept['id'] }}">
                                                    {{ $dept['name'] }}
                                                    @if(in_array($dept['id'], $leaveApprovalOverriddenDepartmentIds)) · Custom @endif
                                                </option>
                                            @endforeach
                                        </select>

                                        @if($leaveApprovalDepartmentId)
                                            <div class="mt-3">
                                                @if($leaveApprovalHasOverride)
                                                    <div class="d-flex align-items-center justify-content-between gap-2 py-2 px-3 small rounded-3"
                                                         style="background:#fff3cd;border:1px solid #ffe69c;color:#664d03;">
                                                        <span class="d-flex align-items-center gap-2">
                                                            <iconify-icon icon="mdi:pencil-outline" class="fs-5"></iconify-icon>
                                                            This department has a custom approval chain.
                                                        </span>
                                                        <button wire:click="resetLeaveApprovalDepartmentOverride"
                                                                class="btn btn-sm btn-outline-secondary flex-shrink-0">
                                                            Reset to organization default
                                                        </button>
                                                    </div>
                                                @else
                                                    <div class="py-2 px-3 d-flex gap-2 align-items-center small rounded-3"
                                                         style="background:#e7f1ff;border:1px solid #b6d4fe;color:#084298;">
                                                        <iconify-icon icon="mdi:information-outline" class="fs-5"></iconify-icon>
                                                        This department is currently using the organization-wide default shown below.
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            @if($leaveApprovalScope === 'organization' || $leaveApprovalDepartmentId)

                            {{-- Approval system master toggle --}}
                            <div class="card border shadow-none mb-4">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:calendar-check-outline"
                                                          class="fs-4 text-primary"></iconify-icon>
                                            Leave approval chain
                                        </h5>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="leaveApprovalEnabled"
                                                   wire:model="leaveApproval.enabled"
                                                   style="width:3em;height:1.5em;">
                                            <label class="form-check-label fw-semibold ms-2" for="leaveApprovalEnabled">
                                                {{ $leaveApprovalScope === 'department' ? 'Enabled for this department' : 'Enabled for this tenant' }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-0 py-2 px-3 d-flex gap-2 align-items-start small rounded-3"
                                         style="background:#e7f1ff;border:1px solid #b6d4fe;">
                                        <iconify-icon icon="mdi:information-outline"
                                                      class="fs-5 flex-shrink-0"
                                                      style="color:#0d6efd;margin-top:1px;"></iconify-icon>
                                        <span style="color:#084298;line-height:1.6;">
                When enabled, a submitted leave request must be approved sequentially through each
                <strong>enabled</strong> level below (Level 1 → Level 2 → Level 3). A rejection at any
                level immediately finalizes the request as rejected. You don't need to enable all 3 —
                only the enabled levels are used.
            </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Approval levels --}}
                            <div class="card border shadow-none mb-4">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:format-list-numbered" class="fs-4 text-primary"></iconify-icon>
                                            Approval levels
                                        </h5>
                                    </div>

                                    <div class="row g-3">
                                        @foreach($leaveApproval['levels'] as $i => $level)
                                            <div class="col-lg-4 col-12">
                                                <div class="border rounded-3 p-3 h-100 {{ $level['enabled'] ? 'border-primary-subtle' : '' }}"
                                                     style="{{ $level['enabled'] ? 'border-color:#0d6efd !important;' : '' }}">

                                                    {{-- Level header --}}
                                                    <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="fw-bold small {{ $level['enabled'] ? 'text-primary' : 'text-muted' }}">
                                LEVEL {{ $i + 1 }}
                            </span>
                                                        <div class="form-check form-switch m-0">
                                                            <input class="form-check-input" type="checkbox" role="switch"
                                                                   id="leaveLevel{{ $i }}Enabled"
                                                                   wire:model="leaveApproval.levels.{{ $i }}.enabled">
                                                        </div>
                                                    </div>

                                                    {{-- Approver type --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold">Approver</label>
                                                        <div class="btn-group btn-group-sm w-100" role="group">
                                                            <button type="button"
                                                                    class="btn {{ $level['approver_type'] === 'role' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                                    wire:click="$set('leaveApproval.levels.{{ $i }}.approver_type', 'role')"
                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                Role
                                                            </button>
                                                            <button type="button"
                                                                    class="btn {{ $level['approver_type'] === 'user' ? 'btn-primary' : 'btn-outline-primary' }}"
                                                                    wire:click="$set('leaveApproval.levels.{{ $i }}.approver_type', 'user')"
                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                Specific user
                                                            </button>
                                                        </div>
                                                    </div>

                                                    @if($level['approver_type'] === 'user')
                                                        <div class="mb-2">
                                                            <label class="form-label small text-uppercase text-muted fw-semibold">User</label>
                                                            <select class="form-select form-select-sm"
                                                                    wire:model="leaveApproval.levels.{{ $i }}.approver_user_id"
                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                <option value="">— Select User —</option>
                                                                @foreach($availableUsers as $availableUser)
                                                                    <option value="{{ $availableUser['id'] }}">{{ $availableUser['name'] }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @else
                                                        <div class="mb-2">
                                                            <label class="form-label small text-uppercase text-muted fw-semibold">Role</label>
                                                            <select class="form-select form-select-sm"
                                                                    wire:model="leaveApproval.levels.{{ $i }}.approver_role"
                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                <option value="">— Select Role —</option>
                                                                @foreach($availableRoles as $role)
                                                                    <option value="{{ $role }}">{{ $role }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @endif

                                                    {{-- Notifications --}}
                                                    <div class="mb-2">
                                                        <label class="form-label small text-uppercase text-muted fw-semibold d-flex align-items-center gap-1">
                                                            <iconify-icon icon="mdi:bell-outline"></iconify-icon>
                                                            Also notify extra emails
                                                        </label>

                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                <span class="d-flex align-items-center gap-1 small">
                                    <iconify-icon icon="mdi:email-outline"></iconify-icon> Email
                                </span>
                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input" type="checkbox" role="switch"
                                                                       wire:model="leaveApproval.levels.{{ $i }}.notify_email"
                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                            </div>
                                                        </div>

                                                        @if($level['notify_email'])
                                                            @foreach($level['notify_email_addresses'] as $ei => $email)
                                                                <div class="input-group input-group-sm mb-1">
                                                                    <input type="email" class="form-control"
                                                                           placeholder="approver@example.com"
                                                                           wire:model="leaveApproval.levels.{{ $i }}.notify_email_addresses.{{ $ei }}">
                                                                    <button class="btn btn-outline-secondary" type="button"
                                                                            wire:click="removeLeaveApprovalEmail({{ $i }}, {{ $ei }})">
                                                                        <iconify-icon icon="mdi:close"></iconify-icon>
                                                                    </button>
                                                                </div>
                                                            @endforeach
                                                            <button type="button" class="btn btn-sm btn-link p-0"
                                                                    wire:click="addLeaveApprovalEmail({{ $i }})">
                                                                + Add email
                                                            </button>
                                                        @endif
                                                    </div>

                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="d-flex align-items-center justify-content-end gap-2 mt-4">
                                        <button wire:click="saveLeaveApprovalSettings" class="btn btn-primary">
                                            <iconify-icon icon="mdi:content-save-outline" class="me-1"></iconify-icon>
                                            {{ $leaveApprovalScope === 'department' ? 'Save for Selected Department' : 'Save Organization Default' }}
                                        </button>
                                    </div>

                                </div>
                            </div>

                            @endif

                        </div>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════════
                     Leave Types Tab
                ════════════════════════════════════════════════════════ --}}
                <div class="tab-pane fade {{ $activeTab === 'leave_types' ? 'show active' : '' }}"
                     id="tab-leave-types">

                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:calendar-cog-outline"
                                                          class="fs-4 text-primary"></iconify-icon>
                                            Leave Types
                                        </h5>

                                        <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                                           data-bs-target="#leaveTypeModal">
                                            <i class="ti ti-calendar-plus fs-5"></i> Add Leave Type
                                        </a>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Icon</th>
                                                <th class="text-end">Annual Entitlement (days)</th>
                                                <th>Active</th>
                                                <th>Actions</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @forelse($this->leaveTypesList as $lt)
                                                <tr wire:key="leave-type-row-{{ $lt->id }}">
                                                    <td class="fw-semibold">{{ $lt->name }}</td>
                                                    <td><span
                                                            class="badge bg-secondary-subtle text-secondary">{{ $lt->code }}</span>
                                                    </td>
                                                    <td>{{ $lt->icon }}</td>
                                                    <td class="text-end">{{ $lt->annual_entitlement_days ?? 'Unlimited' }}</td>
                                                    <td>
                                                        @if($lt->is_active)
                                                            <span
                                                                class="badge bg-success-subtle text-success">Active</span>
                                                        @else
                                                            <span
                                                                class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button class="btn btn-sm btn-warning"
                                                                    wire:click="editLeaveTypeHandler({{ $lt->id }})">
                                                                <i class="ti ti-edit"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-danger"
                                                                    onclick="confirm('Are you sure? Existing leave requests of this type will keep displaying, but no new requests can use it once removed.') || event.stopImmediatePropagation()"
                                                                    wire:click="deleteLeaveTypeHandler({{ $lt->id }})">
                                                                <i class="ti ti-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No leave
                                                        types yet.
                                                    </td>
                                                </tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="leaveTypeModal" tabindex="-1"
                         aria-labelledby="leaveTypeModalTitle" aria-hidden="true" wire:ignore.self>
                        <div class="modal-dialog modal-md modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">{{ $ltEditId ? 'Edit Leave Type' : 'New Leave Type' }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>

                                <form wire:submit.prevent="{{ $ltEditId ? 'updateLeaveType' : 'createLeaveType' }}">
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="ltName" class="form-label">Name</label>
                                            <input type="text" wire:model="ltName" id="ltName" class="form-control"
                                                   placeholder="e.g. Annual Leave"/>
                                            @error('ltName') <small class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <div class="mb-3">
                                            <label for="ltCode" class="form-label">Code</label>
                                            <input type="text" wire:model="ltCode" id="ltCode" class="form-control"
                                                   placeholder="e.g. annual"/>
                                            <small class="text-muted">A stable identifier used internally (letters,
                                                numbers, dashes/underscores only).</small>
                                            @error('ltCode') <small class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <div class="mb-3">
                                            <label for="ltIcon" class="form-label">Icon (optional emoji)</label>
                                            <input type="text" wire:model="ltIcon" id="ltIcon" class="form-control"
                                                   placeholder="🏖️"/>
                                            @error('ltIcon') <small class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <div class="mb-3">
                                            <label for="ltAnnualEntitlementDays" class="form-label">Annual
                                                entitlement (days)</label>
                                            <input type="number" step="0.5" min="0"
                                                   wire:model="ltAnnualEntitlementDays"
                                                   id="ltAnnualEntitlementDays" class="form-control"
                                                   placeholder="Leave blank for unlimited / not balance-tracked"/>
                                            <small class="text-muted">Leave blank for types that aren't
                                                balance-tracked (e.g. unpaid leave, off-shift).</small>
                                            @error('ltAnnualEntitlementDays') <small
                                                class="text-danger">{{ $message }}</small> @enderror
                                        </div>

                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="ltIsActive" wire:model="ltIsActive">
                                            <label class="form-check-label" for="ltIsActive">Active</label>
                                        </div>
                                    </div>

                                    <div class="modal-footer d-flex gap-1">
                                        <button type="submit" class="btn btn-success">
                                            {{ $ltEditId ? 'Save' : 'Add' }}
                                        </button>
                                        <button wire:click="$dispatch('discard-leave-type-modal')" type="button"
                                                class="btn btn-outline-danger" data-bs-dismiss="modal">
                                            Discard
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('#pills-tab button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    let mappedTab;
                    switch (tabId) {
                        case 'tab-qr-code-tab':
                            mappedTab = 'qr_code';
                            break;
                        case 'tab-shift-management-tab':
                            mappedTab = 'shift_management';
                            break;
                        case 'tab-checkin-approval-tab':
                            mappedTab = 'checkin_approval';
                            break;
                        case 'tab-leave-approval-tab':
                            mappedTab = 'leave_approval';
                            break;
                        case 'tab-leave-types-tab':
                            mappedTab = 'leave_types';
                            break;
                        default:
                            mappedTab = 'qr_code';
                    }

                    Livewire.dispatch('systemSettingsTabChanged', {tabId: mappedTab});
                });
            });
        });

        window.addEventListener('show-leave-type-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveTypeModal')).show();
        });

        window.addEventListener('hide-leave-type-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveTypeModal'))?.hide();
        });
    </script>
@endpush
