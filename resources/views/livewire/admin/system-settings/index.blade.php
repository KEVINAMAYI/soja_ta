<?php

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {


    public $settings;

    public function mount()
    {

        $orgId = auth()->user()->employee?->organization_id;
        $org = Organization::find($orgId);

        $this->settings = $org->settings->mapWithKeys(function ($item) {
            $value = $item->type === 'json' ? json_decode($item->value, true) : $item->value;
            return [$item->key => $value];
        })->toArray();


    }


    public function storeSettings()
    {


        $orgId = auth()->user()->employee?->organization_id;
        $org = Organization::find($orgId);

        foreach ($this->settings as $key => $value) {
            $org->setSetting(
                $key,
                is_array($value) ? json_encode($value) : $value,
                gettype($value)
            );
        }

        LivewireAlert::title('Awesome!')
            ->text('Organization settings updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

    }


    #[On('set-end-time')]
    public function calculateDailyHours(): void
    {

        $start = $this->settings['start_time'];
        $end = $this->settings['end_time'];

        if ($start && $end) {
            try {

                $startTime = Carbon::createFromFormat('H:i', $start);
                $endTime = Carbon::createFromFormat('H:i', $end);

                // Handle overnight shifts
                if ($endTime->lessThanOrEqualTo($startTime)) {
                    $endTime->addDay();
                }

                $hours = $startTime->diffInMinutes($endTime) / 60;

                $this->settings['daily_required_hours'] = round($hours, 2);

            } catch (\Exception $e) {
                // Optional error handling
                $this->settings['daily_required_hours'] = null;
            }
        }
    }


}; ?>

<div>

    <div class="container-fluid">
        <div class="card card-body py-3">
            <div class="row align-items-center">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-space-between">
                        <h4 class="mb-4 mb-sm-0 card-title">System Settings</h4>
                        <nav aria-label="breadcrumb" class="ms-auto">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item d-flex align-items-center">
                                    <a class="text-muted text-decoration-none d-flex" href="../main/index.html">
                                        <iconify-icon icon="solar:home-2-line-duotone" class="fs-6"></iconify-icon>
                                    </a>
                                </li>
                                <li class="breadcrumb-item" aria-current="page">
                        <span class="badge fw-medium fs-2 bg-primary-subtle text-primary">
                           System Settings
                        </span>
                                </li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">

                <!-- Overtime -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 active d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
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

                <!-- Notifications -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
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

            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade show active"
                         id="tab-overtime-policy"
                         role="tabpanel"
                         aria-labelledby="tab-overtime-policy-tab"
                         tabindex="0">

                        {{-- Livewire Table --}}
                        <livewire:admin.shifts.index/>

                    </div>

                    <!-- Notifications Tab -->
                    <div class="tab-pane fade"
                         id="tab-notifications"
                         role="tabpanel"
                         aria-labelledby="tab-notifications-tab"
                         tabindex="0">

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
                </div>
            </div>
        </div>

    </div>

</div>




