<?php

use Livewire\Volt\Component;

new class extends Component {
}; ?>

<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Organization Reports"
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
            'label' => 'Organization',
            'icon' => '<iconify-icon icon=\'mdi:domain\' class=\'fs-5\'></iconify-icon>',
        ]
       ]"
        />


        <div class="card">
            <div class="card-body p-4 pb-0" data-simplebar>
                <livewire:admin.summaries.employee-statuses />
            </div>
        </div>
    </div>

    <div class="col-lg-12">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Zoomable Line Chart</h4>
                <div id="chart-line-zoomable" class="ms-n3"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <div id="chart-bar-stacked"></div>
            </div>
        </div>
    </div>
    <!-- ----------------------------------------- -->
    <!-- Annual Profit -->
    <!-- ----------------------------------------- -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">Daily Attendance</h5>
                <div class="bg-primary bg-opacity-10 rounded-1 overflow-hidden mb-4">
                    <div id="chart-pie-simple"></div>
                </div>
                <div class="d-flex align-items-center justify-content-between pb-6 border-bottom">
                    <div>
                        <span class="text-muted fw-medium">Present</span>
                        <span class="fs-11 fw-medium d-block mt-1">368 records</span>
                    </div>
                    <div class="text-end">
                        <h6 class="fw-bolder mb-1 lh-base">78%</h6>
                        <span class="fs-11 fw-medium text-success">+2.5%</span>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between py-6 border-bottom">
                    <div>
                        <span class="text-muted fw-medium">Absent</span>
                        <span class="fs-11 fw-medium d-block mt-1">72 records</span>
                    </div>
                    <div class="text-end">
                        <h6 class="fw-bolder mb-1 lh-base">15%</h6>
                        <span class="fs-11 fw-medium text-danger">-1.1%</span>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between pt-6">
                    <div>
                        <span class="text-muted fw-medium">On Leave</span>
                        <span class="fs-11 fw-medium d-block mt-1">30 records</span>
                    </div>
                    <div class="text-end">
                        <h6 class="fw-bolder mb-1 lh-base">7%</h6>
                        <span class="fs-11 fw-medium text-warning">+0.3%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
    <script src="assets/js/apps/contact.js"></script>
@endpush




