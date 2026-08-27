<?php

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;
    public string $activeTab = 'employee_lifecycle'; // default
    public string $tabTitle = ''; // ✅ initialize
    public string $tabIcon = '';  // ✅ initialize
    public array $breadcrumbItems = [];

    public function mount()
    {

        $orgId = auth()->user()->employee?->organization_id;
        $org = Organization::find($orgId);

        $this->settings = $org->settings->mapWithKeys(function ($item) {
            return [$item->key => $item->value];
        })->toArray();

        // Make sure boolean value is cast properly for your new setting
        $this->settings['generate_employee_qr_on_create'] = isset($this->settings['generate_employee_qr_on_create'])
            ? (bool)$this->settings['generate_employee_qr_on_create']
            : false;

        // Auto-deactivation policy — off by default; admin must opt in and set a duration.
        $this->settings['auto_deactivate_enabled'] = isset($this->settings['auto_deactivate_enabled'])
            ? (bool)$this->settings['auto_deactivate_enabled']
            : false;
        $this->settings['auto_deactivate_after_value'] = isset($this->settings['auto_deactivate_after_value'])
            ? (int)$this->settings['auto_deactivate_after_value']
            : null;
        $this->settings['auto_deactivate_after_unit'] = $this->settings['auto_deactivate_after_unit'] ?? 'months';

        // Help & Support settings
        $this->settings['show_help_icon'] = isset($this->settings['show_help_icon'])
            ? (bool)$this->settings['show_help_icon']
            : false;
        $this->settings['help_page_url'] = $this->settings['help_page_url'] ?? '';
        $this->settings['help_icon_tooltip_label'] = $this->settings['help_icon_tooltip_label'] ?? 'Help';

        // Employee Defaults settings
        $this->settings['deleted_record_retention_days'] = isset($this->settings['deleted_record_retention_days'])
            ? (int)$this->settings['deleted_record_retention_days']
            : 90;

        // ✅ Initialize breadcrumb/title/icon
        $this->changeSystemSettingsBreadcrumb();

    }

    #[On('systemSettingsTabChanged')]
    public function systemSettingsTabChanged($tabId)
    {
        $this->activeTab = $tabId;
        $this->changeSystemSettingsBreadcrumb();
    }


    public function storeSettings()
    {
        if (!empty($this->settings['auto_deactivate_enabled']) && empty($this->settings['auto_deactivate_after_value'])) {
            LivewireAlert::title('Missing duration')
                ->text('Set how many days/months before employees are auto-deactivated, or turn the policy off.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
            return;
        }

        DB::beginTransaction();

        try {
            $orgId = auth()->user()->employee?->organization_id;

            foreach ($this->settings as $key => $value) {
                $setting = OrganizationSetting::firstOrNew([
                    'organization_id' => $orgId,
                    'key' => $key
                ]);

                // We want to treat the new setting as boolean type
                $setting->type = match (true) {
                    in_array($key, ['generate_employee_qr_on_create', 'show_help_icon', 'auto_deactivate_enabled']) => 'boolean',
                    in_array($key, ['deleted_record_retention_days', 'auto_deactivate_after_value']) => 'integer',
                    default => $setting->type ?? 'string',
                };

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


    public function changeSystemSettingsBreadcrumb()
    {

        switch ($this->activeTab) {
            case 'employee_lifecycle':
                $this->tabTitle = 'Employee Lifecycle';
                $this->tabIcon = '<iconify-icon icon="mdi:account-clock-outline" class="fs-5"></iconify-icon>';
                break;

            case 'shift_management': // Add this
                $this->tabTitle = 'Shift Management';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock-outline" class="fs-5"></iconify-icon>';
                break;
            case 'help_support':
                $this->tabTitle = 'Help & Support';
                $this->tabIcon = '<iconify-icon icon="mdi:help-circle-outline" class="fs-5"></iconify-icon>';
                break;

            case 'employee_defaults':
                $this->tabTitle = 'Employee Defaults';
                $this->tabIcon = '<iconify-icon icon="mdi:account-cog-outline" class="fs-5"></iconify-icon>';
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
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'employee_lifecycle' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-employee-lifecycle-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-employee-lifecycle"
                    type="button"
                    role="tab"
                    aria-controls="tab-employee-lifecycle"
                    aria-selected="false">
                    <i class="ti ti-user-check me-2 fs-6"></i>
                    <span class="d-none d-md-block">Employee Lifecycle</span>
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
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'help_support' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-help-support-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-help-support"
                    type="button"
                    role="tab"
                    aria-controls="tab-help-support"
                    aria-selected="false">
                    <i class="ti ti-help-circle me-2 fs-6"></i>
                    <span class="d-none d-md-block">Help & Support</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'employee_defaults' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-employee-defaults-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-employee-defaults"
                    type="button"
                    role="tab"
                    aria-controls="tab-employee-defaults"
                    aria-selected="false">
                    <i class="ti ti-user-cog me-2 fs-6"></i>
                    <span class="d-none d-md-block">Employee Defaults</span>
                </button>
            </li>

        </ul>

        <div class="card-body">
            <div class="tab-content" id="pills-tabContent">

                <!-- Employee Lifecycle Settings Tab -->
                <div class="tab-pane fade {{ $activeTab === 'employee_lifecycle' ? 'show active' : '' }}" id="tab-employee-lifecycle">

                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-4">QR Code</h4>

                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="generateQrOnCreate"
                                               wire:model.defer="settings.generate_employee_qr_on_create">
                                        <label class="form-check-label" for="generateQrOnCreate">
                                            Generate QR code when adding a new employee
                                        </label>
                                    </div>

                                </div>
                            </div>

                            <div class="card border shadow-none mt-3">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-1">Auto-Deactivation Policy</h4>
                                    <p class="text-muted small mb-4">
                                        Automatically deactivate employees a set amount of time after they were
                                        created. Runs once a day. Off by default — nothing changes until you turn
                                        this on and set a duration.
                                    </p>

                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="autoDeactivateEnabled"
                                               wire:model.defer="settings.auto_deactivate_enabled">
                                        <label class="form-check-label" for="autoDeactivateEnabled">
                                            Auto-deactivate employees after a set time
                                        </label>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-semibold">Deactivate after</label>
                                        <div class="input-group" style="max-width:320px;">
                                            <input type="number" min="1" class="form-control"
                                                   placeholder="e.g. 6"
                                                   wire:model.defer="settings.auto_deactivate_after_value">
                                            <select class="form-control" style="max-width:140px;"
                                                    wire:model.defer="settings.auto_deactivate_after_unit">
                                                <option value="days">Days</option>
                                                <option value="months">Months</option>
                                            </select>
                                        </div>
                                        <small class="text-muted">
                                            Measured from the employee's creation date. Applies to every active
                                            employee in the organization.
                                        </small>
                                    </div>

                                    <!-- Save/Cancel Buttons -->
                                    <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                        <button wire:click="storeSettings" class="btn btn-primary">Save</button>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- QR Code Settings Tab -->
                <div class="tab-pane fade {{ $activeTab === 'shift_management' ? 'show active' : '' }}"
                     id="tab-shift-management">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <livewire:admin.shifts.create/>
                        </div>
                    </div>
                </div>


                <!-- Help & Support Settings Tab -->
                <div class="tab-pane fade {{ $activeTab === 'help_support' ? 'show active' : '' }}"
                     id="tab-help-support">
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
                                        <small class="text-muted">Links to an externally hosted help site. Opens in a
                                            new tab.</small>
                                    </div>

                                    <div class="mb-4">
                                        <label for="helpTooltipLabel" class="form-label fw-semibold">Icon tooltip
                                            label</label>
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

                <!-- Employee Defaults Settings Tab -->
                <div class="tab-pane fade {{ $activeTab === 'employee_defaults' ? 'show active' : '' }}"
                     id="tab-employee-defaults">
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-4">Employee Defaults</h4>

                                    <div class="mb-4">
                                        <label for="retentionDays" class="form-label fw-semibold">
                                            Deleted record retention policy
                                        </label>
                                        <div class="input-group" style="max-width:220px;">
                                            <input type="number" min="0" class="form-control" id="retentionDays"
                                                   wire:model.defer="settings.deleted_record_retention_days">
                                            <span class="input-group-text">Days</span>
                                        </div>
                                        <small class="text-muted">
                                            Defines how long a deactivated employee record remains visible in the
                                            app (e.g. under Inactive filters) before it's automatically filtered out
                                            of default views. Setting 0 keeps deactivated staff visible
                                            indefinitely.
                                        </small>
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
        document.addEventListener('DOMContentLoaded', function () {
            // ✅ FIXED: Select tabs from #pills-tab, not #pills-tabContent
            const tabs = document.querySelectorAll('#pills-tab button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    let mappedTab;
                    switch (tabId) {
                        case 'tab-employee-lifecycle-tab':
                            mappedTab = 'employee_lifecycle';
                            break;
                        case 'tab-shift-management-tab':
                            mappedTab = 'shift_management';
                            break;
                        case 'tab-help-support-tab':
                            mappedTab = 'help_support';
                            break;
                        case 'tab-employee-defaults-tab':
                            mappedTab = 'employee_defaults';
                            break;
                        default:
                            mappedTab = 'employee_lifecycle';
                    }

                    Livewire.dispatch('systemSettingsTabChanged', {tabId: mappedTab});
                });
            });
        });
    </script>
@endpush





