<aside class="left-sidebar with-vertical">
    <div><!-- ---------------------------------- -->
        <!-- Start Vertical Layout Sidebar -->
        <!-- ---------------------------------- -->

        <div>

            <div class="brand-logo mb-3 mt-3 d-flex justify-content-center align-items-center" style="height: 100px;">
                <img style="margin-left:-10px;" height="60" width="200" src="assets/images/logos/soja_ta_logo.png"
                     alt="Logo"/>
            </div>


            <!-- ---------------------------------- -->
            <!-- Dashboard -->
            <!-- ---------------------------------- -->
            <nav class="sidebar-nav scroll-sidebar" data-simplebar>
                <ul class="sidebar-menu" id="sidebarnav">


                    <!-- ---------------------------------- -->
                    <!-- Dashboard -->
                    <!-- ---------------------------------- -->
                    @can('view-dashboard')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                               href="{{ route('dashboard') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                                <span class="hide-menu">Dashboard</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('analytics') ? 'active' : '' }}"
                               href="{{ route('analytics') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:chart-line" class="fs-5"></iconify-icon>
                                <span class="hide-menu">
                                    Analytics
                                </span>
                            </a>
                        </li>
                    @endcan

                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                               href="{{ route('employees.index') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:office-building-outline" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Employees</span>
                            </a>
                        </li>
                    @endcan



                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('leaves.index') ? 'active' : '' }}"
                               href="{{ route('leaves.index') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:exit-run" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Leave Requests</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('shifts.create') ? 'active' : '' }}"
                               href="{{ route('shifts.create') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:account-clock-outline" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Shifts</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-all-attendance')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                               href="{{ route('attendance.index') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:clock-time-eight-outline"></iconify-icon>
                                <span class="hide-menu">Attendance</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-all-attendance')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('timesheet.index') ? 'active' : '' }}"
                               href="{{ route('timesheets.index') }}"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:clipboard-clock-outline"></iconify-icon>
                                <span class="hide-menu">Timesheets</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-organizations')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                               href="{{ route('organizations.index') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:office-building-outline" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Clients</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-all-reports')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('reports.employees') ? 'active' : '' }}"
                               href="{{ route('reports.employees') }}"
                               id="get-url"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                                <span class="hide-menu">All Reports</span>
                            </a>
                        </li>
                    @endcan

                </ul>
            </nav>

        </div>
    </div>
</aside>

