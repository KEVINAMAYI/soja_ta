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
                        <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                           href="{{ route('attendance.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Attendance</span>
                        </a>
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
                        <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                           href="{{ route('organizations.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                            <span class="hide-menu">Organizations</span>
                        </a>
                    </li>

                    <li class="sidebar-item">
                        <a class="sidebar-link {{ request()->routeIs('reports.index') ? 'active' : '' }}"
                           href="{{ route('reports.index') }}"
                           id="get-url"
                           aria-expanded="false">
                            <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                            <span class="hide-menu">Reports</span>
                        </a>
                    </li>

                </ul>
            </nav>

        </div>
    </div>
</aside>

