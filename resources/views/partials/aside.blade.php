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
                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                           href="{{ route('dashboard') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Dashboard</span>
                        </a>
                    </li>


                    <livewire:admin.summaries.employee-types-dropdown/>


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
                                <a class="sidebar-link" href="#">
                                    <span class="icon-small"></span>
                                    Clocked In
                                </a>
                            </li>

                            <!-- Clocked Out -->
                            <li class="sidebar-item">
                                <a class="sidebar-link" href="#">
                                    <span class="icon-small"></span>
                                    Clocked Out
                                </a>
                            </li>

                            <!-- Unchecked Out -->
                            <li class="sidebar-item">
                                <a class="sidebar-link" href="#">
                                    <span class="icon-small"></span>
                                    Unchecked Out
                                </a>
                            </li>

                            <!-- Absent -->
                            <li class="sidebar-item">
                                <a class="sidebar-link" href="#">
                                    <span class="icon-small"></span>
                                    Absent
                                </a>
                            </li>
                        </ul>
                    </li>


                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                           href="{{ route('organizations.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Organizations</span>
                        </a>
                    </li>

                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('shifts.index') ? 'active' : '' }}"
                           href="{{ route('shifts.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="mdi:factory"></iconify-icon>
                            <span class="hide-menu">Shifts</span>
                        </a>
                    </li>

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

                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('reports.organization') ? 'active' : '' }}"
                                   href="{{ route('reports.organization') }}">
                                    <span class="icon-small"></span>
                                    Organization Reports
                                </a>
                            </li>

                        </ul>
                    </li>

                </ul>
            </nav>

        </div>
    </div>
</aside>

