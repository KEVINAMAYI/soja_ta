<aside class="left-sidebar with-vertical">
    <!-- ---------------------------------- -->
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

                <li class="sidebar-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                       href="{{ route('dashboard') }}"
                       aria-expanded="{{ request()->routeIs('dashboard') ? 'true' : 'false' }}">
                        <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                        <span class="hide-menu">Dashboard</span>
                    </a>
                </li>


                <li class="sidebar-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <a class="sidebar-link {{ request()->routeIs('employees.*') ? 'active' : '' }}"
                       href="{{ route('employees.index') }}"
                       aria-expanded="{{ request()->routeIs('employees.*') ? 'true' : 'false' }}">
                        <iconify-icon icon="solar:shield-user-line-duotone"></iconify-icon>
                        <span class="hide-menu">Employees</span>
                    </a>
                </li>

                <li class="sidebar-item {{ request()->routeIs('employee-types.*') ? 'active' : '' }}">
                    <a class="sidebar-link {{ request()->routeIs('employee-types.*') ? 'active' : '' }}"
                       href="{{ route('employee-types.index') }}"
                       aria-expanded="{{ request()->routeIs('employee-types.*') ? 'true' : 'false' }}">
                        <iconify-icon icon="solar:shield-user-line-duotone"></iconify-icon>
                        <span class="hide-menu">Employee Types</span>
                    </a>
                </li>

                <li class="sidebar-item {{ request()->routeIs('organizations.*') ? 'active' : '' }}">
                    <a class="sidebar-link {{ request()->routeIs('organizations.*') ? 'active' : '' }}"
                       href="{{ route('organizations.index') }}"
                       aria-expanded="{{ request()->routeIs('organizations.*') ? 'true' : 'false' }}">
                        <iconify-icon icon="solar:shield-user-line-duotone"></iconify-icon>
                        <span class="hide-menu">Organizations</span>
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
</aside>
