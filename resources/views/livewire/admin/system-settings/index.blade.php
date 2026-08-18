<?php

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Services\CheckInApprovalSettings;
use App\Services\LeaveApprovalSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;
    public $activeParentTab = 'attendance'; // default
    public string $activeTab = 'shift_management'; // default
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
    public array $leaveApprovalUserDropdownOpen = [];
    public array $leaveApprovalUserSearch = [];
    public array $leaveApprovalEmailDropdownOpen = [];
    public array $leaveApprovalEmailSearch = [];
    public int $activeLeaveApprovalLevel = 0;


    // public $selectedUserIds;
    // public $selectedEmailAddresses;
    // public $selectedEmailUsers;
    // public $selectedUsers;
    public $selectedUserIds = [];

    // ── Leave Types tab state ─────────────────────────────────────────────
    public $ltName;
    public $ltCode;
    public $ltIcon;
    public $ltAnnualEntitlementDays;
    public $ltIncludeWeekends;
    public $ltIncludeHolidays;
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

        // Help & Support settings
        $this->settings['show_help_icon'] = isset($this->settings['show_help_icon'])
            ? (bool)$this->settings['show_help_icon']
            : false;
        $this->settings['help_page_url'] = $this->settings['help_page_url'] ?? '';
        $this->settings['help_icon_tooltip_label'] = $this->settings['help_icon_tooltip_label'] ?? 'Help';

        // ── Load check-in approval settings ──
        $this->approval = CheckInApprovalSettings::get($orgId);

        // ── Load leave approval settings ──
        $this->leaveApproval = LeaveApprovalSettings::get($orgId);
        $this->normalizeLeaveApprovalUserIds();
        $this->applyLeaveApprovalLevelRules();
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
            ->get(['id', 'name', 'email'])
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->toArray();
    }

    #[On('systemSettingsTabChanged')]
    public function systemSettingsTabChanged($tabId)
    {
        $this->setActiveTab($tabId);
    }

    public function setActiveParentTab(string $parentTab): void
    {
        if (!in_array($parentTab, ['attendance', 'leaves', 'help_support'], true)) {
            return;
        }

        $this->activeParentTab = $parentTab;

        if ($parentTab === 'attendance') {
            $this->activeTab = 'shift_management';
        } elseif ($parentTab === 'leaves') {
            $this->activeTab = 'leave_approval';
        } else {
            $this->activeTab = 'help_support';
        }

        $this->changeSystemSettingsBreadcrumb();
    }

    public function setActiveTab(string $tabId): void
    {
        $this->activeTab = $tabId;

        if (in_array($tabId, ['shift_management', 'checkin_approval'], true)) {
            $this->activeParentTab = 'attendance';
        } elseif (in_array($tabId, ['leave_approval', 'leave_types'], true)) {
            $this->activeParentTab = 'leaves';
        } elseif ($tabId === 'help_support') {
            $this->activeParentTab = 'help_support';
        }

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

                $setting->type = in_array($key, ['generate_employee_qr_on_create', 'show_help_icon'])
                    ? 'boolean'
                    : $setting->type ?? 'string';

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

    public function updatedLeaveApproval($value, $key): void
    {
        if (!str_contains($key, 'levels.')) {
            return;
        }

        if (preg_match('/^levels\.(\d+)\.notify_email$/', $key, $matches)) {
            $levelIndex = (int) $matches[1];

            if (!$value) {
                $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'] = [];
                $this->leaveApprovalEmailDropdownOpen[$levelIndex] = false;
            }

            return;
        }

        if (preg_match('/^levels\.(\d+)\.enabled$/', $key, $matches)) {
            $levelIndex = (int) $matches[1];

            if ($levelIndex === 0) {
                $this->leaveApproval['levels'][0]['enabled'] = true;
            }

            $this->applyLeaveApprovalLevelRules();

            if (!$value) {
                // Keep the notify_email setting independent, but close the email picker
                // when the level itself is disabled.
                $this->leaveApprovalEmailDropdownOpen[$levelIndex] = false;
            }

            return;
        }
    }

    public function leaveApprovalNotifyEmailChanged(int $levelIndex): void
    {
        if (empty($this->leaveApproval['levels'][$levelIndex]['notify_email'])) {
            $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'] = [];
            $this->leaveApprovalEmailDropdownOpen[$levelIndex] = false;
        }
    }

    public function toggleLeaveApprovalUserDropdown(int $levelIndex): void
    {
        $current = $this->leaveApprovalUserDropdownOpen[$levelIndex] ?? false;

        $this->leaveApprovalUserDropdownOpen = [];
        $this->leaveApprovalUserDropdownOpen[$levelIndex] = !$current;

        if ($this->leaveApprovalUserDropdownOpen[$levelIndex]) {
            $this->leaveApprovalUserSearch[$levelIndex] = '';
        }
    }

    #[On('closeLeaveApprovalUserDropdowns')]
    public function closeLeaveApprovalUserDropdowns(): void
    {
        $this->leaveApprovalUserDropdownOpen = [];
        $this->leaveApprovalEmailDropdownOpen = [];
    }

    public function setLeaveApprovalUserSearch(int $levelIndex, string $search): void
    {
        $this->leaveApprovalUserSearch[$levelIndex] = $search;
    }

    public function addLeaveApprovalUser(int $levelIndex, int $userId): void
    {

        if (!isset($this->leaveApproval['levels'][$levelIndex]['approver_user_ids'])) {
            $this->leaveApproval['levels'][$levelIndex]['approver_user_ids'] = [];
        }

        // commented out to accommodate the new approver_user_ids array structure only when approver_type is 'user'
        if (!in_array($userId, $this->leaveApproval['levels'][$levelIndex]['approver_user_ids'], true)) {
            $this->leaveApproval['levels'][$levelIndex]['approver_user_ids'][] = $userId;
        }

        $this->leaveApprovalUserDropdownOpen[$levelIndex] = false;
        $this->leaveApprovalUserSearch[$levelIndex] = '';
    }

    public function setLeaveApprovalEmailSearch(int $levelIndex, string $search): void
    {
        $this->leaveApprovalEmailSearch[$levelIndex] = $search;
    }

    public function toggleLeaveApprovalEmailDropdown(int $levelIndex): void
    {
        $current = $this->leaveApprovalEmailDropdownOpen[$levelIndex] ?? false;

        $this->leaveApprovalEmailDropdownOpen = [];
        $this->leaveApprovalEmailDropdownOpen[$levelIndex] = !$current;

        if ($this->leaveApprovalEmailDropdownOpen[$levelIndex]) {
            $this->leaveApprovalEmailSearch[$levelIndex] = '';
        }
    }

    public function addLeaveApprovalEmailUser(int $levelIndex, int $userId): void
    {
        if (!isset($this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'])) {
            $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'] = [];
        }

        $user = collect($this->availableUsers)->firstWhere('id', $userId);
        if (!$user || empty($user['email'])) {
            return;
        }

        if (!in_array($user['email'], $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'], true)) {
            $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'][] = $user['email'];
        }

        $this->leaveApprovalEmailDropdownOpen[$levelIndex] = false;
        $this->leaveApprovalEmailSearch[$levelIndex] = '';
    }

    public function removeLeaveApprovalEmailAddress(int $levelIndex, string $email): void
    {
        if (empty($this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'])
            || !is_array($this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'])) {
            return;
        }

        $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'] = array_values(array_filter(
            $this->leaveApproval['levels'][$levelIndex]['notify_email_addresses'],
            fn ($item) => $item !== $email
        ));
    }

    public function removeLeaveApprovalUser(int $levelIndex, int $userId): void
    {
        if (empty($this->leaveApproval['levels'][$levelIndex]['approver_user_ids'])
            || !is_array($this->leaveApproval['levels'][$levelIndex]['approver_user_ids'])) {
            return;
        }

        $this->leaveApproval['levels'][$levelIndex]['approver_user_ids'] = array_values(array_filter(
            $this->leaveApproval['levels'][$levelIndex]['approver_user_ids'],
            fn ($id) => $id !== $userId
        ));
    }

    private function normalizeLeaveApprovalUserIds(): void
    {
        foreach ($this->leaveApproval['levels'] as $i => $level) {
            $ids = [];

            if (!empty($level['approver_user_ids']) && is_array($level['approver_user_ids'])) {
                $ids = $level['approver_user_ids'];
            }

            // if (!empty($level['approver_user_id'])) {
            //     $ids[] = $level['approver_user_id'];
            // }

            $ids = array_values(array_unique(array_filter(array_map(
                fn ($id) => is_numeric($id) ? (int) $id : null,
                $ids
            ))));

            $this->leaveApproval['levels'][$i]['approver_user_ids'] = $this->leaveApproval['levels'][$i]['approver_type'] === 'user'
                ? $ids
                : [];
        }
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

    public function selectLeaveApprovalLevel(int $levelIndex): void
    {
        if (!isset($this->leaveApproval['levels'][$levelIndex])) {
            return;
        }

        $this->activeLeaveApprovalLevel = $levelIndex;
    }

    public function setLeaveApprovalLevelEnabled(int $levelIndex, bool $enabled): void
    {
        if (!isset($this->leaveApproval['levels'][$levelIndex])) {
            return;
        }

        // Level 1 is always enabled.
        if ($levelIndex === 0) {
            $this->leaveApproval['levels'][0]['enabled'] = true;
            $this->applyLeaveApprovalLevelRules();
            return;
        }

        $this->leaveApproval['levels'][$levelIndex]['enabled'] = $enabled;

        $this->applyLeaveApprovalLevelRules();
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

        $this->normalizeLeaveApprovalUserIds();
        $this->applyLeaveApprovalLevelRules();
    }

    private function applyLeaveApprovalLevelRules(): void
    {
        if (empty($this->leaveApproval['levels']) || !is_array($this->leaveApproval['levels'])) {
            return;
        }

        $this->leaveApproval['levels'][0]['enabled'] = true;

        $count = count($this->leaveApproval['levels']);
        for ($i = 1; $i < $count; $i++) {
            $prevEnabled = (bool)($this->leaveApproval['levels'][$i - 1]['enabled'] ?? false);
            if (!$prevEnabled) {
                $this->leaveApproval['levels'][$i]['enabled'] = false;
                $this->leaveApprovalUserDropdownOpen[$i] = false;
                $this->leaveApprovalEmailDropdownOpen[$i] = false;
            }
        }

        $active = $this->activeLeaveApprovalLevel;
        if (!isset($this->leaveApproval['levels'][$active]) || empty($this->leaveApproval['levels'][$active]['enabled'])) {
            $fallback = 0;
            foreach ($this->leaveApproval['levels'] as $idx => $level) {
                if (!empty($level['enabled'])) {
                    $fallback = $idx;
                }
            }
            $this->activeLeaveApprovalLevel = $fallback;
        }
    }

    public function saveLeaveApprovalSettings(): void
    {
        $this->applyLeaveApprovalLevelRules();

        $this->validate([
            'leaveApproval.enabled'                              => 'boolean',
            'leaveApproval.levels'                               => 'array|size:3',
            'leaveApproval.levels.*.enabled'                     => 'boolean',
            'leaveApproval.levels.*.approver_type'               => 'required|in:role,user',
            'leaveApproval.levels.*.approver_role'               => 'nullable|string|max:100',
            'leaveApproval.levels.*.approver_rule'               => 'required|in:anyone_approve,all_approve',
            'leaveApproval.levels.*.approver_user_ids'          => 'nullable|array',
            'leaveApproval.levels.*.approver_user_ids.*'        => 'nullable|integer|exists:users,id',
            'leaveApproval.levels.*.notify_email'                => 'boolean',
            'leaveApproval.levels.*.notify_email_addresses.*'    => 'nullable|email',
        ]);

        $orgId = auth()->user()->employee?->organization_id;

        foreach ($this->leaveApproval['levels'] as $idx => $level) {
            if ($level['enabled'] && $level['approver_type'] === 'user' && empty($level['approver_user_ids'])) {
                $this->addError("leaveApproval.levels.$idx.approver_user_ids", 'Select at least one approver for this level.');
                return;
            }
        }

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
        $this->applyLeaveApprovalLevelRules();

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
            'ltIncludeWeekends' => 'boolean',
            'ltIncludeHolidays' => 'boolean',
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
                'weekends_included' => $this->ltIncludeWeekends,
                'holidays_included' => $this->ltIncludeHolidays,
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
        $this->ltIncludeWeekends = $type->weekends_included;
        $this->ltIncludeHolidays = $type->holidays_included;
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
                'weekends_included' => $this->ltIncludeWeekends ? true : false,
                'holidays_included' => $this->ltIncludeHolidays ? true : false,
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
        $this->reset(['ltName', 'ltCode', 'ltIcon', 'ltAnnualEntitlementDays', 'ltIncludeWeekends', 'ltIncludeHolidays', 'ltEditId']);
        $this->ltIsActive = true;
    }

    public function changeSystemSettingsBreadcrumb()
    {
        switch ($this->activeTab) {

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

            case 'help_support':
                $this->tabTitle = 'Help & Support';
                $this->tabIcon = '<iconify-icon icon="mdi:help-circle-outline" class="fs-5"></iconify-icon>';
                break;

            default:
                $this->tabTitle = 'Shift Management';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock-outline" class="fs-5"></iconify-icon>';
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

@push('styles')
    <style>
        .leave-level-layout {
            --leave-red: #c92a2f;
            --leave-red-soft: #fdf1f1;
            --leave-border: #e7e9ef;
        }

        .leave-level-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .leave-level-item {
            border: 1px solid var(--leave-border);
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
            background: #fff;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .leave-level-item.active {
            border-color: #ea3c45;
            background: var(--leave-red-soft);
            box-shadow: 0 0 0 1px rgba(201, 42, 47, 0.1);
        }

        .leave-level-item.locked {
            opacity: 0.6;
        }

        .leave-level-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #9ca3af;
            flex-shrink: 0;
        }

        .leave-level-item.active .leave-level-dot,
        .leave-level-dot.on {
            background: var(--leave-red);
        }

        .leave-level-status {
            font-size: 0.72rem;
            color: #9ca3af;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 0.4rem;
        }

        .leave-level-panel {
            border: 1.5px solid #ef5962;
            border-radius: 14px;
            padding: 1rem;
            background: #fff;
        }

        .leave-level-panel-header {
            border-bottom: 1px solid #eef0f4;
            padding-bottom: 0.8rem;
            margin-bottom: 0.9rem;
        }

        .leave-level-panel-title {
            margin: 0;
            color: #212529;
            font-size: 1.05rem;
            font-weight: 600;
        }

        .leave-level-panel-sub {
            margin: 0;
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 600;
            /* text-transform: lowercase; */
            letter-spacing: 0.03em;
        }

        .leave-red-btn {
            background: #c92a2f;
            border-color: #c92a2f;
            color: #fff;
        }

        .leave-red-btn:hover {
            background: #b02327;
            border-color: #b02327;
            color: #fff;
        }
        
        .table-header {
            background-color: #a2a4a7;
        }
    </style>
@endpush

<div class="container-fluid">

    <livewire:admin.system-settings.bread-crumb
        :title="$tabTitle"
        :items="$breadcrumbItems"
    />

    <div class="card">
        <ul class="nav nav-pills user-profile-tab" id="parent-pills-tab" role="tablist">


            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeParentTab === 'attendance' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                    id="tab-attendance-tab"
                    type="button"
                    wire:click="setActiveParentTab('attendance')"
                    role="tab"
                    aria-controls="tab-attendance"
                    aria-selected="{{ $activeParentTab === 'attendance' ? 'true' : 'false' }}">
                    <i class="ti ti-clock me-2 fs-6"></i>
                    <span class="d-none d-md-block">Attendance Settings</span>
                </button>
            </li>


            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeParentTab === 'leaves' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                    id="tab-leaves-tab"
                    type="button"
                    wire:click="setActiveParentTab('leaves')"
                    role="tab"
                    aria-controls="tab-leaves"
                    aria-selected="{{ $activeParentTab === 'leaves' ? 'true' : 'false' }}">
                    <i class="ti ti-calendar-check me-2 fs-6"></i>
                    <span class="d-none d-md-block">Leave Settings</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeParentTab === 'help_support' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                    id="tab-help-support-tab"
                    type="button"
                    wire:click="setActiveParentTab('help_support')"
                    role="tab"
                    aria-controls="tab-help-support"
                    aria-selected="{{ $activeParentTab === 'help_support' ? 'true' : 'false' }}">
                    <i class="ti ti-help-circle me-2 fs-6"></i>
                    <span class="d-none d-md-block">Help & Support</span>
                </button>
            </li>

        </ul>

        @if($activeParentTab === 'attendance')
            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist" style="background-color: #F3F4F6;">


                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeTab === 'shift_management' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-shift-management-tab"
                        type="button"
                        wire:click="setActiveTab('shift_management')"
                        role="tab"
                        aria-controls="tab-shift-management"
                        aria-selected="{{ $activeTab === 'shift_management' ? 'true' : 'false' }}">
                        <i class="ti ti-calendar-time me-2 fs-6"></i>
                        <span class="d-none d-md-block">{{ auth()->user()->employee?->organization?->is_student_record ? 'Session' : 'Shift' }} Management</span>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeTab === 'checkin_approval' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-checkin-approval-tab"
                        type="button"
                        wire:click="setActiveTab('checkin_approval')"
                        role="tab"
                        aria-controls="tab-checkin-approval"
                        aria-selected="{{ $activeTab === 'checkin_approval' ? 'true' : 'false' }}">
                        <i class="ti ti-shield-check me-2 fs-6"></i>
                        <span class="d-none d-md-block">Check-in Approval</span>
                    </button>
                </li>
            </ul>
        @endif

        @if($activeParentTab === 'leaves')
            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist" style="background-color: #F3F4F6;">

                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeTab === 'leave_approval' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-leave-approval-tab"
                        type="button"
                        wire:click="setActiveTab('leave_approval')"
                        role="tab"
                        aria-controls="tab-leave-approval"
                        aria-selected="{{ $activeTab === 'leave_approval' ? 'true' : 'false' }}">
                        <i class="ti ti-calendar-check me-2 fs-6"></i>
                        <span class="d-none d-md-block">Leave Approval</span>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeTab === 'leave_types' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-leave-types-tab"
                        type="button"
                        wire:click="setActiveTab('leave_types')"
                        role="tab"
                        aria-controls="tab-leave-types"
                        aria-selected="{{ $activeTab === 'leave_types' ? 'true' : 'false' }}">
                        <i class="ti ti-calendar-cog me-2 fs-6"></i>
                        <span class="d-none d-md-block">Leave Types</span>
                    </button>
                </li>

            </ul>
        @endif


        <div class="card-body">
            <div class="tab-content " id="pills-tabContent">

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

                            <!-- {{-- Approval system master toggle --}}
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
                            </div> -->

                            {{-- Approval levels --}}
                            <div class="card border shadow-none mb-4 leave-level-layout">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                        <h5 class="mb-0 d-flex align-items-center gap-2">
                                            <iconify-icon icon="mdi:format-list-numbered" class="fs-4 text-primary"></iconify-icon>
                                            Approval levels
                                        </h5>
                                    </div>

                                    @php
                                        $activeLevelIndex = isset($leaveApproval['levels'][$activeLeaveApprovalLevel]) ? $activeLeaveApprovalLevel : 0;
                                    @endphp

                                    <div class="row g-3 align-items-start">
                                        <div class="col-lg-4 col-12">
                                            <div class="leave-level-list">
                                                @foreach($leaveApproval['levels'] as $i => $level)
                                                    @php
                                                        $isActive = $activeLevelIndex === $i;
                                                        $isLockedByParent = $i > 0 && empty($leaveApproval['levels'][$i - 1]['enabled']);

                                                        $enabled_check = true;
                                                        if($isLockedByParent) {
                                                            $enabled_check = false;
                                                        } else {
                                                            $enabled_check = !empty($level['enabled']) ? true : false;
                                                        }

                                                        if (!$enabled_check) {
                                                            $this->dispatch('uncheck-leave-level', index: $i);
                                                        }
                                                    @endphp
                                                    <div wire:key="leave-approval-level-nav-{{ $i }}"
                                                         class="leave-level-item {{ $isActive ? 'active' : '' }} {{ $isLockedByParent ? 'locked' : '' }}"
                                                         wire:click="selectLeaveApprovalLevel({{ $i }})">
                                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="leave-level-dot {{ !empty($level['enabled']) ? 'on' : '' }}"></span>
                                                                <span class="fw-semibold">Level {{ $i + 1 }}</span>
                                                                @if(empty($level['enabled']))
                                                                    <span class="leave-level-status">off</span>
                                                                @endif
                                                            </div>
                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input" type="checkbox" role="switch"
                                                                       id="leaveLevelNav{{ $i }}Enabled"
                                                                       @checked($enabled_check)
                                                                       wire:change="setLeaveApprovalLevelEnabled({{ $i }}, $event.target.checked)"
                                                                       wire:click.stop
                                                                    {{ $i === 0 || $isLockedByParent ? 'disabled' : '' }}>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        <div class="col-lg-8 col-12">
                                            @foreach($leaveApproval['levels'] as $i => $level)
                                                @if($activeLevelIndex === $i)
                                                    <div wire:key="leave-approval-level-detail-{{ $i }}" class="leave-level-panel">
                                                        <div class="leave-level-panel-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                                            <h4 class="leave-level-panel-title d-flex align-items-center gap-2">
                                                                <span class="leave-level-dot {{ !empty($level['enabled']) ? 'on' : '' }}"></span>
                                                                Level {{ $i + 1 }}
                                                            </h4>
                                                            <p class="leave-level-panel-sub mb-0">
                                                                @if($i === 0)
                                                                    Always on
                                                                @elseif(!empty($level['enabled']))
                                                                    Enabled
                                                                @else
                                                                    Disabled
                                                                @endif
                                                            </p>
                                                        </div>

                                                        <div class="mb-2">
                                                            <label class="form-label small text-uppercase text-muted fw-semibold">Approver Type</label>
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
                                                            <div class="mb-2 mt-4">
                                                                <label class="form-label small text-uppercase text-muted fw-semibold">Approvers</label>
                                                                @php
                                                                    $selectedUserIds = $level['approver_user_ids'] ?? [];
                                                                    $selectedUsers = collect($availableUsers)
                                                                        ->whereIn('id', $selectedUserIds)
                                                                        ->values();
                                                                    $availableToAdd = collect($availableUsers)
                                                                        ->reject(fn($u) => in_array($u['id'], $selectedUserIds, true))
                                                                        ->values();

                                                                    $searchTerm = trim($leaveApprovalUserSearch[$i] ?? '');
                                                                    if ($searchTerm !== '') {
                                                                        $availableToAdd = $availableToAdd
                                                                            ->filter(fn($u) => str_contains(strtolower($u['name']), strtolower($searchTerm))
                                                                                || str_contains(strtolower($u['email']), strtolower($searchTerm)))
                                                                            ->values();
                                                                    }
                                                                @endphp
                                                                <div class="d-flex flex-wrap gap-2 align-items-center mb-2 rounded-3 p-2" style="border:1px solid #cbd1d6; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06); background-color: #fff;">
                                                                    @foreach($selectedUsers as $userIndex => $user)
                                                                        <div wire:key="leave-approver-{{ $i }}-{{ $user['id'] }}" class="d-flex align-items-center gap-2 rounded-pill px-3 py-1" style="background-color: #F4E9E9; border:1px solid #cbd1d6;">
                                                                            <span class="fw-semibold" style="color: #A53437;">{{ $user['name'] }}</span>
                                                                            <button type="button"
                                                                                    class="btn btn-sm btn-outline-danger p-0 border-0"
                                                                                    style="width: 1.5rem; height: 1.2rem; line-height: 1.2rem;  color: #A53437;"
                                                                                    wire:click="removeLeaveApprovalUser({{ $i }}, {{ $user['id'] }})"
                                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                                &times;
                                                                            </button>
                                                                        </div>
                                                                    @endforeach
                                                                    <div class="dropdown leave-approval-user-dropdown" onclick="event.stopPropagation()">
                                                                        <button type="button"
                                                                                class="btn btn-sm text-dark"
                                                                                style="border:1px dashed #6c757d; border-radius:999px; background-color:transparent;"
                                                                                wire:click="toggleLeaveApprovalUserDropdown({{ $i }})"
                                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                            + Add approver
                                                                        </button>
                                                                        @if($leaveApprovalUserDropdownOpen[$i] ?? false)
                                                                            <div class="dropdown-menu show mt-2 p-2" style="position: absolute; z-index: 1000; min-width: 18rem; max-height: 20rem; overflow:auto;">
                                                                                <div class="mb-2">
                                                                                    <input type="text"
                                                                                           class="form-control form-control-sm"
                                                                                           placeholder="Search users..."
                                                                                           value="{{ $leaveApprovalUserSearch[$i] ?? '' }}"
                                                                                           wire:input="setLeaveApprovalUserSearch({{ $i }}, $event.target.value)">
                                                                                </div>
                                                                                @if($availableToAdd->isNotEmpty())
                                                                                    @foreach($availableToAdd as $availableUser)
                                                                                        <button type="button"
                                                                                                class="dropdown-item"
                                                                                                wire:click="addLeaveApprovalUser({{ $i }}, {{ $availableUser['id'] }})">
                                                                                            {{ $availableUser['name'] }}
                                                                                        </button>
                                                                                    @endforeach
                                                                                @else
                                                                                    <div class="dropdown-item text-muted">No users match your search.</div>
                                                                                @endif
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                                @if(empty($selectedUserIds))
                                                                    <div class="form-text text-muted">Select one or more users who can approve at this level.</div>
                                                                @endif

                                                                @if($selectedUsers->count() >= 2)
                                                                    <div class="align-items-center mb-2 mt-4 rounded-3 p-2" style="border:1px solid #cbd1d6; box-shadow: 0 6px 18px #D9B84A; background-color: #FAF7F0; color: #D9B84A;">

                                                                        <div class="mb-1">
                                                                            <label class="form-label small text-uppercase text-muted fw-semibold" style="color: #D9B84A;">Approver Rule</label>
                                                                            <div class="btn-group btn-group-sm w-100" role="group">
                                                                                <button type="button"
                                                                                        class="btn"
                                                                                        style="background-color: {{ $level['approver_rule'] === 'anyone_approve' ? '#D9B84A' : '#FFFFFF' }}; color: {{ $level['approver_rule'] === 'anyone_approve' ? '#FFFFFF' : '#D9B84A' }}; border: 1px solid {{ $level['approver_rule'] === 'anyone_approve' ? '#D9B84A' : '#cbd1d6' }}; font-weight: 700;"
                                                                                        wire:click="$set('leaveApproval.levels.{{ $i }}.approver_rule', 'anyone_approve')"
                                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                                    Anyone Approves
                                                                                </button>
                                                                                <button type="button"
                                                                                        class="btn"
                                                                                        style="background-color: {{ $level['approver_rule'] === 'all_approve' ? '#D9B84A' : '#FFFFFF' }}; color: {{ $level['approver_rule'] === 'all_approve' ? '#FFFFFF' : '#D9B84A' }}; border: 1px solid {{ $level['approver_rule'] === 'all_approve' ? '#D9B84A' : '#cbd1d6' }}; font-weight: 700;"
                                                                                        wire:click="$set('leaveApproval.levels.{{ $i }}.approver_rule', 'all_approve')"
                                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                                    All Must Approve
                                                                                </button>
                                                                            </div>
                                                                        </div>

                                                                        @if($level['approver_rule'] === 'all_approve')
                                                                            <div class="small p-1" style="color: #D9B84A;">
                                                                                Every one of the {{ count($level['approver_user_ids'])}} approvers must act before this level clears.
                                                                            </div>
                                                                        @else
                                                                            <div class="small p-1" style="color: #D9B84A;">
                                                                                Level clears as soon as one of the {{ count($level['approver_user_ids'])}} approvers acts.
                                                                            </div>
                                                                        @endif

                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <div class="mb-2 mt-4">

                                                                {{-- Keep Livewire model binding while rendering custom role cards. --}}
                                                                <select class="d-none"
                                                                        wire:model="leaveApproval.levels.{{ $i }}.approver_role"
                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                    <option value="direct_supervisor">Direct supervisor</option>
                                                                </select>

                                                                <div class="mt-2 rounded-3 p-3 d-flex align-items-center justify-content-between gap-3"
                                                                     style="border:1px solid #b9f2e2;background:#f3fffb;">
                                                                    <div class="d-flex align-items-start gap-3">
                                                                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                                                                             style="width:44px;height:44px;background:#c9f5ea;color:#18a07b;">
                                                                            @if($i === 0)
                                                                                <iconify-icon icon="mdi:account-outline" class="fs-5"></iconify-icon>
                                                                            @elseif($i === 1)
                                                                                <iconify-icon icon="mdi:account-multiple" class="fs-5"></iconify-icon>
                                                                            @elseif($i > 1)
                                                                                <iconify-icon icon="mdi:domain" class="fs-5"></iconify-icon>
                                                                            @endif
                                                                        </div>
                                                                        <div>
                                                                            @if($i === 0)
                                                                                <div class="fw-bold" style="color:#1f2d3d;">Direct supervisor</div>
                                                                                <div class="text-muted small">The employee's direct supervisor will approve this level.</div>
                                                                            @elseif($i === 1)
                                                                                <div class="fw-bold" style="color:#1f2d3d;">Supervisor's manager</div>
                                                                                <div class="text-muted small">The employee's supervisor's manager will approve this level.</div>
                                                                            @elseif($i > 1)
                                                                                <div class="fw-bold" style="color:#1f2d3d;">Department head</div>
                                                                                <div class="text-muted small">The department head or division director will approve this level.</div>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                    <iconify-icon icon="mdi:check" class="fs-5" style="color:#18a07b;"></iconify-icon>
                                                                </div>

                                                                <div class="mt-2 rounded-3 p-3 d-flex align-items-start gap-2"
                                                                     style="border:1px solid #e5e9ef;background:#f8fafc;color:#6b7280;">
                                                                    <iconify-icon icon="mdi:information-outline" class="fs-5 flex-shrink-0"></iconify-icon>
                                                                    @if($i === 0)
                                                                        <span class="small fw-semibold">Level {{ $i + 1 }} approver is automatically determined based on the employee's direct reporting line.</span>
                                                                    @elseif($i === 1)
                                                                        <span class="small fw-semibold">Level {{ $i + 1 }} approver is automatically determined based on the employee's reporting hierarchy.</span>
                                                                    @elseif($i > 1)
                                                                        <span class="small fw-semibold">Level {{ $i + 1 }} approver is automatically determined based on the employee's department hierarchy.</span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endif

                                                        <div wire:key="leave-approval-level-{{ $i }}-notifications" class="mb-2 mt-4">
                                                            <label class="form-label small text-uppercase text-muted fw-semibold d-flex align-items-center gap-1">
                                                                <iconify-icon icon="mdi:bell-outline"></iconify-icon>
                                                                CC on approval/rejection
                                                            </label>

                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input" type="checkbox" role="switch"
                                                                       wire:model="leaveApproval.levels.{{ $i }}.notify_email"
                                                                       wire:change="leaveApprovalNotifyEmailChanged({{ $i }})"
                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                            </div>

                                                            @if($level['notify_email'])
                                                                @php
                                                                    $selectedEmailAddresses = $level['notify_email_addresses'] ?? [];
                                                                    $selectedEmailUsers = collect($selectedEmailAddresses)
                                                                        ->map(fn($email) => [
                                                                            'email' => $email,
                                                                            'name' => collect($availableUsers)->firstWhere('email', $email)['name'] ?? $email,
                                                                        ])
                                                                        ->values();
                                                                    $availableEmailUsers = collect($availableUsers)
                                                                        ->reject(fn($u) => in_array($u['email'], $selectedEmailAddresses, true) || empty($u['email']))
                                                                        ->values();

                                                                    
                                                                    $availableEmailUsers = collect($availableUsers)
                                                                        ->reject(fn($u) => in_array($u['id'], $selectedUserIds, true))
                                                                        ->values();

                                                                    $emailSearchTerm = trim($leaveApprovalEmailSearch[$i] ?? '');
                                                                    if ($emailSearchTerm !== '') {
                                                                        $availableEmailUsers = $availableEmailUsers
                                                                            ->filter(fn($u) => str_contains(strtolower($u['name']), strtolower($emailSearchTerm))
                                                                                || str_contains(strtolower($u['email']), strtolower($emailSearchTerm)))
                                                                            ->values();
                                                                    }
                                                                @endphp

                                                                <div class="d-flex flex-wrap gap-2 align-items-center mt-3 mb-2 rounded-3 p-2" style="border:1px solid #cbd1d6; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06); background-color: #fff;">
                                                                    @foreach($selectedEmailUsers as $user)
                                                                        <div wire:key="leave-email-{{ $i }}-{{ $user['email'] }}" class="d-flex align-items-center gap-2 rounded-pill px-3 py-1" style="background-color: #F4E9E9; border:1px solid #cbd1d6;">
                                                                            <span class="fw-semibold" style="color: #A53437;">{{ $user['name'] }}</span>
                                                                            <button type="button"
                                                                                    class="btn btn-sm btn-outline-danger p-0 border-0"
                                                                                    style="width: 1.5rem; height: 1.2rem; line-height: 1.2rem; color: #A53437;"
                                                                                    wire:click="removeLeaveApprovalEmailAddress({{ $i }}, '{{ $user['email'] }}')"
                                                                                    {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                                &times;
                                                                            </button>
                                                                        </div>
                                                                    @endforeach

                                                                    <div class="dropdown leave-approval-email-dropdown" onclick="event.stopPropagation()">
                                                                        <button type="button"
                                                                                class="btn btn-sm text-dark"
                                                                                style="border:1px dashed #6c757d; border-radius:999px; background-color:transparent;"
                                                                                wire:click="toggleLeaveApprovalEmailDropdown({{ $i }})"
                                                                                {{ $level['enabled'] ? '' : 'disabled' }}>
                                                                            + Add users
                                                                        </button>
                                                                        @if($leaveApprovalEmailDropdownOpen[$i] ?? false)
                                                                            <div class="dropdown-menu show mt-2 p-2" style="position: absolute; z-index: 1000; min-width: 18rem; max-height: 20rem; overflow:auto;">
                                                                                <div class="mb-2">
                                                                                    <input type="text"
                                                                                           class="form-control form-control-sm"
                                                                                           placeholder="Search users..."
                                                                                           value="{{ $leaveApprovalEmailSearch[$i] ?? '' }}"
                                                                                           wire:input="setLeaveApprovalEmailSearch({{ $i }}, $event.target.value)">
                                                                                </div>
                                                                                @if($availableEmailUsers->isNotEmpty())
                                                                                    @foreach($availableEmailUsers as $availableUser)
                                                                                        <button type="button"
                                                                                                class="dropdown-item"
                                                                                                wire:click="addLeaveApprovalEmailUser({{ $i }}, {{ $availableUser['id'] }})">
                                                                                            {{ $availableUser['name'] }}
                                                                                        </button>
                                                                                    @endforeach
                                                                                @else
                                                                                    <div class="dropdown-item text-muted">No users match your search.</div>
                                                                                @endif
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>


                                    <div class="d-flex align-items-center justify-content-end gap-2 mt-4">
                                        <button wire:click="saveLeaveApprovalSettings"
                                                class="btn leave-red-btn d-inline-flex align-items-center">
                                            <iconify-icon icon="mdi:content-save-outline" class="me-1"></iconify-icon>
                                            <span>
                                                {{ $leaveApprovalScope === 'department'
                                                    ? 'Save for Selected Department'
                                                    : 'Save Organization Default' }}
                                            </span>
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
                                        <table class="table table-hover table-striped align-middle">
                                            
                                            <thead class = "table-header">
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Code</th>
                                                    <th>Icon</th>
                                                    <th class="text-end">Annual Entitlement (days)</th>
                                                    <th class="text-end">Weekends Inc</th>
                                                    <th class="text-end">Holidays Inc</th>
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

                                                    <!-- Rows for holidays and weekends inclusion -->
                                                    <td class="text-end">{{ $lt->weekends_included ? 'Yes' : 'No' }}</td>
                                                    <td class="text-end">{{ $lt->holidays_included ? 'Yes' : 'No' }}</td>

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
                                            <input type="number" step="0.5" min="0.5" max="365"
                                                   wire:model="ltAnnualEntitlementDays"
                                                   id="ltAnnualEntitlementDays" class="form-control"
                                                   placeholder="Leave blank for unlimited / not balance-tracked"/>
                                            <small class="text-muted">Leave blank for types that aren't
                                                balance-tracked (e.g. unpaid leave, off-shift).</small>
                                            @error('ltAnnualEntitlementDays') <small
                                                class="text-danger">{{ $message }}</small> @enderror
                                        </div>


                                        <!-- Include Weekends -->
                                        <div class="mb-3">
                                            <label for="ltIncludeWeekends" class="form-label">Include weekends</label>
                                            <select class="form-select" wire:model="ltIncludeWeekends" id="ltIncludeWeekends">
                                                <option value="1" selected>Yes</option>
                                                <option value="0" >No</option>
                                            </select>
                                            <small class="text-muted">Are weekends included in leave days?</small>
                                            @error('ltIncludeWeekends') <small class="text-danger">{{ $message }}</small>@enderror
                                        </div>

                                        <!-- Include Holidays -->
                                        <div class="mb-3">
                                            <label for="ltIncludeHolidays" class="form-label">Include holidays</label>
                                            <select class="form-select" wire:model="ltIncludeHolidays" id="ltIncludeHolidays">
                                                <option value="1" selected>Yes</option>
                                                <option value="0" >No</option>
                                            </select>
                                            <small class="text-muted">Are holidays included in leave days?</small>
                                            @error('ltIncludeHolidays') <small class="text-danger">{{ $message }}</small>@enderror
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

                <!-- Help & Support Settings Tab -->
                <div class="tab-pane fade {{ $activeTab === 'help_support' ? 'show active' : '' }}" id="tab-help-support">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-4">Help & Support Settings</h4>

                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="showHelpIcon"
                                               wire:model.defer="settings.show_help_icon">
                                        <label class="form-check-label" for="showHelpIcon">
                                            Show help icon in the top navigation bar
                                        </label>
                                    </div>

                                    <div class="mb-4">
                                        <label for="helpPageUrl" class="form-label fw-semibold">Help page URL</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ti ti-link"></i></span>
                                            <input type="url" class="form-control" id="helpPageUrl"
                                                   placeholder="https://help.example.com/..."
                                                   wire:model.defer="settings.help_page_url">
                                        </div>
                                        <small class="text-muted">Links to an externally hosted help site. Opens in a new tab.</small>
                                    </div>

                                    <div class="mb-4">
                                        <label for="helpTooltipLabel" class="form-label fw-semibold">Icon tooltip label</label>
                                        <input type="text" class="form-control" id="helpTooltipLabel"
                                               placeholder="Help"
                                               wire:model.defer="settings.help_icon_tooltip_label">
                                    </div>

                                    <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                        <button wire:click="storeSettings" class="btn btn-primary">Save</button>
                                    </div>

                                </div>
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
        window.addEventListener('show-leave-type-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveTypeModal')).show();
        });

        window.addEventListener('hide-leave-type-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveTypeModal'))?.hide();
        });

        document.addEventListener('click', function (event) {
            const target = event.target;
            const dropper = target.closest('.leave-approval-user-dropdown, .leave-approval-email-dropdown');
            if (!dropper) {
                const livewire = window.Livewire ?? window.livewire;
                livewire?.dispatch?.('closeLeaveApprovalUserDropdowns');
            }
        });

        document.addEventListener('livewire:init', () => {
            Livewire.on('uncheck-leave-level', ({ index }) => {
                const checkbox = document.getElementById(`leaveLevelNav${index}Enabled`);

                if (checkbox) {
                    checkbox.checked = false;
                }
            });
        });

    </script>
@endpush
