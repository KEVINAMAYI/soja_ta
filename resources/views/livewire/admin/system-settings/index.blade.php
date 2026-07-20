<?php

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;
    public string $activeTab = 'qr_code'; // default
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

        // Help & Support settings
        $this->settings['show_help_icon'] = isset($this->settings['show_help_icon'])
            ? (bool)$this->settings['show_help_icon']
            : false;
        $this->settings['help_page_url'] = $this->settings['help_page_url'] ?? '';
        $this->settings['help_icon_tooltip_label'] = $this->settings['help_icon_tooltip_label'] ?? 'Help';

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
        DB::beginTransaction();

        try {
            $orgId = auth()->user()->employee?->organization_id;

            foreach ($this->settings as $key => $value) {
                $setting = OrganizationSetting::firstOrNew([
                    'organization_id' => $orgId,
                    'key' => $key
                ]);

                // We want to treat the new setting as boolean type
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


    public function changeSystemSettingsBreadcrumb()
    {

        switch ($this->activeTab) {
            case 'qr_code':
                $this->tabTitle = 'QR Code Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:qrcode-scan" class="fs-5"></iconify-icon>';
                break;

            case 'shift_management': // Add this
                $this->tabTitle = 'Shift Management';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock-outline" class="fs-5"></iconify-icon>';
                break;
            case 'help_support':
                $this->tabTitle = 'Help & Support';
                $this->tabIcon = '<iconify-icon icon="mdi:help-circle-outline" class="fs-5"></iconify-icon>';
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


        </ul>

        <div class="card-body">
            <div class="tab-content" id="pills-tabContent">

                <!-- QR Code Settings Tab -->
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
                        case 'tab-qr-code-tab':
                            mappedTab = 'qr_code';
                            break;
                        case 'tab-shift-management-tab':
                            mappedTab = 'shift_management';
                            break;
                        case 'tab-help-support-tab':
                            mappedTab = 'help_support';
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





