<x-layouts.app :title="__('Dashboard')">
    <div class="row">

        <div class="col-lg-12">
            <div class="card text-bg-primary">
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-7">
                            <div class="d-flex flex-column h-100 py-3"> {{-- Reduced padding for height --}}
                                <div class="hstack gap-2">
                <span class="d-flex align-items-center justify-content-center round-48 bg-white rounded flex-shrink-0">
                    <iconify-icon icon="mdi:calendar-account-outline" class="fs-5 text-muted"></iconify-icon>
                </span>
                                    <div>
                                        <h6 class="text-white fs-5 mb-0">Welcome back, Kevin Amayi</h6>
                                        <small class="text-white-50">HR Administrator — Attendance System</small>
                                    </div>
                                </div>
                                <div class="mt-3 mt-sm-auto">
                                    <div class="row">
                                        <div class="col-6">
                                            <span class="text-white-50">Today's Date</span>
                                            <h5 class="mb-0 text-white mt-1 fs-6 fw-semibold">
                                                {{ now()->format('l, d M Y') }}
                                            </h5>
                                        </div>
                                        <div class="col-6 border-start border-light" style="--bs-border-opacity: .15;">
                                            <span class="text-white-50">Total Employees</span>
                                            <h5 class="mb-0 text-white mt-1 fs-6 fw-semibold">191</h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Right side illustration (optional) --}}
                        <div class="col-sm-5 text-center text-md-end">
                            <img src="{{ asset('assets/images/backgrounds/welcome-bg.png') }}"
                                 alt="welcome" class="img-fluid mb-n4 mt-2" width="150" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body p-4 pb-0" data-simplebar>
                    <div class="row flex-nowrap">

                        {{-- Total Employees --}}
                        <div class="col">
                            <div class="card primary-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div
                                        class="d-flex align-items-center justify-content-center round-48 rounded text-bg-primary flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:account-group-outline"
                                                      class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Total Employees</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        191
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View
                                        Details</a>
                                </div>
                            </div>
                        </div>


                        {{-- Present Employees --}}
                        <div class="col">
                            <div class="card success-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div
                                        class="d-flex align-items-center justify-content-center round-48 rounded text-bg-success flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:account-check-outline"
                                                      class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Present</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        152
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View
                                        Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- Absent Employees --}}
                        <div class="col">
                            <div class="card danger-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div
                                        class="d-flex align-items-center justify-content-center round-48 rounded text-bg-danger flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:account-cancel-outline"
                                                      class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Absent</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        27
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View
                                        Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- On Leave Employees --}}
                        <div class="col">
                            <div class="card warning-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div
                                        class="d-flex align-items-center justify-content-center round-48 rounded text-bg-warning flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:beach" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">On Leave</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        12
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View
                                        Details</a>
                                </div>
                            </div>
                        </div>

                    </div> <!-- end row -->
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
</x-layouts.app>


