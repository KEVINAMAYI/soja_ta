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

                    @can('view-all-attendance')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('attendance.*') ? 'active' : '' }}"
                               href="#timesheetsMenu"
                               data-bs-toggle="collapse"
                               aria-expanded="{{ request()->routeIs('attendance.*') ? 'true' : 'false' }}">
                                <iconify-icon icon="mdi:clock-time-eight-outline"></iconify-icon>
                                <span class="hide-menu">Timesheets</span>
                            </a>
                            <ul class="collapse first-level {{ request()->routeIs('attendance.*') ? 'show' : '' }}"
                                id="timesheetsMenu">

                                <!-- All Timesheets -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                                       href="{{ route('attendance.index') }}">
                                        <span class="icon-small"></span>
                                        All Timesheets
                                    </a>
                                </li>

                                <!-- Clocked In -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'clocked_in' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'clocked_in']) }}">
                                        <span class="icon-small"></span> Clocked In
                                    </a>
                                </li>

                                <!-- Clocked Out -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'clocked_out' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'clocked_out']) }}">
                                        <span class="icon-small"></span> Clocked Out
                                    </a>
                                </li>

                                <!-- Absent -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'absent' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'absent']) }}">
                                        <span class="icon-small"></span> Absent
                                    </a>
                                </li>

                                <!-- Unchecked In -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'off_shift' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'off_shift']) }}">
                                        <span class="icon-small"></span> Off Shift
                                    </a>
                                </li>

                                <!-- Unchecked In -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'suck_off' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'sick_off']) }}">
                                        <span class="icon-small"></span> Sick Off
                                    </a>
                                </li>

                                <!-- Absent -->
                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('attendance.status.index') && request()->status === 'on_leave' ? 'active' : '' }}"
                                       href="{{ route('attendance.status.index', ['status' => 'on_leave']) }}">
                                        <span class="icon-small"></span> On Leave
                                    </a>
                                </li>
                            </ul>
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
                            <a class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"
                               href="#reportsMenu"
                               data-bs-toggle="collapse"
                               aria-expanded="{{ request()->routeIs('reports.*') ? 'true' : 'false' }}"
                               aria-controls="reportsMenu">
                                <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                                <span class="hide-menu">Reports</span>
                            </a>

                            <ul class="collapse first-level {{ request()->routeIs('reports.*') ? 'show' : '' }}"
                                id="reportsMenu">

                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('reports.employees') ? 'active' : '' }}"
                                       href="{{ route('reports.employees') }}">
                                        <span class="icon-small"></span>
                                        Employee Reports
                                    </a>
                                </li>

                                <li class="sidebar-item">
                                    <a class="sidebar-link {{ request()->routeIs('reports.departments') ? 'active' : '' }}"
                                       href="{{ route('reports.departments') }}">
                                        <span class="icon-small"></span>
                                        Department Reports
                                    </a>
                                </li>

                            </ul>
                        </li>
                    @endcan

                </ul>
            </nav>

        </div>
    </div>
</aside>

