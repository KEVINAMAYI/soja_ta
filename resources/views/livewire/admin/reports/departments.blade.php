<?php

use App\Models\ReportSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public string $report_type = 'daily_department_attendance';

    public array $emails = [];
    public string $frequency = 'daily';
    public ?string $time = '09:00';
    public ?string $day_of_week = 'Monday';
    public string $timezone = 'Africa/Nairobi';

    public array $availableEmails = [];
    public array $availableFrequencies = ['daily', 'weekly', 'monthly'];
    public array $availableDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    public array $availableTimezones = [];

    private array $frequencyToReportType = [
        'daily' => 'daily_department_attendance',
        'weekly' => 'weekly_department_attendance',
        'monthly' => 'monthly_department_attendance',
    ];

    private array $reportTypeToFrequency = [
        'daily_department_attendance' => 'daily',
        'weekly_department_attendance' => 'weekly',
        'monthly_department_attendance' => 'monthly',
    ];


    public function mount()
    {
        $this->availableEmails = $this->getOrgEmails();
        $this->availableTimezones = timezone_identifiers_list();

        // Initialize frequency from current report_type
        $this->frequency = $this->reportTypeToFrequency[$this->report_type] ?? 'daily';
    }

    public function setReportType($type)
    {
        $this->report_type = $type;
        $this->frequency = $this->reportTypeToFrequency[$type] ?? $this->frequency;

        $orgId = Auth::user()->employee->organization_id ?? null;

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
        } else {
            // Only default these for new report types
            $this->time = $this->time ?? '09:00';
            $this->day_of_week = $this->day_of_week ?? 'Monday';
            $this->emails = $this->emails ?? [];
        }

        // Notify JS to refresh Select2 UI
        $this->dispatch('refresh-select2');
    }


    #[On('setEmails')]
    public function setEmails($emails)
    {
        $this->emails = $emails ?? [];
    }

    public function saveReportSetting()
    {
        $this->validate([
            'emails' => 'required|array|min:1',
            'time' => 'required|date_format:H:i',
            'frequency' => 'required|string',
            'timezone' => 'required|string',
            'day_of_week' => 'nullable|string',
        ]);

        $orgId = Auth::user()->employee->organization_id ?? null;

        DB::beginTransaction();

        try {

            // Delete old department reports for this org
            ReportSetting::where('organization_id', $orgId)
                ->where('report_type', 'like', '%department%')
                ->delete();

            // Store new record for each selected email
            foreach ($this->emails as $email) {
                ReportSetting::create([
                    'organization_id' => $orgId,
                    'report_type' => $this->report_type, // or keep $this->report_type if you prefer
                    'email' => $email,
                    'frequency' => $this->frequency,
                    'time' => $this->time,
                    'day_of_week' => $this->day_of_week,
                    'timezone' => $this->timezone,
                    'active' => true,
                ]);
            }

            DB::commit();

            $this->dispatch('hide-report-settings-modal');

            LivewireAlert::title('Awesome!')
                ->text('Report settings updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Throwable $e) {
            DB::rollBack();

            LivewireAlert::title('Oops!')
                ->text('Failed to save report settings: ' . $e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

            \Log::error('Error saving report settings: ', ['error' => $e->getMessage()]);
        }
    }

    private function getOrgEmails(): array
    {
        $orgId = Auth::user()->employee->organization_id ?? null;
        return \App\Models\Employee::where('organization_id', $orgId)
            ->pluck('email')
            ->toArray();
    }


    public function changeFrequency($newFrequency)
    {
        $this->report_type = $this->frequencyToReportType[$newFrequency] ?? $this->report_type;
        $this->setReportType($this->report_type);
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

        .select2-container--default.select2-container--open {
            margin-bottom: 0 !important;
        }

        .select2-container {
            width: 100% !important;
        }

        .select2-selection__rendered {
            line-height: 1.5 !important;
        }

        .select2-selection__choice {
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }

    </style>
@endpush

<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Department Reports"
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
            'label' => 'Departments',
            'icon' => '<iconify-icon icon=\'mdi:office-building-outline\' class=\'fs-5\'></iconify-icon>',
        ]
      ]"
        />


        <div class="card card-body">


            <!-- Top-right button inside card -->
            <div class="d-flex justify-content-end mb-5">
                <button class="btn d-flex align-items-center gap-2 px-3 py-2"
                        style="background-color: #EDE9FE; color: #DC2626; border-radius: 8px;"
                        data-bs-toggle="modal"
                        data-bs-target="#reportModal">
                    <iconify-icon icon="mdi:cog-outline" width="20"
                                  height="20"></iconify-icon>
                    <span>Schedule Report</span>
                </button>
            </div>

            {{-- Livewire Table --}}
            <livewire:departmental-attendance-table theme="bootstrap-4"/>

        </div>
    </div>

    <div class="modal fade" id="reportModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content rounded-3">
                <form wire:submit.prevent="saveReportSetting">
                    <div class="modal-header">
                        <h5 class="modal-title fw-semibold">
                            <span class="me-2">&#9881;</span>
                            {{ ucfirst(str_replace('_', ' ', $report_type)) }} Email Settings
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body px-4">
                        <div class="row">

                            {{-- Emails --}}
                            <div class="mb-3 col-12" wire:ignore>
                                <select required class="form-control select2" multiple wire:model="emails"
                                        data-placeholder="Select emails">
                                    @foreach($availableEmails as $email)
                                        <option value="{{ $email }}">{{ $email }}</option>
                                    @endforeach
                                </select>
                                @error('emails') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            {{-- Frequency --}}
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Frequency</label>
                                <select required wire:model="frequency"
                                        wire:change="changeFrequency($event.target.value)"
                                        class="form-select">
                                    @foreach($availableFrequencies as $freq)
                                        <option value="{{ $freq }}">{{ ucfirst($freq) }}</option>
                                    @endforeach
                                </select>
                            </div>


                            {{-- Time --}}
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Time</label>
                                <input required type="time" wire:model="time" required class="form-control">
                            </div>

                            {{-- Day of Week (conditionally shown) --}}
                            <div class="mb-3 col-md-6" x-data="{ freq: @entangle('frequency') }"
                                 x-show="['weekly','monthly'].includes(freq)">
                                <label class="form-label fw-semibold">Day of Week</label>
                                <select required wire:model="day_of_week" class="form-select">
                                    <option value="">-- Select --</option>
                                    @foreach($availableDays as $day)
                                        <option value="{{ $day }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                            </div>


                            {{-- Timezone --}}
                            <div class="mb-3 col-md-6">
                                <label class="form-label fw-semibold">Timezone</label>
                                <select required wire:model="timezone" class="form-select">
                                    @foreach($availableTimezones as $tz)
                                        <option value="{{ $tz }}">{{ $tz }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4 py-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Settings</button>
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

        window.addEventListener('refresh-select2', () => {
            $('select.select2[multiple]').each(function () {
                $(this).val(@this.get('emails')).trigger('change');
            });
        });

    </script>

@endpush








