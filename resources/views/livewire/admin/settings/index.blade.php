<?php

use Livewire\Volt\Component;

new class extends Component {
}; ?>

<div>

    <div class="container-fluid">
        <div class="card card-body py-3">
            <div class="row align-items-center">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-space-between">
                        <h4 class="mb-4 mb-sm-0 card-title">Settings</h4>
                        <nav aria-label="breadcrumb" class="ms-auto">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item d-flex align-items-center">
                                    <a class="text-muted text-decoration-none d-flex" href="../main/index.html">
                                        <iconify-icon icon="solar:home-2-line-duotone" class="fs-6"></iconify-icon>
                                    </a>
                                </li>
                                <li class="breadcrumb-item" aria-current="page">
                        <span class="badge fw-medium fs-2 bg-primary-subtle text-primary">
                           Settings
                        </span>
                                </li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">
                <!-- Working Hours -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 active d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-working-hours-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-working-hours"
                        type="button"
                        role="tab"
                        aria-controls="tab-working-hours"
                        aria-selected="true">
                        <i class="ti ti-user-circle me-2 fs-6"></i>
                        <span class="d-none d-md-block">Working Hours Policy</span>
                    </button>
                </li>

                <!-- Overtime -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-overtime-policy-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-overtime-policy"
                        type="button"
                        role="tab"
                        aria-controls="tab-overtime-policy"
                        aria-selected="false">
                        <i class="ti ti-bell me-2 fs-6"></i>
                        <span class="d-none d-md-block">Overtime Hours Policy</span>
                    </button>
                </li>

                <!-- Notifications -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-notifications-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-notifications"
                        type="button"
                        role="tab"
                        aria-controls="tab-notifications"
                        aria-selected="false">
                        <i class="ti ti-article me-2 fs-6"></i>
                        <span class="d-none d-md-block">Notifications</span>
                    </button>
                </li>

                <!-- Security -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 d-flex align-items-center justify-content-center bg-transparent fs-3 py-3"
                        id="tab-security-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-holiday"
                        type="button"
                        role="tab"
                        aria-controls="tab-security"
                        aria-selected="false">
                        <i class="ti ti-lock me-2 fs-6"></i>
                        <span class="d-none d-md-block">Security</span>
                    </button>
                </li>
            </ul>

            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">
                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade show active"
                         id="tab-working-hours"
                         role="tabpanel"
                         aria-labelledby="tab-working-hours-tab"
                         tabindex="0">
                        <div class="row">
                            <div class="col-12">
                                <div class="card w-100 border position-relative overflow-hidden mb-0">
                                    <div class="card-body p-4">
                                        <h6 class="mb-3">Working Hours Policy</h6>

                                        <!-- Daily Required Hours -->
                                        <div class="mb-3">
                                            <label for="dailyHours" class="form-label">Daily Required Hours</label>
                                            <div class="input-group">
                                                <input type="number" step="0.1" class="form-control" id="dailyHours"
                                                       placeholder="e.g. 8.0" required>
                                                <span class="input-group-text">hrs</span>
                                            </div>
                                        </div>

                                        <!-- Start & End Time -->
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="startTime" class="form-label">Start Time</label>
                                                <input type="time" class="form-control" id="startTime" value="09:00"
                                                       required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="endTime" class="form-label">End Time <span
                                                        class="text-muted">(optional)</span></label>
                                                <input type="time" class="form-control" id="endTime" value="17:00">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- Save/Cancel Buttons -->
                            <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                <button class="btn btn-primary">Save</button>
                                <button class="btn bg-danger-subtle text-danger">Cancel</button>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Policy Tab -->
                    <div class="tab-pane fade"
                         id="tab-overtime-policy"
                         role="tabpanel"
                         aria-labelledby="tab-overtime-policy-tab"
                         tabindex="0">
                        <div class="row justify-content-center">
                            <div class="col-lg-9">
                                <div class="card border shadow-none">
                                    <div class="card-body p-4">
                                        <h4 class="card-title mb-4">Overtime Policy</h4>

                                        <!-- Minimum OT Threshold -->
                                        <div class="mb-4">
                                            <label for="minOtThreshold" class="form-label">Minimum OT Threshold</label>
                                            <div class="input-group">
                                                <input type="number" step="0.1" class="form-control" id="minOtThreshold"
                                                       placeholder="e.g. 1.0" required>
                                                <span class="input-group-text">hrs</span>
                                            </div>
                                        </div>

                                        <!-- Approval Required -->
                                        <div class="d-flex align-items-center justify-content-between mb-4">
                                            <div class="d-flex align-items-center gap-3">
                                                <div
                                                    class="text-bg-light rounded-1 p-6 d-flex align-items-center justify-content-center">
                                                    <i class="ti ti-check text-dark d-block fs-7"></i>
                                                </div>
                                                <div>
                                                    <h5 class="fs-4 fw-semibold mb-0">Approval Required</h5>
                                                    <p class="mb-0">Overtime must be approved by a manager</p>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="otApprovalSwitch" checked>
                                            </div>
                                        </div>

                                        <!-- OT Allowed on Weekends -->
                                        <div class="d-flex align-items-center justify-content-between mb-4">
                                            <div class="d-flex align-items-center gap-3">
                                                <div
                                                    class="text-bg-light rounded-1 p-6 d-flex align-items-center justify-content-center">
                                                    <i class="ti ti-calendar-x text-dark d-block fs-7"></i>
                                                </div>
                                                <div>
                                                    <h5 class="fs-4 fw-semibold mb-0">OT Allowed on Weekends</h5>
                                                    <p class="mb-0">Enable or disable OT tracking on weekends</p>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="otWeekendSwitch">
                                            </div>
                                        </div>

                                        <!-- Auto Calculate OT -->
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-3">
                                                <div
                                                    class="text-bg-light rounded-1 p-6 d-flex align-items-center justify-content-center">
                                                    <i class="ti ti-calculator text-dark d-block fs-7"></i>
                                                </div>
                                                <div>
                                                    <h5 class="fs-4 fw-semibold mb-0">Auto Calculate OT</h5>
                                                    <p class="mb-0">Automatically compute OT from working hours</p>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="autoOtSwitch" checked>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Save / Cancel Buttons -->
                            <div class="col-12">
                                <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                    <button class="btn btn-primary">Save</button>
                                    <button class="btn bg-danger-subtle text-danger">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notifications Tab -->
                    <div class="tab-pane fade"
                         id="tab-notifications"
                         role="tabpanel"
                         aria-labelledby="tab-notifications-tab"
                         tabindex="0">

                        <div class="row justify-content-center">
                            <div class="col-lg-9">
                                <div class="card border shadow-none">
                                    <div class="card-body p-4">
                                        <h4 class="card-title mb-4">Event Notification Settings</h4>
                                        <p class="card-subtitle mb-4">Manage who gets notified when specific events
                                            occur.</p>

                                        <!-- Notification Table -->
                                        <div class="table-responsive">
                                            <table class="table align-middle mb-0">
                                                <thead class="table-light">
                                                <tr>
                                                    <th scope="col">Event</th>
                                                    <th scope="col" class="text-center">Notify Employee</th>
                                                    <th scope="col" class="text-center">Notify Admin</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <!-- Late Check-in -->
                                                <tr>
                                                    <td><strong>Late Check-in</strong></td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="lateCheckinEmployee" checked>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="lateCheckinAdmin" checked>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <!-- OT Approval Needed -->
                                                <tr>
                                                    <td><strong>OT Approval Needed</strong></td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="otApprovalEmployee" checked>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="otApprovalAdmin" checked>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <!-- Missing Check-out Detected -->
                                                <tr>
                                                    <td><strong>Missing Check-out Detected</strong></td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="missingCheckoutEmployee" checked>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-inline-block">
                                                            <input class="form-check-input" type="checkbox"
                                                                   role="switch" id="missingCheckoutAdmin" checked>
                                                        </div>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Save/Cancel Buttons -->
                                        <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                            <button class="btn btn-primary">Save</button>
                                            <button class="btn bg-danger-subtle text-danger">Cancel</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div class="tab-pane fade"
                         id="tab-holiday"
                         role="tabpanel"
                         aria-labelledby="tab-holiday"
                         tabindex="0">
                        <!-- Your security content remains unchanged -->
                        <div>
                            <div class="row justify-content-center">
                                <div class="col-lg-10">
                                    <div class="card border shadow-none">
                                        <div class="card-body p-4">
                                            <h4 class="card-title mb-4">🏝️ Public Holidays</h4>
                                            <p class="card-subtitle mb-4">Manage company-wide holidays for automatic
                                                absence logging and schedule planning.</p>

                                            <div class="table-responsive">
                                                <table class="table align-middle mb-0" id="holidaysTable">
                                                    <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 200px;">Date</th>
                                                        <th>Occasion</th>
                                                        <th style="width: 60px;"></th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <tr>
                                                        <td><input type="date" class="form-control" value="2025-08-10"/>
                                                        </td>
                                                        <td><input type="text" class="form-control"
                                                                   value="Independence Day 🇰🇪"/></td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                    onclick="this.closest('tr').remove();">
                                                                <i class="ti ti-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><input type="date" class="form-control" value="2025-08-15"/>
                                                        </td>
                                                        <td><input type="text" class="form-control"
                                                                   value="Company Retreat 🏕️"/></td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                    onclick="this.closest('tr').remove();">
                                                                <i class="ti ti-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><input type="date" class="form-control" value="2025-09-02"/>
                                                        </td>
                                                        <td><input type="text" class="form-control" value="Labour Day"/>
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                    onclick="this.closest('tr').remove();">
                                                                <i class="ti ti-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="mt-4">
                                                <button type="button" class="btn btn-outline-primary"
                                                        onclick="addHolidayRow()">
                                                    <i class="ti ti-plus me-1"></i> Add Holiday
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Save/Cancel Buttons -->
                                    <div class="d-flex align-items-center justify-content-end gap-6 mt-4">
                                        <button class="btn btn-primary">Save</button>
                                        <button class="btn bg-danger-subtle text-danger">Cancel</button>
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


@push('scripts')
    <script src="assets/js/apps/contact.js"></script>
@endpush




