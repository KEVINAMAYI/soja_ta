<x-layouts.app :title="__('Dashboard')">
    <div class="row">

        <div class="col-lg-12">
            <div class="card text-bg-primary">
                <div class="card-body">
                    <div class="row mb-0">
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between py-1"> <!-- reduce vertical padding -->

                                <!-- Left: Icon + Welcome -->
                                <div class="d-flex align-items-center gap-2">
                                     <span class="d-flex align-items-center justify-content-center rounded-circle bg-white flex-shrink-0" style="width:28px; height:28px;">
                                         <iconify-icon icon="mdi:calendar-account-outline" class="fs-6 text-muted"></iconify-icon>
                                     </span>
                                    <div class="lh-1"> <!-- line-height 1 to reduce spacing -->
                                        <div class="text-white fw-medium" style="font-size:0.875rem;">Welcome back, Kevin</div>
                                        <small class="text-white-50" style="font-size:0.75rem;">HR Admin — Attendance</small>
                                    </div>
                                </div>

                                <!-- Right: Date -->
                                <div class="text-end lh-1">
                                    <small class="text-white-50" style="font-size:0.75rem;">Today</small><br>
                                    <span class="text-white fw-semibold" style="font-size:0.875rem;">{{ now()->format('l, d M Y') }}</span>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body p-4 pb-0" data-simplebar>
                    <livewire:admin.summaries.employee-statuses />
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
        <livewire:admin.summaries.daily-attendance-pie-chart />



        <div class="col-12">
            <div class="card card-body">

                {{-- Top Bar: Title + View More --}}
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">

                    {{-- Left side: Title --}}
                    <h5 class="mb-0">Recent Employee Check-ins</h5>

                    {{-- Right side: View More button --}}
                    <a href="{{ route('attendance.index') }}" class="btn btn-primary d-flex align-items-center">
                        <i class="ti ti-eye fs-5 me-2"></i> View All
                    </a>
                </div>

                {{-- Livewire Table --}}
                <livewire:latest-checkins-table theme="bootstrap-4"/>

            </div>
        </div>


        <div class="col-12">

            <div class="card card-body">

                {{-- Top Bar: Title + View More --}}
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">

                    {{-- Left side: Title --}}
                    <h5 class="mb-0">Top Departments This Month</h5>

                    {{-- Right side: View More button --}}
                    <a href="{{ route('reports.departments') }}" class="btn btn-primary d-flex align-items-center">
                        <i class="ti ti-eye fs-5 me-2"></i> View All
                    </a>

                </div>

                {{-- Livewire Table --}}
                <livewire:top-departments-table theme="bootstrap-4"/>

            </div>
        </div>


    </div>
</x-layouts.app>


