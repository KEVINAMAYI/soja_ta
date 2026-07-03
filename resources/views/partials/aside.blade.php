<style>
    .sidebar-item.has-dropdown { position: relative; }
    .sidebar-item.has-dropdown > .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 12px 16px; cursor: pointer; transition: all 0.3s ease; }
    .dropdown-icon { margin-left: auto; transition: transform 0.3s ease; }
    .sidebar-item.has-dropdown.active .dropdown-icon { transform: rotate(180deg); }
    .sidebar-dropdown { list-style: none; padding: 0; margin: 0; max-height: 0; overflow: hidden; transition: max-height 0.3s ease; background-color: transparent; }
    .sidebar-item.has-dropdown.active .sidebar-dropdown { max-height: 500px; }
    .sidebar-dropdown li { list-style: disc; margin-left: 45px; }
    .sidebar-sublink { display: inline-block; padding: 8px 10px; text-decoration: none; color: grey !important; transition: all 0.3s ease; font-size: 14px; }
    .sidebar-sublink:hover { color: var(--primary-color) !important; font-weight: 500; padding-left: 15px; }
    .sidebar-sublink.active { color: var(--primary-color) !important; font-weight: 600; }
    .sidebar-item.has-dropdown > .sidebar-link:hover { background-color: rgba(93, 135, 255, 0.1); }
    .sidebar-item.has-dropdown.active > .sidebar-link { background-color: rgba(93, 135, 255, 0.15); }

    /* Section labels (MAIN, EVENTS, REPORTS) */
    .sidebar-section-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6c757d;
        padding: 16px 16px 6px;
        margin: 0;
    }
</style>

