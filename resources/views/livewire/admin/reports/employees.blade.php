<?php

use App\Jobs\SendReportJob;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\ReportSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $emails = '';
    public $frequency = 'weekly';
    public $time = '09:00';
    public $day_of_week = null;
    public $timezone = 'Africa/Nairobi';
    public $report_type = 'daily_attendance';
    public $export_report_type = 'daily_attendance';

    // dynamic dropdown data
    public $availableEmails = [];
    public $availableFrequencies = ['daily', 'weekly', 'monthly'];
    public $reportTypes = ['attendance', 'timesheets', 'department', 'break'];
    public $availableDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    public $availableTimezones = [];
    public $startDate;
    public $endDate;
    public $filterStatus;

    public $timeSheetsStartDate;
    public $timeSheetsEndDate;
    public $departments = [];
    public $department_ids = [];
    public $reportSettings;
    public $exportDepartmentIds = [];
    public $filterGrade = null;

    public $filterEmployeeType = 'all';
    public $employeeTypes = [];

    protected function rules()
    {
        return [
            'emails' => 'required|string',
            'export_report_type' => 'required|string',
            'frequency' => 'required|in:daily,weekly,monthly',
            'time' => 'required',
            'day_of_week' => 'nullable|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'timezone' => 'required|string',
        ];
    }

    public function mount()
    {

        // 👇 preload supported PHP timezones
        $this->availableTimezones = \DateTimeZone::listIdentifiers();
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();

        $today = now()->toDateString();
        $this->timeSheetsStartDate = $today;
        $this->timeSheetsEndDate = $today;

        // Load departments for the current organization, grouped by department_derived
        $orgId = auth()->user()->employee->organization_id ?? null;
        if ($orgId) {
            $this->departments = Department::groupedByDerived($orgId);
            $this->employeeTypes = Employee::where('organization_id', $orgId)
                ->whereNotNull('employee_type')
                ->distinct()
                ->orderBy('employee_type')
                ->pluck('employee_type')
                ->toArray();
        }

        $this->loadReportSettings();

    }


    public function loadReportSettings()
    {

        // Simplified - just get raw data first
        $settings = ReportSetting::where('organization_id', auth()->user()->employee->organization_id)
            ->orderBy('report_type')
            ->orderBy('frequency')
            ->get();

        $this->reportSettings = $settings
            ->groupBy(function ($item) {
                // Group by report configuration (excluding recipients)
                return $item->report_type . '|' .
                    $item->frequency . '|' .
                    $item->time . '|' .
                    ($item->day_of_week ?? 'none');
            })
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'report_type' => $first->report_type,
                    'frequency' => $first->frequency,
                    'time' => $first->time,
                    'day_of_week' => $first->day_of_week,
                    'timezone' => $first->timezone,
                    'active' => $group->contains(fn($r) => $r->active == true),
                    'recipients' => $group->pluck('email')->filter()->unique()->values()->toArray(),
                    'ids' => $group->pluck('id')->toArray(),
                    'recipient_count' => $group->count(),
                    'last_run_at' => $first->last_run_at ? Carbon::parse($first->last_run_at) : null,
                    'next_run_at' => $first->next_run_at ? Carbon::parse($first->next_run_at) : null,
                ];
            })
            ->values()
            ->toArray();
    }


    #[On('filter-updated')]
    public function dateChaged($department_ids = null)
    {
        if ($department_ids !== null) {
            $this->exportDepartmentIds = $department_ids;
        }

        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, status: $this->filterStatus, grade: $this->filterGrade, department_ids: $this->exportDepartmentIds, employee_type: $this->filterEmployeeType);
    }

    #[On('timesheets-filter-updated')]
    public function filterChnaged($department_ids = null)
    {
        if ($department_ids !== null) {
            $this->department_ids = $department_ids;
        }

        $this->dispatch('timesheet-range-updated', startDate: $this->timeSheetsStartDate, endDate: $this->timeSheetsEndDate, department_ids: $this->department_ids);
    }


    public function saveReportSetting()
    {
        $this->validate();

        $orgId = auth()->user()->employee->organization_id ?? null;

        if (!$orgId) {
            $this->alert('error', 'Organization not found');
            return;
        }

        try {
            DB::beginTransaction();

            // Parse comma-separated emails
            $emailArray = array_map('trim', explode(',', $this->emails));

            // Filter out empty values and validate emails
            $emailArray = array_filter($emailArray, function ($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            });

            if (empty($emailArray)) {
                throw new Exception('Please provide at least one valid email address');
            }

            // Save settings for each email
            foreach ($emailArray as $email) {
                ReportSetting::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'email' => $email,
                        'report_type' => $this->export_report_type,
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

            $this->dispatch('hide-report-modal');

            LivewireAlert::title('Awesome!')
                ->text('Report settings saved successfully for ' . count($emailArray) . ' recipient(s)')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();

        } catch (Exception $e) {
            DB::rollBack();

            logger()->error('Report settings save failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);


            LivewireAlert::title('Error!')
                ->text('Something went wrong while saving report settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }


    public function toggleReportStatus($ids)
    {

        try {

            // Normalize input
            $ids = is_string($ids) ? json_decode($ids, true) : $ids;

            if (empty($ids)) {
                LivewireAlert::title('Error!')
                    ->text('Invalid report settings selected.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Fetch all targeted settings
            $settings = ReportSetting::whereIn('id', $ids)->get();

            if ($settings->isEmpty()) {
                LivewireAlert::title('Error!')
                    ->text('No report settings found.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Determine new state:
            // If ALL are active → disable all
            // If ANY are inactive → enable all
            $shouldEnable = $settings->contains(fn($s) => !$s->active);

            // Update all
            ReportSetting::whereIn('id', $ids)->update([
                'active' => $shouldEnable,
            ]);

            // Refresh UI (if your component uses it)
            $this->loadReportSettings();

            // SUCCESS ALERT
            LivewireAlert::title('Awesome!')
                ->text(($shouldEnable ? 'Enabled' : 'Disabled') . ' ' . count($ids) . ' report(s) successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {

            LivewireAlert::title('Error!')
                ->text('Something went wrong while updating report settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

            \Log::error('toggleReportStatus error', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteReportSettings($ids)
    {
        try {
            // Normalize input
            $ids = is_string($ids) ? json_decode($ids, true) : $ids;

            if (empty($ids)) {
                LivewireAlert::title('Error!')
                    ->text('Invalid report settings selected for deletion.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Fetch records before deletion for safety/logging
            $settings = ReportSetting::whereIn('id', $ids)->get();

            if ($settings->isEmpty()) {
                LivewireAlert::title('Error!')
                    ->text('No report settings found to delete.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Perform delete
            ReportSetting::whereIn('id', $ids)->delete();

            // Refresh component if applicable
            $this->loadReportSettings();

            // SUCCESS TOAST
            LivewireAlert::title('Awesome!')
                ->text('Deleted ' . count($ids) . ' report setting(s) successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {

            // ERROR TOAST
            LivewireAlert::title('Error!')
                ->text('Something went wrong while deleting report settings.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

            // Log error for debugging
            \Log::error('deleteGroup error', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function runReport($ids)
    {
        try {
            // Normalize the payload
            $ids = is_string($ids) ? json_decode($ids, true) : $ids;

            if (empty($ids)) {
                LivewireAlert::title('Error!')
                    ->text('Invalid report group selection.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Fetch all settings
            $settings = ReportSetting::whereIn('id', $ids)->get();

            if ($settings->isEmpty()) {
                LivewireAlert::title('Error!')
                    ->text('No report settings found.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Filter only active ones
            $activeSettings = $settings->filter(fn($s) => $s->active);

            if ($activeSettings->isEmpty()) {
                LivewireAlert::title('Error!')
                    ->text('This report is disabled. Enable it first before running.')
                    ->error()
                    ->toast()
                    ->position('top-end')
                    ->show();
                return;
            }

            // Dispatch jobs immediately (skip schedule rules)
            foreach ($activeSettings as $setting) {
                dispatch(new SendReportJob($setting->id, $setting->organization_id));
            }

            LivewireAlert::title('Awesome!')
                ->text('Report queued for ' . count($activeSettings) . ' active recipient(s).')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Exception $e) {

            LivewireAlert::title('Error!')
                ->text('Could not run the report right now.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

            \Log::error('runReport error', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }
    }


    public function resetForm()
    {
        $this->reset(['emails', 'frequency', 'time', 'day_of_week']);
        $this->timezone = 'Africa/Nairobi';
    }


    public function closeModal()
    {
        $this->dispatch('hide-report-modal');
    }


    #[On('setReportType')]
    public function setReportType($type)
    {
        $this->report_type = $type;
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }

}; ?>

@push('styles')

    <style>

        #table-bulkActionsDropdown {
            background-color: var(--primary-color) !important;
            border: none;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        #table-bulkActionsDropdown:hover {
            background-color: var(--primary-color) !important; /* darker shade for hover */
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
            title="Detailed Reports"
            :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Detailed Reports',
            'icon'  => '<iconify-icon icon=\'mdi:file-document-outline\' class=\'fs-6\'></iconify-icon>',
        ],
        [
            'label' => 'All',
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
                        <span class="d-none d-md-block">Attendance</span>
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
                        <span class="d-none d-md-block">Timesheets</span>
                    </button>
                </li>

                <!-- Leave -->
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

                <!-- Leave -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'department' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'department' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'department' ? 'true' : 'false' }}">
                        <i class="ti ti-building-skyscraper mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Department Report</span>
                    </button>
                </li>


                <!-- Leave -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'break' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'break' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'break' ? 'true' : 'false' }}">
                        <i class="ti ti-coffee mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Break Report</span>
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
                                                           wire:click.prevent="dispatch('export-pivot-daily-excel')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-excel" width="16" height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            Pivot Excel
                                                        </a>
                                                    </li>

                                                    <li>
                                                        <a href="#"
                                                           wire:click.prevent="dispatch('export-full-excel')"
                                                           class="dropdown-item">
                                                            <iconify-icon icon="mdi:file-excel-box" width="16"
                                                                          height="16"
                                                                          style="margin-right:6px; vertical-align:middle;"></iconify-icon>
                                                            Full Excel (T&amp;A)
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

                                        </div>

                                        <div class="row">
                                            {{-- DATE FILTER --}}
                                            <div class="col-3 mb-3">
                                                <label class="form-label">Start Date</label>
                                                <input
                                                    type="date"
                                                    id="attendance-start-date"
                                                    class="form-control"
                                                    wire:model="startDate"
                                                    wire:change="$dispatch('filter-updated')"
                                                />
                                            </div>

                                            <div class="col-3 mb-3">
                                                <label class="form-label">End Date</label>
                                                <input
                                                    type="date"
                                                    id="attendance-end-date"
                                                    class="form-control"
                                                    wire:model="endDate"
                                                    wire:change="$dispatch('filter-updated')"
                                                />
                                            </div>

                                            <div class="col-3 md-3">
                                                <label class="form-label">Attendance Status</label>
                                                <select
                                                    class="form-control"
                                                    wire:model="filterStatus"
                                                    wire:change="$dispatch('filter-updated')">
                                                    <option value="">All</option>
                                                    <option value="present">Present [Clocked In + Clocked Out]</option>
                                                    <option value="clocked_in">Clocked In</option>
                                                    <option value="clocked_out">Clocked Out</option>
                                                    <option value="absent">Absent</option>
                                                    <option value="on_leave">On Leave</option>
                                                    <option value="off_shift">Off Shift</option>
                                                    <option value="sick_off">Sick Off</option>
                                                </select>
                                            </div>

                                            <div class="col-3 md-3">
                                                <label class="form-label">Department</label>
                                                @include('livewire.admin.partials.department-group-filter', [
                                                    'groupedDepartments' => $departments,
                                                    'selectId' => 'dailyExportDeptFilter',
                                                    'dispatchEvent' => 'filter-updated',
                                                    'selectedDepartmentIds' => $exportDepartmentIds,
                                                ])
                                            </div>

                                            <div class="col-3 mb-3">
                                                <label class="form-label">Employee Type</label>
                                                <select
                                                    class="form-control"
                                                    wire:model="filterEmployeeType"
                                                    wire:change="$dispatch('filter-updated')">
                                                    <option value="all">All Types</option>
                                                    @foreach($employeeTypes as $type)
                                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                                    @endforeach
                                                </select>
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

                                        </div>

                                        <div class="row align-items-end mb-4">

                                            {{-- START DATE --}}
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Start Date</label>
                                                <input
                                                    type="date"
                                                    class="form-control"
                                                    wire:model="timeSheetsStartDate"
                                                    wire:change="$dispatch('timesheets-filter-updated')"
                                                />
                                            </div>

                                            {{-- END DATE --}}
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">End Date</label>
                                                <input
                                                    type="date"
                                                    class="form-control"
                                                    wire:model="timeSheetsEndDate"
                                                    wire:change="$dispatch('timesheets-filter-updated')"
                                                />
                                            </div>

                                            {{-- DEPARTMENT FILTER --}}
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Department</label>
                                                @include('livewire.admin.partials.department-group-filter', [
                                                    'groupedDepartments' => $departments,
                                                    'selectId' => 'monthlyTimesheetsDeptFilter',
                                                    'dispatchEvent' => 'timesheets-filter-updated',
                                                    'selectedDepartmentIds' => $department_ids,
                                                ])
                                            </div>
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


                    <div class="tab-pane fade show {{ $report_type === 'department' ? 'show active' : '' }}"
                         id="tab-daily-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-daily-attendance-tab"
                         tabindex="0">

                        <livewire:admin.reports.departments/>
                    </div>


                    <div class="tab-pane fade show {{ $report_type === 'break' ? 'show active' : '' }}"
                         id="tab-daily-break"
                         role="tabpanel"
                         aria-labelledby="tab-daily-break-tab"
                         tabindex="0">

                        <livewire:admin.reports.breaks/>
                    </div>

                    <div class="tab-pane fade show {{ $report_type === 'scheduled' ? 'show active' : '' }}"
                         id="tab-daily-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-daily-attendance-tab"
                         tabindex="0">
                        <div class="col-12">
                            <!-- Top-right button inside card -->
                            <div class="d-flex justify-content-end mb-4">
                                <button class="btn btn-primary" type="button"
                                        wire:click="$dispatch('show-report-modal')">
                                    <i class="ti ti-mail-forward fs-6 me-1"></i>
                                    Schedule Email Report
                                </button>
                            </div>
                            <table class="table align-middle">
                                <thead class="table-light">
                                <tr>
                                    <th>Report</th>
                                    <th>Frequency</th>
                                    <th>Recipients</th>
                                    <th>Format</th>
                                    {{--                                    <th>Last Run</th>--}}
                                    {{--                                    <th>Next Run</th>--}}
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($reportSettings as $setting)
                                    <tr>
                                        <!-- Report -->
                                        <td>
                                            <strong>{{ ucfirst(str_replace('_', ' ', $setting['report_type'])) }}</strong>
                                        </td>

                                        <!-- Frequency -->
                                        <td>
                                            @if($setting['frequency'] === 'daily')
                                                <span class="badge bg-info">Daily</span>
                                                <br>
                                                <small
                                                    class="text-muted">{{ date('g:i A', strtotime($setting['time'])) }}</small>
                                            @elseif($setting['frequency'] === 'weekly')
                                                <span class="badge bg-primary">Weekly</span>
                                                <br>
                                                <small class="text-muted">{{ ucfirst($setting['day_of_week']) }}
                                                    at {{ date('g:i A', strtotime($setting['time'])) }}</small>
                                            @elseif($setting['frequency'] === 'monthly')
                                                <span class="badge bg-warning">Monthly</span>
                                                <br>
                                                <small
                                                    class="text-muted">{{ date('g:i A', strtotime($setting['time'])) }}</small>
                                            @else
                                                <span
                                                    class="badge bg-secondary">{{ ucfirst($setting['frequency']) }}</span>
                                                <br>
                                                <small
                                                    class="text-muted">{{ date('g:i A', strtotime($setting['time'])) }}</small>
                                            @endif
                                        </td>

                                        <!-- Recipients - Beautiful Display -->
                                        <td>
                                            <div>
                                                <!-- Count Badge -->
                                                <span class="badge rounded-pill bg-primary mb-2">
            {{ count($setting['recipients']) }} recipient{{ count($setting['recipients']) !== 1 ? 's' : '' }}
        </span>

                                                <!-- Collapsible Recipients List -->
                                                <div class="collapse" id="recipients-{{ $loop->index }}">
                                                    <div class="mt-2 p-2 bg-light rounded">
                                                        @foreach($setting['recipients'] as $email)
                                                            <div class="d-flex align-items-center gap-2 py-1">
                                                                <i class="ti ti-mail fs-5 text-primary"></i>
                                                                <small>{{ $email }}</small>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                <!-- Toggle Button -->
                                                @if(count($setting['recipients']) > 0)
                                                    <a
                                                        class="btn btn-sm btn-link p-0 text-decoration-none collapse-toggle"
                                                        data-bs-toggle="collapse"
                                                        href="#recipients-{{ $loop->index }}"
                                                        data-label-show="View all"
                                                        data-label-hide="Hide"
                                                        role="button"
                                                    >
                                                        <small>View all</small>
                                                    </a>
                                                @endif
                                            </div>
                                        </td>


                                        <!-- Format -->
                                        <td><span class="badge bg-light text-dark"><i class="ti ti-file-type-pdf"></i>PDF</span>
                                        </td>

                                        <!-- Last Run -->
                                        {{--                                        <td>{{ $setting['last_run_at']?->format('d M Y H:i') ?? 'Never' }}</td>--}}
                                        {{--                                        <td>{{ $setting['next_run_at']?->format('d M Y H:i') ?? 'Not scheduled' }}</td>--}}

                                        <!-- Status -->
                                        <td>
                <span class="badge {{ $setting['active'] ? 'bg-success' : 'bg-secondary' }}">
                    {{ $setting['active'] ? 'Active' : 'Inactive' }}
                </span>
                                        </td>

                                        <!-- Actions -->
                                        <td class="text-center">
                                            <div class="dropdown dropstart">
                                                <a href="#" data-bs-toggle="dropdown" class="text-dark">
                                                    <i class="ti ti-dots-vertical fs-5"></i>
                                                </a>

                                                <ul class="dropdown-menu">
                                                    <!-- Toggle All -->
                                                    <li>
                                                        <a style="cursor:pointer;"
                                                           class="dropdown-item d-flex align-items-center gap-2"
                                                           wire:click="toggleReportStatus({{ json_encode($setting['ids']) }})">
                                                            <i class="ti ti-toggle-{{ $setting['active'] ? 'left' : 'right' }} fs-5 text-primary"></i>
                                                            <span>{{ $setting['active'] ? 'Disable' : 'Enable' }}</span>
                                                        </a>
                                                    </li>

                                                    <!-- Run Now -->
                                                    <li>
                                                        <a style="cursor:pointer;"
                                                           class="dropdown-item d-flex align-items-center gap-2"
                                                           wire:click="runReport({{ json_encode($setting['ids']) }})">
                                                            <i class="ti ti-player-play fs-5 text-success"></i>
                                                            <span>Run Now</span>
                                                        </a>
                                                    </li>

                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>

                                                    <!-- Delete -->
                                                    <li>
                                                        <a style="cursor:pointer;"
                                                           class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                                           wire:click="deleteReportSettings({{ json_encode($setting['ids']) }})"
                                                           onclick="confirm('Delete all {{ count($setting['recipients']) }} recipient(s) for this schedule?') || event.stopImmediatePropagation()">
                                                            <i class="ti ti-trash fs-5"></i>
                                                            <span>Delete</span>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-5">
                                            <i class="ti ti-file-report fs-1 d-block mb-2"></i>
                                            <p class="mb-0">No scheduled reports found.</p>
                                            <small>Create your first scheduled report to get started.</small>
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>

                            <!-- Initialize Bootstrap Popovers -->
                            @push('scripts')
                                <script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        // Initialize all popovers
                                        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                                        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                                            return new bootstrap.Popover(popoverTriggerEl);
                                        });
                                    });

                                    // Re-initialize after Livewire updates
                                    document.addEventListener('livewire:load', function () {
                                        Livewire.hook('message.processed', (message, component) => {
                                            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                                            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                                                return new bootstrap.Popover(popoverTriggerEl);
                                            });
                                        });
                                    });
                                </script>
                            @endpush
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="modal fade"
         id="reportModal"
         tabindex="-1"
         wire:ignore.self
         x-data="{ modal: null }"
         x-on:show-report-modal.window="
        modal = modal ?? new bootstrap.Modal($el);
        modal.show(); "
         x-on:hide-report-modal.window="
        modal?.hide(); ">

        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content rounded-3">
                <form wire:submit.prevent="saveReportSetting">
                    <div class="modal-header">
                        <h5 class="modal-title fw-semibold" id="reportModalLabel">
                            <i class="ti ti-mail-forward me-2 text-primary"></i>
                            Schedule Email Report
                        </h5>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row">
                            <!-- Recipients - Changed to textarea for comma-separated emails -->
                            <div class="mb-3 col-12">
                                <label class="form-label fw-semibold">Recipients</label>
                                <textarea
                                    class="form-control"
                                    rows="2"
                                    required
                                    wire:model="emails"></textarea>
                                <small class="text-muted">Separate multiple email addresses with commas</small>
                                @error('emails')<small class="text-danger d-block">{{ $message }}</small>@enderror
                            </div>

                            <div class="mb-3 col-md-12">
                                <label class="form-label fw-semibold">Report Type</label>
                                <select wire:model="export_report_type" class="form-select">
                                    <option value="">-- None --</option>
                                    @foreach($reportTypes as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>
                                @error('export_report_type')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>

                            <!-- Frequency -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Frequency</label>
                                <select wire:model="frequency" required class="form-select">
                                    <option value="">-- Select --</option>
                                    @foreach($availableFrequencies as $freq)
                                        <option value="{{ $freq }}">{{ ucfirst($freq) }}</option>
                                    @endforeach
                                </select>
                                @error('frequency')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>

                            <!-- Time -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Time</label>
                                <input type="time" wire:model="time" required class="form-control">
                                @error('time')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>

                            <!-- Day of Week -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Day of Week (optional)</label>
                                <select wire:model="day_of_week" class="form-select">
                                    <option value="">-- None --</option>
                                    @foreach($availableDays as $day)
                                        <option value="{{ $day }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                                @error('day_of_week')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>

                            <!-- Timezone -->
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Timezone</label>
                                <select wire:model="timezone" required class="form-select">
                                    @foreach($availableTimezones as $tz)
                                        <option value="{{ $tz }}">{{ $tz }}</option>
                                    @endforeach
                                </select>
                                @error('timezone')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4 py-3">
                        <button type="button"
                                class="btn btn-light"
                                wire:click="closeModal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-success">
                            <span wire:loading.remove wire:target="saveReportSetting">Save Settings</span>
                            <span wire:loading wire:target="saveReportSetting">
                            <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                            Saving...
                        </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalEl = document.getElementById('reportModal');

            // Initialize modal with static backdrop (prevents outside click from closing)
            const noBackdropModal = new bootstrap.Modal(modalEl, {
                backdrop: false, // Setting to false removes the backdrop entirely
                keyboard: false
            });

            // Helper function to clean up lingering backdrop elements
            const removeLingeringBackdrop = () => {
                // Find all backdrop elements created by Bootstrap and remove them
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    backdrop.remove();
                });
                // Also ensure the body class is clean (Bootstrap handles this, but good practice)
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            };


            // Show modal via Livewire
            window.addEventListener('show-report-modal', () => {
                modalInstance.show();
            });

            // Hide modal via Livewire (THE FIX IS HERE)
            window.addEventListener('hide-report-modal', () => {
                modalInstance.hide();

                // CRITICAL FIX: Manually remove the backdrop element after the hide call.
                // We use a slight delay (100ms) to ensure Bootstrap finishes its internal transition.
                setTimeout(removeLingeringBackdrop, 100);
            });


            document.querySelectorAll(".collapse-toggle").forEach((toggle) => {
                const targetId = toggle.getAttribute("href").replace("#", "");
                const target = document.getElementById(targetId);

                const label = toggle.querySelector("small");
                const labelShow = toggle.dataset.labelShow;
                const labelHide = toggle.dataset.labelHide;

                // Set initial label state
                label.textContent = labelShow;

                // When opened → show "Hide"
                target.addEventListener("show.bs.collapse", () => {
                    label.textContent = labelHide;
                });

                // When closed → show "View all"
                target.addEventListener("hide.bs.collapse", () => {
                    label.textContent = labelShow;
                });
            });

        });
    </script>
@endpush







