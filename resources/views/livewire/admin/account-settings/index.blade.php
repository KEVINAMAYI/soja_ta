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
                <!-- Company Information -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 active d-flex align-items-center justify-content-between bg-transparent fs-3 py-3"
                        id="tab-company-information-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-company-information"
                        type="button"
                        role="tab"
                        aria-controls="tab-company-information"
                        aria-selected="true">
                        <i class="ti ti-user-circle mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Company Information</span>
                    </button>
                </li>

                <!-- Roles & Permissions -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-between bg-transparent fs-3 py-3"
                        id="tab-roles-permissions-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-roles-permissions"
                        type="button"
                        role="tab"
                        aria-controls="tab-roles-permissions"
                        aria-selected="false">
                        <i class="ti ti-calendar mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Roles & Permissions</span>
                    </button>
                </li>

                <!-- User -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-between bg-transparent fs-3 py-3"
                        id="tab-users-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-users"
                        type="button"
                        role="tab"
                        aria-controls="tab-users"
                        aria-selected="false">
                        <i class="ti ti-users mx-1 fs-6"></i>
                        <span class="d-none d-md-block">User</span>
                    </button>
                </li>

                <!-- Departments -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-between bg-transparent fs-3 py-3"
                        id="tab-depts-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-depts"
                        type="button"
                        role="tab"
                        aria-controls="tab-depts"
                        aria-selected="false">
                        <i class="ti ti-building mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Departments</span>
                    </button>
                </li>
            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade show active"
                         id="tab-company-information"
                         role="tabpanel"
                         aria-labelledby="tab-company-information-tab"
                         tabindex="0">

                        <livewire:admin.organizations.edit/>

                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade"
                         id="tab-roles-permissions"
                         role="tabpanel"
                         aria-labelledby="tab-roles-permissions-tab"
                         tabindex="0">

                        <livewire:admin.roles.index/>

                    </div>


                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade"
                         id="tab-users"
                         role="tabpanel"
                         aria-labelledby="tab-users-tab"
                         tabindex="0">

                        <livewire:admin.employees.index/>

                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade"
                         id="tab-depts"
                         role="tabpanel"
                         aria-labelledby="tab-depts-tab"
                         tabindex="0">

                        <livewire:admin.departments.index/>

                    </div>

                </div>
            </div>
        </div>

    </div>
</div>





