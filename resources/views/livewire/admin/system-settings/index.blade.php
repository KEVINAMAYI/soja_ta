<?php

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;
    public string $activeTab = 'shifts'; // default
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
        $this->changeBreadcrumb();

    }

    #[On('tabChanged')]
    public function tabChanged($tabId)
    {
        $this->activeTab = $tabId;
        $this->changeBreadcrumb();
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


    public function changeBreadcrumb()
    {
        switch ($this->activeTab) {
            case 'notifications':
                $this->tabTitle = 'Notification';
                $this->tabIcon = '<iconify-icon icon="mdi:bell-outline" class="fs-5"></iconify-icon>';
                break;

            case 'devices':
                $this->tabTitle = 'Devices';
                $this->tabIcon = '<iconify-icon icon="mdi:tablet-dashboard" class="fs-5"></iconify-icon>';
                break;

            case 'qr_code':
                $this->tabTitle = 'QR Code Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:qrcode-scan" class="fs-5"></iconify-icon>';
                break;

            default: // shifts or fallback
                $this->tabTitle = 'Shift';
                $this->tabIcon = '<iconify-icon icon="mdi:calendar-clock" class="fs-5"></iconify-icon>';
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
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'shifts' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-overtime-policy-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-overtime-policy"
                    type="button"
                    role="tab"
                    aria-controls="tab-overtime-policy"
                    aria-selected="false">
                    <i class="ti ti-building-factory me-2 fs-6"></i>
                    <span class="d-none d-md-block">Shifts</span>
                </button>
            </li>

            <!-- Devices Tab -->
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'devices' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-devices-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-devices"
                    type="button"
                    role="tab"
                    aria-controls="tab-devices"
                    aria-selected="false">
                    <i class="ti ti-device-laptop me-2 fs-6"></i>
                    <span class="d-none d-md-block">Devices</span>
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link position-relative rounded-0 {{ $activeTab === 'notifications' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                    id="tab-notifications-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-notifications"
                    type="button"
                    role="tab"
                    aria-controls="tab-notifications"
                    aria-selected="false">
                    <i class="ti ti-bell me-2 fs-6"></i>
                    <span class="d-none d-md-block">Notifications</span>
                </button>
            </li>

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


        </ul>

        <div class="card-body">
            <div class="tab-content" id="pills-tabContent">

                <!-- Overtime Policy Tab -->
                <div class="tab-pane fade {{ $activeTab === 'shifts' ? 'show active' : '' }}"
                     id="tab-shifts">


                    {{-- Livewire Table --}}
                    <livewire:admin.shifts.index theme="bootstrap-4"/>

                </div>

                <!-- Overtime Policy Tab -->
                <div class="tab-pane fade {{ $activeTab === 'devices' ? 'show active' : '' }}"
                     id="tab-devices">


                    {{-- Livewire Table --}}
                    <livewire:admin.devices.index theme="bootstrap-4"/>

                </div>

                <!-- Notifications Tab -->
                <div class="tab-pane fade {{ $activeTab === 'notifications' ? 'show active' : '' }}"
                     id="tab-notifications">

                    <div class="row justify-content-center">
                        <div class="col-lg-9">
                            <div class="card border shadow-none">
                                <div class="card-body p-4">
                                    <h4 class="card-title mb-4">Event Notification Settings</h4>
                                    <p class="card-subtitle mb-4">Manage who gets notified when specific events
                                        occur.</p>

                                    <!-- Notification Table -->
                                    <div class="table-responsive">
                                        <table class="table align-middle mb-0">
                                            <thead class="table-light">
                                            <tr>
                                                <th scope="col">Event</th>
                                                <th scope="col" class="text-center">Notify Employee</th>
                                                <th scope="col" class="text-center">Notify Admin</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <!-- Late Check-in -->
                                            <tr>
                                                <td><strong>Late Check-in</strong></td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="lateCheckinEmployee"
                                                               wire:model="settings.late_checkin_employee">
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="lateCheckinAdmin"
                                                               wire:model="settings.late_checkin_admin">
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- OT Approval Needed -->
                                            <tr>
                                                <td><strong>OT Approval Needed</strong></td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="otApprovalEmployee"
                                                               wire:model="settings.ot_approval_employee">
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="otApprovalAdmin"
                                                               wire:model="settings.ot_approval_admin">
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Missing Check-out Detected -->
                                            <tr>
                                                <td><strong>Missing Check-out Detected</strong></td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="missingCheckoutEmployee"
                                                               wire:model="settings.missing_checkout_employee">
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input class="form-check-input" type="checkbox"
                                                               role="switch" id="missingCheckoutAdmin"
                                                               wire:model="settings.missing_checkout_admin">
                                                    </div>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Save/Cancel Buttons -->
                                    <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                        <button wire:click="storeSettings" class="btn btn-primary">Save</button>
                                        <button wire:click="resetSettings" class="btn bg-danger-subtle text-danger">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


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

            </div>
        </div>
    </div>

</div>



@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    // Map Bootstrap tab IDs to your internal tab names
                    let mappedTab;
                    switch (tabId) {
                        case 'tab-overtime-policy-tab':
                            mappedTab = 'shifts';
                            break;
                        case 'tab-devices-tab':
                            mappedTab = 'devices';
                            break;
                        case 'tab-notifications-tab':
                            mappedTab = 'notifications';
                            break;
                        case 'tab-qr-code-tab':          // <-- add this
                            mappedTab = 'qr_code';
                            break;
                        default:
                            mappedTab = 'shifts';
                    }

                    Livewire.dispatch('tabChanged', {tabId: mappedTab});

                });
            });
        });
    </script>
@endpush





