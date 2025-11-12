<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ReportSetting;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $emails = [];
    public $frequency = 'weekly';
    public $time = '09:00';
    public $day_of_week = null;
    public $timezone = 'Africa/Nairobi';
    public $report_type = 'daily_attendance';

    // dynamic dropdown data
    public $availableEmails = [];
    public $availableFrequencies = ['daily', 'weekly', 'monthly'];
    public $availableDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    public $availableTimezones = [];
    public $startDate;
    public $endDate;

    public function mount()
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $this->availableEmails = Employee::where('organization_id', $orgId)
            ->pluck('email')
            ->filter()
            ->toArray();

        // 👇 preload supported PHP timezones
        $this->availableTimezones = \DateTimeZone::listIdentifiers();
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();

    }


    #[On('date-changed')]
    public function dateChaged()
    {
        // Emit event to other Livewire components
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate);
    }


    public function saveReportSetting()
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        try {
            DB::beginTransaction();

            foreach ($this->emails as $email) {
                ReportSetting::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'email' => $email,
                        'report_type' => $this->report_type,
                    ],
                    [
                        'frequency' => $this->frequency,
                        'time' => $this->time,
                        'day_of_week' => $this->day_of_week,
                        'timezone' => $this->timezone,
                        'active' => true,
                    ]
                );
            }

            DB::commit();

            $this->dispatch('hide-report-settings-modal');

            LivewireAlert::title('Awesome!')
                ->text('Reports settings updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();

        } catch (Exception $e) {
            DB::rollBack();

            // Log the error for debugging
            logger()->error('Report settings save failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            LivewireAlert::title('Error')
                ->text('Something went wrong while saving report settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    #[On('setEmails')]
    public function setEmails($emails)
    {
        $this->emails = $emails ?? [];
    }

    public function resetForm()
    {
        $this->reset([
            'emails',
            'frequency',
            'time',
            'day_of_week',
            'timezone'
        ]);
    }


    #[On('setReportType')]
    public function setReportType($type)
    {
        $this->report_type = $type;

        // 🔄 Reset to clean defaults
        $this->emails = [];
        $this->frequency = 'weekly';
        $this->time = '09:00';
        $this->day_of_week = null;
        $this->timezone = 'Africa/Nairobi';

        // 🎯 Enforce type-specific defaults
        if ($this->report_type === 'daily_attendance') {
            $this->frequency = 'daily';
            $this->day_of_week = null; // irrelevant for daily
        }

        if ($this->report_type === 'monthly_attendance') {
            $this->frequency = 'monthly';
            $this->day_of_week = null; // monthly doesn’t need weekly day
        }

        // 🏢 Load saved settings for this org + report type (if any)
        $orgId = auth()->user()->employee->organization_id ?? null;

        $existing = ReportSetting::where('organization_id', $orgId)
            ->where('report_type', $this->report_type)
            ->get();

        if ($existing->isNotEmpty()) {

            $first = $existing->first();

            $this->emails = $existing->pluck('email')->toArray();
            $this->frequency = $first->frequency ?? $this->frequency;
            $this->time = $first->time ? date('H:i', strtotime($first->time)) : $this->time;
            $this->day_of_week = $first->day_of_week ?? $this->day_of_week;
            $this->timezone = $first->timezone ?? $this->timezone;
        }
        $this->dispatch('modify-bulk-action');

    }

}; ?>

@push('styles')

    <style>

        #table-bulkActionsDropdown {
            background-color: #e14326;
            border: none;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        #table-bulkActionsDropdown:hover {
            background-color: #c2361d; /* darker shade for hover */
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(225, 67, 38, 0.4);
        }

        .btn-outline-secondary {
            margin-left: 0.5rem !important;
            padding: 6px 16px !important;
            border-radius: 8px !important;
            font-size: 0.875rem !important;
            transition: all 0.2s ease-in-out !important;
            border-color: red !important;
        }

        .btn-outline-secondary:hover {
            background-color: #f1f1f1 !important;
            border-color: #aaa !important;
            color: #000 !important;
        }

        .btn-outline-secondary svg,
        .btn-outline-secondary svg * {
            fill: red !important;
            stroke: red !important;
        }

        .btn-outline-secondary:hover svg,
        .btn-outline-secondary:hover svg * {
            fill: white !important;
            stroke: white !important;
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


        .email-badge {
            background: linear-gradient(45deg, #6dbf8c, #3a9e64, #2a8a47, #118c1b); /* Green gradient */
            color: white;
            padding: 2px 5px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
            text-transform: lowercase;
            text-overflow: ellipsis;
            max-width: 200px; /* Ensures long emails don't overflow */
            overflow: hidden;
            white-space: nowrap;
            transition: transform 0.3s ease, background 0.3s ease; /* Smooth transition for hover effect */
        }

        .email-badge:hover {
            background: linear-gradient(45deg, #6dbf8c, #3a9e64, #2a8a47, #118c1b); /* Green gradient */
            transform: translateY(-3px); /* Slight lift effect */
            cursor: pointer;
        }

        .email-badge:focus {
            outline: none;
        }

        .modal-body {
            position: relative;
        }

        .d-flex.flex-wrap.gap-2 {
            padding-top: 10px;
        }

        /* Optional: If you'd like to adjust the spacing between badges */
        .d-flex.flex-wrap.gap-2 .email-badge {
            margin-bottom: 8px;
        }



    </style>

@endpush
<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Employee Reports"
            :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Reports',
            'icon' => '<iconify-icon icon=\'mdi:file-chart-outline\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Employees',
            'icon' => '<iconify-icon icon=\'tabler:users\' class=\'fs-5\'></iconify-icon>',
        ]
      ]"
        />


        <div class="card">

            <ul class="nav nav-pills user-profile-tab" role="tablist">
                <!-- Daily -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'daily_attendance' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'daily_attendance' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'daily_attendance' ? 'true' : 'false' }}">
                        <i class="ti ti-user-circle mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Daily Attendance</span>
                    </button>
                </li>

                <!-- Monthly -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'monthly_attendance' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'monthly_attendance' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'monthly_attendance' ? 'true' : 'false' }}">
                        <i class="ti ti-calendar mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Monthly Attendance</span>
                    </button>
                </li>

                <!-- Leave -->
                <!-- Monthly -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'leave' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'leave' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'leave' ? 'true' : 'false' }}">
                        <i class="ti ti-clock-pause mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Leave Report</span>
                    </button>
                </li>

            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade show {{ $report_type === 'daily_attendance' ? 'show active' : '' }}"
                         id="tab-daily-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-daily-attendance-tab"
                         tabindex="0">
                        <div class="row">
                            <div class="col-12">
                                <div class="card w-100 border position-relative overflow-hidden mb-0">
                                    <div class="card-body p-4">


                                        <!-- Top-right button inside card -->
                                        <div class="d-flex justify-content-end mb-5">

                                            <div style="margin-right:10px;" class="dropdown">
                                                <button class="btn btn-primary dropdown-toggle" type="button"
                                                        id="exportDailyDropdown" data-bs-toggle="dropdown"
                                                        aria-expanded="false">
                                                    <iconify-icon icon="mdi:file-export-outline" width="18" height="18"
                                                                  style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                    Export
                                                </button>
                                                <ul class="dropdown-menu" aria-labelledby="exportDailyDropdown">
                                                    <li>
                                                        <a href="#"
                                                           wire:click.prevent="dispatch('export-daily-excel')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-excel" width="16" height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            Excel
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="#"
                                                           wire:click.prevent="dispatch('export-daily-pdf')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-pdf-box" width="16" height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            PDF
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>


                                            <button class="btn d-flex align-items-center gap-2 px-3 py-2"
                                                    style="background-color: #EDE9FE; color: #DC2626; border-radius: 8px;"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#reportModal"
                                                    onclick="Livewire.dispatch('setReportType', { type: 'daily_attendance' })">
                                                <iconify-icon icon="mdi:email-newsletter" width="20"
                                                              height="20"></iconify-icon>
                                                <span>Email Reports</span>
                                            </button>
                                        </div>

                                        <div class="row">
                                            {{-- DATE FILTER --}}
                                            <div class="col-4 mb-4">
                                                <label class="form-label">Start Date</label>
                                                <input
                                                    type="date"
                                                    id="attendance-start-date"
                                                    class="form-control"
                                                    wire:model="startDate"
                                                    wire:change="$dispatch('date-changed')"
                                                />
                                            </div>

                                            <div class="col-4 mb-4">
                                                <label class="form-label">End Date</label>
                                                <input
                                                    type="date"
                                                    id="attendance-end-date"
                                                    class="form-control"
                                                    wire:model="endDate"
                                                    wire:change="$dispatch('date-changed')"
                                                />
                                            </div>
                                        </div>


                                        <!-- Livewire Component -->
                                        <livewire:attendance-daily-table theme="bootstrap-4"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade {{ $report_type === 'monthly_attendance' ? 'show active' : '' }}"
                         id="tab-monthly-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-monthly-attendance-tab"
                         tabindex="0">

                        <div class="row">
                            <div class="col-12">
                                <div class="card w-100 border position-relative overflow-hidden mb-0">
                                    <div class="card-body p-4">

                                        <!-- Top-right button inside card -->
                                        <div class="d-flex justify-content-end mb-5">
                                            <div style="margin-right:10px;" class="dropdown">
                                                <button class="btn btn-primary dropdown-toggle" type="button"
                                                        id="exportDropdown" data-bs-toggle="dropdown"
                                                        aria-expanded="false">
                                                    <iconify-icon icon="mdi:file-export-outline" width="18" height="18"
                                                                  style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                    Export
                                                </button>
                                                <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                                                    <li>
                                                        <a href="#"
                                                           wire:click.prevent="dispatch('export-monthly-excel')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-excel" width="16" height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            Excel
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="#"
                                                           wire:click.prevent="dispatch('export-monthly-pdf')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-pdf-box" width="16" height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            PDF
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>

                                            <button class="btn d-flex align-items-center gap-2 px-3 py-2"
                                                    style="background-color: #EDE9FE; color: #DC2626; border-radius: 8px;"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#reportModal"
                                                    onclick="Livewire.dispatch('setReportType', { type: 'monthly_attendance' })">
                                                <iconify-icon icon="mdi:email-newsletter" width="20"
                                                              height="20"></iconify-icon>
                                                <span>Email Reports</span>
                                            </button>
                                        </div>

                                        <livewire:attendance-monthly-table theme="bootstrap-4"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="tab-pane fade show {{ $report_type === 'leave' ? 'show active' : '' }}"
                         id="tab-daily-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-daily-attendance-tab"
                         tabindex="0">

                        <livewire:admin.leaves.index :isReporting="true"/>

                    </div>

                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="reportModal" tabindex="-1"
         aria-labelledby="reportModalLabel"
         aria-hidden="true"
         wire:ignore.self>

        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content rounded-3">
                <form wire:submit.prevent="saveReportSetting">
                    <div class="modal-header">
                        <h5 class="modal-title fw-semibold" id="reportModalLabel">
                            <span class="me-2">&#9881;</span>
                            {{ $report_type === 'daily_attendance' ? 'Daily Report Email Settings' : 'Monthly Report Email Settings' }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4">
                        <div class="row">
                            <!-- Emails (dynamic from organizations) -->
                            <div class="mb-3 col-12" wire:ignore>
                                <select class="form-control select2" required multiple
                                        data-placeholder="Select recipients"
                                        wire:model="emails">
                                    @foreach($availableEmails as $email)
                                        <option value="{{ $email }}">{{ $email }}</option>
                                    @endforeach
                                </select>
                                @error('emails') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <!-- Frequency -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Frequency</label>

                                @if($report_type === 'daily_attendance')
                                    <input type="text" class="form-control" value="Daily" readonly>
                                    <input type="hidden" wire:model="frequency">
                                @elseif($report_type === 'monthly_attendance')
                                    <input type="text" class="form-control" value="Monthly" readonly>
                                    <input type="hidden" wire:model="frequency">
                                @else
                                    <select wire:model="frequency" required class="form-select">
                                        @foreach($availableFrequencies as $freq)
                                            <option value="{{ $freq }}">{{ ucfirst($freq) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            <!-- Time -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Time</label>
                                <input type="time" wire:model="time" required class="form-control">
                            </div>

                            <!-- Day of Week -->
                            @if($report_type === 'monthly_attendance')
                                <div class="mb-3 col-md-6">
                                    <label class="form-label fw-semibold">Day of Week</label>
                                    <select wire:model="day_of_week" required class="form-select">
                                        <option value="">-- Select --</option>
                                        @foreach($availableDays as $day)
                                            <option value="{{ $day }}">{{ $day }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <!-- Timezone -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Timezone</label>
                                <select wire:model="timezone" required class="form-select">
                                    @foreach($availableTimezones as $tz)
                                        <option value="{{ $tz }}">{{ $tz }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Active Emails (Gradient Spans) -->
                            <div class="mt-4">
                                <h6 class="fw-semibold">Active Users</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($emails as $email)
                                        <span class="email-badge">{{ $email }}</span>
                                    @endforeach
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="modal-footer px-4 py-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


</div>

@push('scripts')
    <script src="assets/libs/select2/dist/js/select2.full.min.js"></script>

    <script>
        function initializeMultiSelect(context = document) {
            $(context).find('select.select2[multiple]').each(function () {
                const $el = $(this);
                const parentModal = $el.closest('.modal');

                $el.select2({
                    width: '100%',
                    dropdownParent: parentModal.length ? parentModal : $(document.body),
                    placeholder: $el.data('placeholder') || 'Select options',
                    allowClear: $el.data('allow-clear') === "true",
                    minimumResultsForSearch: 0 // always show search box
                });

                // 🔑 Sync values to Livewire on change
                $el.on('change', function () {
                    let selected = $(this).val();
                    Livewire.dispatch('setEmails', {emails: selected});
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            initializeMultiSelect();
        });

        // Re-initialize inside modals
        $(document).on('shown.bs.modal', function (e) {
            initializeMultiSelect(e.target);
        });

        window.addEventListener('hide-report-settings-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('reportModal'))?.hide();
        });


    </script>

@endpush





