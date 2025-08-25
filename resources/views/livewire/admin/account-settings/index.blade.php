<?php

use App\Models\Organization;
use Livewire\Volt\Component;

new class extends Component {

    public $organizationId;
    public $org;

    public function mount()
    {
        // Get the logged in user
        $user = auth()->user();

        // Ensure they have an employee record with an organization
        if ($user && $user->employee && $user->employee->organization_id) {
            $this->organizationId = $user->employee->organization_id;

            // Fetch the organization
            $this->org = Organization::findOrFail($this->organizationId);

        } else {
            abort(403, 'No organization found for this user.');
        }
    }


}; ?>

@push('styles')
    <style>
        /* Buttons */
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

        /* Input fields */
        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

        /* Accordion wrapper */
        .accordion-item {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        /* Accordion header button */
        .accordion-button {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
            padding: 1rem 1.25rem;
            transition: background-color 0.3s ease;
            border: none;
        }

        .accordion-button:not(.collapsed) {
            background-color: #e9ecef;
            color: #000;
        }

        .accordion-button:hover {
            background-color: #e2e6ea;
        }

        /* Remove default arrow */
        .accordion-button::after {
            display: none !important;
        }

        /* Accordion collapse body */
        .accordion-collapse {
            background-color: white;
            padding: 1rem 1.25rem;
            border-top: 1px solid #dee2e6;
            border-bottom-left-radius: 0px;
            border-bottom-right-radius: 0px;
        }

        /* Flatten Livewire inner card */
        .accordion-collapse .card {
            box-shadow: none !important;
            border: none !important;
            border-radius: 0 !important;
            margin: 0 !important;
        }

        /* Rounded corners when closed */
        .accordion-item .accordion-button.collapsed {
            border-radius: 8px;
        }

        /* Only top corners when open */
        .accordion-item .accordion-button:not(.collapsed) {
            border-top-left-radius: 0px;
            border-top-right-radius: 0px;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }

        .accordion-collapse {
            background-color: white;
            padding: 1rem 1.25rem;
            border-top: 1px solid #dee2e6;
            border-bottom-left-radius: 0px;
            border-bottom-right-radius: 0px;

            /* Smooth transition */
            transition: height 0.3s ease, padding 0.3s ease;
            overflow: hidden;
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
                        <span class="d-none d-md-block">User & Departments</span>
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

                        <livewire:admin.organizations.edit :id="$org->id"/>

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

                        <div class="accordion" id="customAccordion">
                            <div class="accordion-item border-0 mb-3 shadow-sm rounded">
                                <h2 class="accordion-header" id="headingOne">
                                    <button class="fs-4 accordion-button fw-bold collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseOne"
                                            aria-expanded="false" aria-controls="collapseOne">
                                        Departments
                                    </button>
                                </h2>
                                <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne"
                                     data-bs-parent="#customAccordion">
                                    <livewire:admin.departments.index/>
                                </div>
                            </div>

                            <div class="accordion" id="userAccordion">
                                <div class="accordion-item border-0 shadow-sm rounded">
                                    <h2 class="accordion-header" id="headingUsers">
                                        <button class="fs-4 accordion-button fw-bold collapsed" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#collapseUsers"
                                                aria-expanded="false" aria-controls="collapseUsers">
                                            Users
                                        </button>
                                    </h2>
                                    <div id="collapseUsers" class="accordion-collapse collapse"
                                         aria-labelledby="headingUsers"
                                         data-bs-parent="#userAccordion">
                                        <livewire:admin.employees.index :roleId="null" />
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





