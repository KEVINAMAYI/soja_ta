<?php

use App\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $settings;
    public int $generateQrOnCreate = 1;
    public int $requireEmployeePhoto;
    public int $autoAssignEmployeeId;
    public int $requireEmployeeJobTitle;

    public function mount(): void
    {
        $org = auth()->user()->employee->organization;
        $saved = $org->settings()->where('key', 'generate_employee_qr_on_create')->value('value');
        $requireEmployeePhoto = $org->settings()->where('key', 'require_employee_photo')->value('value');
        $autoAssignEmployeeId = $org->settings()->where('key', 'auto_assign_employee_id')->value('value');
        $requireEmployeeJobTitle = $org->settings()->where('key', 'require_employee_job_title')->value('value');
        $this->generateQrOnCreate = (int)($saved ?? 1);
        $this->requireEmployeePhoto = (int)($requireEmployeePhoto ?? 1);
        $this->autoAssignEmployeeId = (int)($autoAssignEmployeeId ?? 1);
        $this->requireEmployeeJobTitle = (int)($requireEmployeeJobTitle ?? 0);

        $this->settings = $org->settings->mapWithKeys(function ($item) {
            $value = $item->type === 'json'
                ? (is_array($item->value) ? $item->value : json_decode($item->value, true))
                : $item->value;
            return [$item->key => $value];
        })->toArray();

        $this->settings['auto_deactivate_enabled'] = isset($this->settings['auto_deactivate_enabled'])
            ? (bool)$this->settings['auto_deactivate_enabled']
            : false;
        $this->settings['auto_deactivate_after_value'] = isset($this->settings['auto_deactivate_after_value'])
            ? (int)$this->settings['auto_deactivate_after_value']
            : null;
        $this->settings['auto_deactivate_after_unit'] = $this->settings['auto_deactivate_after_unit'] ?? 'months';

    }

    public function saveQrCodeSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'generate_employee_qr_on_create';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->generateQrOnCreate = $value;

        LivewireAlert::title('Success!')
            ->text('QR code generation setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveEmployeePhotoSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'require_employee_photo';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->requireEmployeePhoto = $value;

        LivewireAlert::title('Success!')
            ->text('Employee photo requirement setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveAutoAssignEmployeeIdSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'auto_assign_employee_id';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->autoAssignEmployeeId = $value;

        LivewireAlert::title('Success!')
            ->text('Auto-assign employee ID setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveRequireEmployeeJobTitleSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'require_employee_job_title';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->requireEmployeeJobTitle = $value;

        LivewireAlert::title('Success!')
            ->text('Require employee job title setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
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

                $setting->type = match (true) {
                    in_array($key, ['generate_employee_qr_on_create', 'show_help_icon', 'auto_deactivate_enabled']) => 'boolean',
                    in_array($key, ['auto_deactivate_after_value']) => 'integer',
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
};

?>

@push('styles')
    <style>

        @media (max-width: 768px) {
            
        }

        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

        .auto-deactivate-duration {
            max-width: 360px;
            border: 1px solid #d9dee8;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .auto-deactivate-duration .auto-deactivate-value,
        .auto-deactivate-duration .auto-deactivate-unit {
            height: 52px;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            color: #172033 !important;
            font-size: 0.95rem !important;
            background-color: transparent !important;
        }

        .auto-deactivate-duration .auto-deactivate-value {
            padding-left: 1.15rem !important;
        }

        .auto-deactivate-duration .auto-deactivate-unit {
            max-width: 150px;
            border-left: 1px solid #e2e7ef !important;
            padding-left: 1.05rem !important;
            font-weight: 600 !important;
            appearance: auto;
        }

        .auto-deactivate-duration:focus-within {
            border-color: #c92a2f;
            box-shadow: 0 0 0 3px rgba(201, 42, 47, 0.12), 0 12px 28px rgba(15, 23, 42, 0.08);
        }

        @media (max-width: 575.98px) {
            .auto-deactivate-duration {
                max-width: 100%;
            }

            .auto-deactivate-duration .auto-deactivate-unit {
                max-width: 132px;
            }
        }
    </style>
@endpush


<div class="row g-3">
    <div class="col-12">
        <div class="dept-shell">

            <h3 class="dept-defaults-title">New employee defaults</h3>
            <p class="dept-defaults-sub">Applied automatically when an employee record is created.</p>

            <div class="dept-setting-row">
                <div>
                    <p class="dept-setting-label fs-3 fw-3">Generate QR code on creation</p>
                    <p class="fs-2 mt-2">Issues a QR code for the employee at the time their record is added.</p>
                </div>
                <button type="button"
                        class="dept-toggle {{ $generateQrOnCreate ? 'is-on' : 'is-off' }}"
                        wire:click="saveQrCodeSetting({{ $generateQrOnCreate ? 0 : 1 }})"
                        aria-label="Generate QR code on creation"></button>
            </div>

            <div class="dept-setting-row">
                <div>
                    <p class="dept-setting-label fs-3 fw-3">Require employee photo</p>
                    <p class="fs-2 mt-2">Blocks saving a new employee until a photo is uploaded.</p>
                </div>

                <button type="button"
                        class="dept-toggle {{ $requireEmployeePhoto ? 'is-on' : 'is-off' }}"
                        wire:click="saveEmployeePhotoSetting({{ $requireEmployeePhoto ? 0 : 1 }})"
                        aria-label="Require employee photo"></button>
                <!-- <button type="button" class="dept-toggle is-off" aria-label="Require employee photo"></button> -->
            </div>


            <div class="dept-setting-row">
                <div>
                    <p class="dept-setting-label fs-3 fw-3">Require Job Title</p>
                    <p class="fs-2 mt-2">Blocks saving a new employee until a job title is specified.</p>
                </div>

                <button type="button"
                        class="dept-toggle {{ $requireEmployeeJobTitle ? 'is-on' : 'is-off' }}"
                        wire:click="saveRequireEmployeeJobTitleSetting({{ $requireEmployeeJobTitle ? 0 : 1 }})"
                        aria-label="Require job title"></button>
                <!-- <button type="button" class="dept-toggle is-on" aria-label="Require job title"></button> -->
            </div>

            <div class="dept-setting-row">
                <div>
                    <p class="dept-setting-label fs-3 fw-3">Auto-assign employee ID</p>
                    <p class="fs-2 mt-2">Generates a sequential ID instead of requiring manual entry.</p>
                </div>

                <button type="button"
                        class="dept-toggle {{ $autoAssignEmployeeId ? 'is-on' : 'is-off' }}"
                        wire:click="saveAutoAssignEmployeeIdSetting({{ $autoAssignEmployeeId ? 0 : 1 }})"
                        aria-label="Auto-assign employee ID"></button>
                <!-- <button type="button" class="dept-toggle is-on" aria-label="Auto-assign employee ID"></button> -->
            </div>

        </div>
        <div class="card border shadow-none mt-3">
            <div class="card-body p-4">
                <h4 class="card-title mb-1">Auto-Deactivation Policy</h4>
                <p class="text-muted small mb-4">
                    Automatically deactivate employees a set amount of time after they were
                    created. Runs once a day. Off by default - nothing changes until you turn
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
                    <div class="input-group auto-deactivate-duration">
                        <input type="number" min="1" class="form-control auto-deactivate-value"
                                placeholder="e.g. 6"
                                wire:model.defer="settings.auto_deactivate_after_value">
                        <select class="form-control auto-deactivate-unit"
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

                <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                    <button wire:click="storeSettings" class="btn btn-primary">Save</button>
                </div>

            </div>
        </div>
    </div>




</div>

@push('scripts')
    <script>
        
    </script>
@endpush
