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
            $value = $item->type === 'json' ? json_decode($item->value, true) : $item->value;
            return [$item->key => $value];
        })->toArray();

        // Make sure boolean value is cast properly for your new setting
        $this->settings['generate_employee_qr_on_create'] = isset($this->settings['generate_employee_qr_on_create'])
            ? (bool)$this->settings['generate_employee_qr_on_create']
            : false;

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
                    <span class="d-none d-md-block">Shift Management</span>
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
                        default:
                            mappedTab = 'qr_code';
                    }

                    Livewire.dispatch('systemSettingsTabChanged', {tabId: mappedTab});
                });
            });
        });
    </script>
@endpush