<aside {{ $sidebarBg ? "style=background-color:{$sidebarBg};" : '' }} class="left-sidebar with-vertical">
    <div>
        <div>

            <div class="brand-logo mb-3 mt-3 d-flex justify-content-center align-items-center" style="height: 100px;">
                <livewire:admin.account-settings.org_logo/>
            </div>



            <nav class="sidebar-nav scroll-sidebar" data-simplebar>

                @php
                    $isSchool = auth()->user()->employee?->organization?->is_student_record;
                    $pendingCheckinCount = \App\Models\CheckInApprovalRequest::where('organization_id', auth()->user()->employee?->organization_id)
                        ->where('status', 'pending')
                        ->count();
                @endphp

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- SCHOOL SIDEBAR                                          --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                @if($isSchool)

                    <ul class="sidebar-menu" id="sidebarnav">

                        @can('view-dashboard')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                                   href="{{ route('dashboard') }}">
                                    <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                                    <span class="hide-menu">Dashboard</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-students')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') && request()->query('type') === 'student' ? 'active' : '' }}"
                                   href="{{ route('employees.index', ['type' => 'student']) }}">
                                    <iconify-icon icon="mdi:account-school-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Students</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') && request()->query('type') === 'staff' ? 'active' : '' }}"
                                   href="{{ route('employees.index', ['type' => 'staff']) }}">
                                    <iconify-icon icon="mdi:account-tie-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Staff</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-school-attendance')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                                   href="{{ route('attendance.index') }}">
                                    <iconify-icon icon="mdi:calendar-check-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Attendance</span>
                                </a>
                            </li>
                        @endcan

                        @can('approve-checkin-requests')
                            <li class="sidebar-item">
                                <a class="sidebar-link d-flex align-items-center {{ request()->routeIs('checkin-requests.index') ? 'active' : '' }}"
                                   href="{{ route('checkin-requests.index') }}">
                                    <iconify-icon icon="mdi:clock-alert-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Check-in Requests</span>
                                    @if($pendingCheckinCount > 0)
                                        <span class="badge bg-danger rounded-pill ms-auto">{{ $pendingCheckinCount }}</span>
                                    @endif
                                </a>
                            </li>
                        @endcan

                        {{-- EVENTS section --}}
                        @can('manage-special-activities')
                            <p class="sidebar-section-label">Events</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('special-activities.index') ? 'active' : '' }}"
                                   href="{{ route('special-activities.index') }}">
                                    <iconify-icon icon="mdi:arrow-right-circle-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Special Activities</span>
                                </a>
                            </li>
                        @endcan

                        {{-- REPORTS section --}}
                        @can('view-all-reports')
                            <p class="sidebar-section-label">Reports</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"
                                   href="{{ route('reports.school-summary') }}">
                                    <iconify-icon icon="mdi:file-chart-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">All Reports</span>
                                </a>
                            </li>
                        @endcan

                    </ul>

                    {{-- ══════════════════════════════════════════════════════ --}}
                    {{-- REGULAR ORG SIDEBAR                                     --}}
                    {{-- ══════════════════════════════════════════════════════ --}}
                @else

                    <ul class="sidebar-menu" id="sidebarnav">

                        @can('view-dashboard')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                                   href="{{ route('dashboard') }}">
                                    <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                                    <span class="hide-menu">Dashboard</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('analytics') ? 'active' : '' }}"
                                   href="{{ route('analytics') }}">
                                    <iconify-icon icon="mdi:chart-line" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Analytics</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                                   href="{{ route('employees.index') }}">
                                    <iconify-icon icon="mdi:account-group-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Employees</span>
                                </a>
                            </li>
                        @endcan

                        @php
                            $canSeeLeaveRequests = auth()->user()->can('view-employees')
                                || auth()->user()->can('approve-leave-requests')
                                || (auth()->user()->employee
                                    && app(\App\Services\LeaveApprovalService::class)->hasAnyPendingApprovalFor(auth()->user(), auth()->user()->employee->organization_id));
                        @endphp
                        @if($canSeeLeaveRequests)
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('leaves.index') ? 'active' : '' }}"
                                   href="{{ route('leaves.index') }}">
                                    <iconify-icon icon="mdi:exit-run" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Leave Requests</span>
                                </a>
                            </li>
                        @endif

                        @can('approve-leave-requests')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('leave-balances.index') ? 'active' : '' }}"
                                   href="{{ route('leave-balances.index') }}">
                                    <iconify-icon icon="mdi:calendar-account-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Leave Balances</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('shifts.coverage') ? 'active' : '' }}"
                                   href="{{ route('shifts.coverage') }}">
                                    <iconify-icon icon="mdi:account-clock-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Shift Monitoring</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-all-attendance')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                                   href="{{ route('attendance.index') }}">
                                    <iconify-icon icon="mdi:clock-time-eight-outline"></iconify-icon>
                                    <span class="hide-menu">Attendance</span>
                                </a>
                            </li>
                        @endcan

                        @can('approve-checkin-requests')
                            <li class="sidebar-item">
                                <a class="sidebar-link d-flex align-items-center {{ request()->routeIs('checkin-requests.index') ? 'active' : '' }}"
                                   href="{{ route('checkin-requests.index') }}">
                                    <iconify-icon icon="mdi:clock-alert-outline" class="fs-5"></iconify-icon>
                                    <span class="hide-menu">Check-in Requests</span>
                                    @if($pendingCheckinCount > 0)
                                        <span class="badge bg-danger rounded-pill ms-auto">{{ $pendingCheckinCount }}</span>
                                    @endif
                                </a>
                            </li>
                        @endcan

                        @can('view-all-attendance')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('timesheet.index') ? 'active' : '' }}"
                                   href="{{ route('timesheets.index') }}">
                                    <iconify-icon icon="mdi:clipboard-clock-outline"></iconify-icon>
                                    <span class="hide-menu">Timesheets</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-organizations')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                                   href="{{ route('organizations.index') }}">
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
                                    <li><a href="{{ route('reports.detailed') }}" class="sidebar-sublink">Detailed Reports</a></li>
                                    <li><a href="{{ route('reports.summary') }}" class="sidebar-sublink">Summary Reports</a></li>
                                    <li><a href="{{ route('reports.scheduled') }}" class="sidebar-sublink">Report Schedules</a></li>
                                </ul>
                            </li>
                        @endcan

                    </ul>

                @endif

            </nav>
        </div>
    </div>
</aside>

<script>
    function toggleReportsDropdown(event) {
        event.preventDefault();
        const parentLi = event.currentTarget.closest('.sidebar-item');
        document.querySelectorAll('.sidebar-item.has-dropdown').forEach(item => {
            if (item !== parentLi) item.classList.remove('active');
        });
        parentLi.classList.toggle('active');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const currentUrl = window.location.href;
        document.querySelectorAll('.sidebar-sublink').forEach(link => {
            if (link.href === currentUrl) {
                link.classList.add('active');
                const parent = link.closest('.sidebar-item.has-dropdown');
                if (parent) parent.classList.add('active');
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.sidebar-item.has-dropdown')) {
            document.querySelectorAll('.sidebar-item.has-dropdown').forEach(item => {
                item.classList.remove('active');
            });
        }
    });
</script>
