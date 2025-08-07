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


                    <li class="sidebar-item">
                        <a class="sidebar-link has-arrow" href="javascript:void(0)" aria-expanded="false">
                            <iconify-icon icon="solar:home-angle-line-duotone"></iconify-icon>
                            <span class="hide-menu">Attendance</span>
                        </a>
                        <ul aria-expanded="false" class="collapse first-level">
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('attendance.daily') ? 'text-primary fw-bold' : '' }}"
                                   href="{{ route('attendance.daily') }}">
                                    <span class="icon-small"></span>
                                    <span class="hide-menu">Daily Attendance</span>
                                </a>
                            </li>

                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('attendance.monthly') ? 'text-primary fw-bold' : '' }}"
                                   href="{{ route('attendance.monthly') }}">
                                    <span class="icon-small"></span>
                                    <span class="hide-menu">Monthly Attendance</span>
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- ---------------------------------- -->
                    <!-- Dashboard -->
                    <!-- ---------------------------------- -->
                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('overtime.index') ? 'active' : '' }}"
                           href="{{ route('overtime.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="mdi:account-check-outline"></iconify-icon>
                            <span class="hide-menu">Overtime</span>
                        </a>
                    </li>

                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                           href="{{ route('employees.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Employees</span>
                        </a>
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
                        <a class="sidebar-link {{ request()->routeIs('employee-types.index') ? 'active' : '' }}"
                           href="{{ route('employee-types.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Employee Types</span>
                        </a>
                    </li>


                    <!-- ---------------------------------- -->
                    <!-- Dashboard -->
                    <!-- ---------------------------------- -->
                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('settings.index') ? 'active' : '' }}"
                           href="{{ route('settings.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="mdi:tune-variant"></iconify-icon>
                            <span class="hide-menu">Settings</span>
                        </a>
                    </li>


                    <li class="sidebar-item">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="sidebar-link w-100 text-start border-0 bg-transparent d-flex align-items-center gap-2 px-3 py-2">
                                <iconify-icon icon="solar:logout-2-line-duotone"></iconify-icon>
                                <span class="hide-menu">Logout</span>
                            </button>
                        </form>
                    </li>

                </ul>
            </nav>

        </div>
    </div>
</aside>

