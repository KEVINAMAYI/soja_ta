<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public int $generateQrOnCreate = 1;
    public int $requireEmployeePhoto;
    public int $autoAssignEmployeeId;
    public int $requireEmployeeJobTitle = 0;

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
        $this->requireEmployeeJobTitle = (int)($requireEmployeeJobTitle ?? 1);
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
    </div>




</div>

@push('scripts')
    <script>
        
    </script>
@endpush
