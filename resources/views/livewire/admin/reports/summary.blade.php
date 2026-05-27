<?php

use App\Models\Attendance;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {


    public $report_type = 'daily_report';

    #[On('setReportType')]
    public function setReportType($type)
    {

        $this->report_type = $type;

    }

}
?>

<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Summary Reports"
            :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
        'label' => 'Summary Reports',
        'icon'  => '<iconify-icon icon=\'mdi:file-chart\' class=\'fs-6\'></iconify-icon>',
        'route' => 'reports.summary',
        ],
        [
            'label' => 'All',
            'icon' => '<iconify-icon icon=\'tabler:users\' class=\'fs-5\'></iconify-icon>',
        ]
      ]"
        />


        <div class="card">

            <ul class="nav nav-pills user-profile-tab" role="tablist">

                <!-- Daily Report-->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'daily_report' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'daily_report' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'daily_report' ? 'true' : 'false' }}">
                        <i class="ti ti-user-circle mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Daily</span>
                    </button>
                </li>

                <!-- Weekly Report -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'weekly_report' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'weekly_report' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'weekly_report' ? 'true' : 'false' }}">
                        <i class="ti ti-calendar mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Weekly</span>
                    </button>
                </li>

                <!-- Monthly Report -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'monthly_report' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'monthly_report' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'monthly_report' ? 'true' : 'false' }}">
                        <i class="ti ti-clock-pause mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Monthly</span>
                    </button>
                </li>

                <!-- Leave -->
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3
                   {{ $report_type === 'custom_date_report' ? 'active' : '' }}"
                        onclick="Livewire.dispatch('setReportType', { type: 'custom_date_report' })"
                        role="tab"
                        aria-selected="{{ $report_type === 'custom_date_report' ? 'true' : 'false' }}">
                        <i class="ti ti-building-skyscraper mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Custom Dates</span>
                    </button>
                </li>


            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade show {{ $report_type === 'daily_report' ? 'show active' : '' }}"
                         id="tab-daily-report"
                         role="tabpanel"
                         aria-labelledby="tab-daily-report-tab"
                         tabindex="0">
                        <div class="row">
                            <div class="col-12">
                                <livewire:admin.reports.daily_report_summary/>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade {{ $report_type === 'weekly_report' ? 'show active' : '' }}"
                         id="tab-weekly-report"
                         role="tabpanel"
                         aria-labelledby="tab-weekly-report-tab"
                         tabindex="0">

                        <div class="row">
                            <div class="col-12">
                                <livewire:admin.reports.weekly_report_summary/>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade {{ $report_type === 'monthly_report' ? 'show active' : '' }}"
                         id="tab-monthly-report"
                         role="tabpanel"
                         aria-labelledby="tab-monthly-report-tab"
                         tabindex="0">

                        <div class="row">
                            <div class="col-12">
                                <livewire:admin.reports.monthly_report_summary/>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade {{ $report_type === 'custom_date_report' ? 'show active' : '' }}"
                         id="tab-custom-date-report"
                         role="tabpanel"
                         aria-labelledby="tab-custom-date-tab"
                         tabindex="0">

                        <div class="row">
                            <div class="col-12">
                                <livewire:admin.reports.custom_date_report_summary/>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

