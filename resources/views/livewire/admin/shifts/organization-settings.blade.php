<?php

use App\Models\OrganizationShiftSetting;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Volt\Component;

new class extends Component {

    public $settings;

    public function mount()
    {
        $organizationId = auth()->user()->employee->organization_id;
        $this->settings = OrganizationShiftSetting::getForOrganization($organizationId);
    }

    public function toggleAutoDetection()
    {
        $this->settings->allow_auto_shift_detection = !$this->settings->allow_auto_shift_detection;
        $this->settings->save();
    }

    public function toggleManualSelection()
    {
        $this->settings->allow_manual_shift_selection = !$this->settings->allow_manual_shift_selection;
        $this->settings->save();
    }

    public function toggleApprovalRequired()
    {
        $this->settings->require_approval_for_manual_shift_change = !$this->settings->require_approval_for_manual_shift_change;
        $this->settings->save();
    }

    public function toggleNotifyManagers()
    {
        $this->settings->notify_managers = !$this->settings->notify_managers;
        $this->settings->save();
    }

    public function toggleMobileNotifications()
    {
        $this->settings->mobile_notifications = !$this->settings->mobile_notifications;
        $this->settings->save();
    }

    public function toggleEmailSummaries()
    {
        $this->settings->email_summaries = !$this->settings->email_summaries;
        $this->settings->save();
    }

    public function saveSettings()
    {
        $this->validate([
            'settings.shift_change_cooldown_minutes' => 'required|integer|min:0|max:1440',
            'settings.auto_detection_minimum_score' => 'required|integer|min:0|max:100',
        ]);

        try {
            $this->settings->save();

            LivewireAlert::title('Success!')
                ->text('Organization shift settings saved successfully!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to save settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

}; ?>

@push('styles')
    <style>
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #6c757d;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #0d6efd;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }

        .config-section {
            background-color: #ffffff;
            border-radius: 0.5rem;
            border: 1px solid #dee2e6;
        }

        .section-header {
            padding: 1rem 1.5rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 0.5rem 0.5rem 0 0;
        }

        .section-body {
            padding: 1.5rem;
            background-color: #ffffff;
        }

        .page-container {
            background-color: #ffffff;
            min-height: 100vh;
        }
    </style>
@endpush


<div class="page-container">
    <div class="container-fluid py-4">
        <div class="card shadow-sm border-0">
            <div class="card-header text-primary">
                <h4 class="mb-0">
                    Organization Shift Settings
                </h4>
                <p class="mb-0 small">Configure multi-shift and auto-detection settings for your organization</p>
            </div>

            <div class="card-body bg-white">

                <!-- Auto Detection Section -->
                <div class="config-section mb-4">
                    <div class="section-header">
                        <h5 class="mb-0">
                            <svg width="20" height="20" class="text-primary me-2" fill="currentColor"
                                 viewBox="0 0 24 24">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                <path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>
                            </svg>
                            Automatic Shift Detection
                        </h5>
                    </div>
                    <div class="section-body">

                        <!-- Main Toggle -->
                        <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                            <div>
                                <h6 class="mb-1">Enable Auto Shift Detection</h6>
                                <p class="text-muted small mb-0">
                                    Allow system to automatically detect which shift an employee should be checked into
                                    based on time and shift patterns
                                </p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox"
                                       wire:click="toggleAutoDetection"
                                    {{ $settings->allow_auto_shift_detection ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        @if($settings->allow_auto_shift_detection)
                            <!-- Detection Settings -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Minimum Detection Score</label>
                                    <input type="number"
                                           wire:model.defer="settings.auto_detection_minimum_score"
                                           class="form-control"
                                           min="0"
                                           max="100">
                                    <small class="text-muted">
                                        Minimum score required for auto-detection to succeed (0-100). Default: 40
                                    </small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Shift Change Cooldown</label>
                                    <div class="input-group">
                                        <input type="number"
                                               wire:model.defer="settings.shift_change_cooldown_minutes"
                                               class="form-control"
                                               min="0"
                                               max="1440">
                                        <span class="input-group-text">minutes</span>
                                    </div>
                                    <small class="text-muted">
                                        Time required between shift changes. Default: 240 minutes (4 hours)
                                    </small>
                                </div>
                            </div>

                            <!-- Info Alert -->
                            <div class="alert alert-info mt-3 mb-0">
                                <h6 class="mb-2">
                                    <svg width="16" height="16" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor"
                                                stroke-width="2"/>
                                        <line x1="12" y1="16" x2="12" y2="12" stroke="currentColor" stroke-width="2"/>
                                        <line x1="12" y1="8" x2="12.01" y2="8" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                    How Auto Detection Works
                                </h6>
                                <ul class="small mb-0">
                                    <li>System scores each shift based on: day pattern match, time proximity, grace
                                        period, and priority
                                    </li>
                                    <li>The shift with the highest score above the minimum threshold is selected</li>
                                    <li>If no shift meets the minimum score, detection fails and employee must select
                                        manually (if enabled)
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Manual Selection Section -->
                <div class="config-section mb-4">
                    <div class="section-header">
                        <h5 class="mb-0">
                            <svg width="20" height="20" class="text-success me-2" fill="currentColor"
                                 viewBox="0 0 24 24">
                                <path d="M9 11l3 3L22 4"/>
                                <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                            </svg>
                            Manual Shift Selection
                        </h5>
                    </div>
                    <div class="section-body">

                        <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                            <div>
                                <h6 class="mb-1">Allow Manual Shift Selection</h6>
                                <p class="text-muted small mb-0">
                                    Employees can manually select their shift during check-in if auto-detection fails or
                                    is disabled
                                </p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox"
                                       wire:click="toggleManualSelection"
                                    {{ $settings->allow_manual_shift_selection ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        @if($settings->allow_manual_shift_selection)
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">Require Manager Approval</h6>
                                    <p class="text-muted small mb-0">
                                        Manual shift changes require manager approval before taking effect
                                    </p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox"
                                           wire:click="toggleApprovalRequired"
                                        {{ $settings->require_approval_for_manual_shift_change ? 'checked' : '' }}>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Notifications Section -->
                <div class="config-section mb-4">
                    <div class="section-header">
                        <h5 class="mb-0">
                            <svg width="20" height="20" class="text-warning me-2" fill="currentColor"
                                 viewBox="0 0 24 24">
                                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 01-3.46 0"/>
                            </svg>
                            Notifications
                        </h5>
                    </div>

                    <div class="section-body">

                        <!-- Notify Managers -->
                        <div class="d-flex justify-content-between align-items-start mb-3 pb-3 border-bottom">
                            <div class="d-flex align-items-start">
                                <svg width="18" height="18" class="text-primary me-2 mt-1 flex-shrink-0" fill="none"
                                     stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                                    <path d="M17 7a4 4 0 010 7.87"/>
                                </svg>
                                <div>
                                    <h6 class="mb-1">Notify Managers on Overtime</h6>
                                    <p class="text-muted small mb-0">Send alerts when employees exceed standard hours</p>
                                </div>
                            </div>
                            <label class="toggle-switch flex-shrink-0 ms-3">
                                <input type="checkbox"
                                       wire:click="toggleNotifyManagers"
                                    {{ $settings->notify_managers ?? false ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <!-- Mobile Notifications -->
                        <div class="d-flex justify-content-between align-items-start mb-3 pb-3 border-bottom">
                            <div class="d-flex align-items-start">
                                <svg width="18" height="18" class="text-info me-2 mt-1 flex-shrink-0" fill="none"
                                     stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <rect x="7" y="2" width="10" height="20" rx="2" ry="2"/>
                                    <line x1="12" y1="18" x2="12" y2="18"/>
                                </svg>
                                <div>
                                    <h6 class="mb-1">Employee Mobile Notifications</h6>
                                    <p class="text-muted small mb-0">Push notifications for overtime warnings</p>
                                </div>
                            </div>
                            <label class="toggle-switch flex-shrink-0 ms-3">
                                <input type="checkbox"
                                       wire:click="toggleMobileNotifications"
                                    {{ $settings->mobile_notifications ?? false ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <!-- Email Summaries -->
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-start">
                                <svg width="18" height="18" class="text-success me-2 mt-1 flex-shrink-0" fill="none"
                                     stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M4 4h16v16H4z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                                <div>
                                    <h6 class="mb-1">Email Summaries</h6>
                                    <p class="text-muted small mb-0">Daily overtime reports to management</p>
                                </div>
                            </div>
                            <label class="toggle-switch flex-shrink-0 ms-3">
                                <input type="checkbox"
                                       wire:click="toggleEmailSummaries"
                                    {{ $settings->email_summaries ?? false ? 'checked' : '' }}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Fixed Footer with Save Button -->
            <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <svg width="14" height="14" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="16" x2="12" y2="12" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="8" x2="12.01" y2="8" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Changes will apply to all employees in your organization
                </small>
                <button wire:click="saveSettings" class="btn btn-primary px-4">
                    <svg width="16" height="16" class="me-1" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" fill="none"
                              stroke="currentColor" stroke-width="2"/>
                        <polyline points="17 21 17 13 7 13 7 21" stroke="currentColor" stroke-width="2"/>
                        <polyline points="7 3 7 8 15 8" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Save Settings
                </button>
            </div>

        </div>
    </div>
</div>
