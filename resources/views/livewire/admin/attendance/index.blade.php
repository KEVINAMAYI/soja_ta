<?php

use Livewire\Volt\Component;

new class extends Component {
}; ?>

@push('styles')
    <style>
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

    </style>
@endpush
<div class="row">
    <div class="col-12">

        <div class="card">

            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">
                <!-- Working Hours -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 active d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-daily-attendance-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-daily-attendance"
                        type="button"
                        role="tab"
                        aria-controls="tab-daily-attendance"
                        aria-selected="true">
                        <i class="ti ti-user-circle mx-2 fs-6"></i>
                        <span class="d-none d-md-block">Daily Attendance</span>
                    </button>
                </li>

                <!-- Overtime -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-monthly-attendance-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-monthly-attendance"
                        type="button"
                        role="tab"
                        aria-controls="tab-monthly-attendance"
                        aria-selected="false">
                        <i class="ti ti-calendar mx-2 fs-6"></i> <!-- Right aligned icon -->
                        <span class="d-none d-md-block">Monthly Attendance</span>
                    </button>
                </li>

            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade show active"
                         id="tab-daily-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-daily-attendance-tab"
                         tabindex="0">
                        <div class="row">
                            <div class="col-12">
                                <div class="card w-100 border position-relative overflow-hidden mb-0">
                                    <div class="card-body p-4">
                                        <livewire:attendance-daily-table theme="bootstrap-4"/>
                                    </div>
                                </div>

                            </div>

                        </div>
                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade"
                         id="tab-monthly-attendance"
                         role="tabpanel"
                         aria-labelledby="tab-monthly-attendance-tab"
                         tabindex="0">

                        <div class="row">
                            <div class="col-12">
                                <div class="card w-100 border position-relative overflow-hidden mb-0">
                                    <div class="card-body p-4">
                                        <livewire:attendance-monthly-table theme="bootstrap-4"/>
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





