<style>
    /* Dropdown Container */
    .sidebar-item.has-dropdown {
        position: relative;
    }

    /* Main Dropdown Link */
    .sidebar-item.has-dropdown > .sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    /* Dropdown Icon Rotation */
    .dropdown-icon {
        margin-left: auto;
        transition: transform 0.3s ease;
    }

    .sidebar-item.has-dropdown.active .dropdown-icon {
        transform: rotate(180deg);
    }

    /* Dropdown Menu */
    .sidebar-dropdown {
        list-style: none;
        padding: 0;
        margin: 0;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background-color: transparent;
    }

    .sidebar-item.has-dropdown.active .sidebar-dropdown {
        max-height: 500px;
    }

    /* Dropdown Items */
    .sidebar-dropdown li {
        list-style: disc;
        margin-left: 45px;
    }

    .sidebar-sublink {
        display: inline-block;
        padding: 8px 10px;
        text-decoration: none;
        color: grey !important;;
        transition: all 0.3s ease;
        font-size: 14px;
    }

    /* Hover and Active States - Primary Color */
    .sidebar-sublink:hover {
        color: var(--primary-color) !important;;
        font-weight: 500;
        padding-left: 15px;
    }

    .sidebar-sublink.active {
        color: var(--primary-color) !important;;
        font-weight: 600;
    }

    /* Parent Link Hover */
    .sidebar-item.has-dropdown > .sidebar-link:hover {
        background-color: rgba(93, 135, 255, 0.1);
    }

    .sidebar-item.has-dropdown.active > .sidebar-link {
        background-color: rgba(93, 135, 255, 0.15);
    }
</style>

<aside class="left-sidebar with-vertical">
    <div>
        <div>
            <div class="brand-logo mb-3 mt-3 d-flex justify-content-center align-items-center" style="height: 100px;">
                <img style="margin-left:-10px;" height="60" width="200" src="assets/images/logos/soja_ta_logo.png"
                     alt="Logo"/>
            </div>

            <nav class="sidebar-nav scroll-sidebar" data-simplebar>
                <ul class="sidebar-menu" id="sidebarnav">

                    @can('view-dashboard')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                               href="{{ route('dashboard') }}"
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
                               aria-expanded="false">
                                <iconify-icon icon="mdi:chart-line" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Analytics</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                               href="{{ route('employees.index') }}"
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
                               aria-expanded="false">
                                <iconify-icon icon="mdi:exit-run" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Leave Requests</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-employees')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('shifts.coverage') ? 'active' : '' }}"
                               href="{{ route('shifts.coverage') }}"
                               aria-expanded="false">
                                <iconify-icon icon="mdi:account-clock-outline" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Shift Monitoring</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-all-attendance')
                        <li class="sidebar-item">
                            <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                               href="{{ route('attendance.index') }}"
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
                               aria-expanded="false">
                                <iconify-icon icon="mdi:office-building-outline" class="fs-5"></iconify-icon>
                                <span class="hide-menu">Clients</span>
                            </a>
                        </li>
                    @endcan

                    @can('view-all-reports')
                        <li class="sidebar-item has-dropdown">
                            <a href="javascript:void(0);"
                               class="sidebar-link"
                               onclick="toggleReportsDropdown(event)">
                                <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                                <span class="hide-menu">All Reports</span>
                                <iconify-icon class="dropdown-icon" icon="mdi:chevron-down"></iconify-icon>
                            </a>

                            <ul class="sidebar-dropdown">
                                <li><a href="{{ route('reports.detailed') }}" class="sidebar-sublink">Detailed
                                        Reports</a></li>
                                <li><a href="{{ route('reports.summary') }}" class="sidebar-sublink">Summary Reports</a>
                                </li>
                                <li><a href="{{ route('reports.scheduled') }}" class="sidebar-sublink">Report
                                        Schedules</a></li>
                            </ul>
                        </li>
                    @endcan

                </ul>
            </nav>
        </div>
    </div>
</aside>

<script>
    function toggleReportsDropdown(event) {
        event.preventDefault();

        // Get the parent li element
        const parentLi = event.currentTarget.closest('.sidebar-item');

        // Close other dropdowns
        document.querySelectorAll('.sidebar-item.has-dropdown').forEach(item => {
            if (item !== parentLi) {
                item.classList.remove('active');
            }
        });

        // Toggle current dropdown
        parentLi.classList.toggle('active');
    }

    // Set active class on current page
    document.addEventListener('DOMContentLoaded', function () {
        const currentUrl = window.location.href;
        const sublinks = document.querySelectorAll('.sidebar-sublink');

        sublinks.forEach(link => {
            if (link.href === currentUrl) {
                link.classList.add('active');
                // Also open the parent dropdown
                link.closest('.sidebar-item.has-dropdown').classList.add('active');
            }
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.sidebar-item.has-dropdown')) {
            document.querySelectorAll('.sidebar-item.has-dropdown').forEach(item => {
                item.classList.remove('active');
            });
        }
    });
</script>
