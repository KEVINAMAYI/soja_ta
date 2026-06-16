<?php

use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Services\CheckInApprovalSettings;
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
                        default:
                            mappedTab = 'qr_code';
                    }

                    Livewire.dispatch('systemSettingsTabChanged', {tabId: mappedTab});
                });
            });
        });
    </script>
@endpush
