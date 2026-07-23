<style>
    .sidebar-item .sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 16px;
        margin: 2px 8px;
        border-radius: 8px;
        cursor: pointer;
        color: #1f2937;
        font-weight: 500;
        text-decoration: none !important;
        font-size: 14.5px;
        transition: all 0.15s ease;
    }

    .sidebar-item .sidebar-link:hover {
        background-color: rgba(93, 135, 255, 0.08);
        color: #111827;
    }

    .sidebar-item .sidebar-link.active {
        background-color: #e8443c;
        color: #fff !important;
        font-weight: 600;
    }

    .sidebar-item .sidebar-link.active iconify-icon {
        color: #fff !important;
    }

    .sidebar-item .sidebar-link iconify-icon {
        font-size: 19px;
        color: #4b5563;
        flex-shrink: 0;
    }

    /* Section labels (Overview, Workforce, Time and attendance, Leave, Reports, Client accounts) */
    .sidebar-section-label {
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: none;
        color: #6b7280;
        padding: 16px 16px 4px;
        margin: 0;
    }

    .sidebar-section-label:first-child {
        padding-top: 8px;
    }

    aside.left-sidebar {
        display: flex;
        flex-direction: column;
        height: 100vh;
    }

    aside.left-sidebar > div {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }

    aside.left-sidebar > div > div {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }

    .brand-logo {
        flex-shrink: 0;
    }

    .scroll-sidebar {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
    }

    .sidebar-footer-help {
        flex-shrink: 0;
        margin-top: auto;
    }

    .sidebar-footer-help .sidebar-link {
        padding: 10px 16px;
        color: #4b5563;
        text-decoration: none;
    }

    .sidebar-footer-help .sidebar-link:hover {
        color: var(--primary-color);
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

                    $orgId = auth()->user()->employee?->organization_id;
                    $orgSettings = \App\Models\Organization::find($orgId)?->settings
                        ->mapWithKeys(fn($item) => [$item->key => $item->value])
                        ->toArray() ?? [];

                    $showHelpIcon = (bool)($orgSettings['show_help_icon'] ?? false);
                    $helpPageUrl = $orgSettings['help_page_url'] ?? '';
                    $helpTooltipLabel = $orgSettings['help_icon_tooltip_label'] ?? 'Help';

                    $pendingCheckinCount = \App\Models\CheckInApprovalRequest::where('organization_id', auth()->user()->employee?->organization_id)
                        ->where('status', 'pending')
                        ->count();
                @endphp

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- SCHOOL SIDEBAR                                          --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                @if($isSchool)

                    <ul class="sidebar-menu" id="sidebarnav">

                        <p class="sidebar-section-label">Overview</p>

                        @can('view-dashboard')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                                   href="{{ route('dashboard') }}">
                                    <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                                    <span class="hide-menu">Dashboard</span>
                                </a>
                            </li>
                        @endcan

                        @canany(['view-students', 'view-employees'])
                            <p class="sidebar-section-label">Workforce</p>
                        @endcanany

                        @can('view-students')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') && request()->query('type') === 'student' ? 'active' : '' }}"
                                   href="{{ route('employees.index', ['type' => 'student']) }}">
                                    <iconify-icon icon="mdi:account-school-outline"></iconify-icon>
                                    <span class="hide-menu">Students</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') && request()->query('type') === 'staff' ? 'active' : '' }}"
                                   href="{{ route('employees.index', ['type' => 'staff']) }}">
                                    <iconify-icon icon="mdi:account-tie-outline"></iconify-icon>
                                    <span class="hide-menu">Staff</span>
                                </a>
                            </li>
                        @endcan

                        @canany(['view-school-attendance', 'approve-checkin-requests'])
                            <p class="sidebar-section-label">Time and attendance</p>
                        @endcanany

                        @can('view-school-attendance')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                                   href="{{ route('attendance.index') }}">
                                    <iconify-icon icon="mdi:calendar-check-outline"></iconify-icon>
                                    <span class="hide-menu">Attendance</span>
                                </a>
                            </li>
                        @endcan

                        @can('approve-checkin-requests')
                            <li class="sidebar-item">
                                <a class="sidebar-link d-flex align-items-center {{ request()->routeIs('checkin-requests.index') ? 'active' : '' }}"
                                   href="{{ route('checkin-requests.index') }}">
                                    <iconify-icon icon="mdi:clock-alert-outline"></iconify-icon>
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
                                    <iconify-icon icon="mdi:arrow-right-circle-outline"></iconify-icon>
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
                                    <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
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

                        <p class="sidebar-section-label">Overview</p>

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
                                    <iconify-icon icon="mdi:chart-line"></iconify-icon>
                                    <span class="hide-menu">Analytics</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <p class="sidebar-section-label">Workforce</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                                   href="{{ route('employees.index') }}">
                                    <iconify-icon icon="mdi:account-group-outline"></iconify-icon>
                                    <span class="hide-menu">Employees</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-employees')
                            <p class="sidebar-section-label">Time and attendance</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('shifts.coverage') ? 'active' : '' }}"
                                   href="{{ route('shifts.coverage') }}">
                                    <iconify-icon icon="mdi:account-clock-outline"></iconify-icon>
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
                                    <iconify-icon icon="mdi:clock-alert-outline"></iconify-icon>
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

                        @php
                            $canSeeLeaveRequests = auth()->user()->can('view-employees')
                                || auth()->user()->can('approve-leave-requests')
                                || (auth()->user()->employee
                                    && app(\App\Services\LeaveApprovalService::class)->hasAnyPendingApprovalFor(auth()->user(), auth()->user()->employee->organization_id));
                        @endphp
                        @if($canSeeLeaveRequests || auth()->user()->can('approve-leave-requests'))
                            <p class="sidebar-section-label">Leave</p>
                        @endif

                        @if($canSeeLeaveRequests)
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('leaves.index') ? 'active' : '' }}"
                                   href="{{ route('leaves.index') }}">
                                    <iconify-icon icon="mdi:exit-run"></iconify-icon>
                                    <span class="hide-menu">Leave Requests</span>
                                </a>
                            </li>
                        @endif

                        @can('approve-leave-requests')
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('leave-balances.index') ? 'active' : '' }}"
                                   href="{{ route('leave-balances.index') }}">
                                    <iconify-icon icon="mdi:calendar-account-outline"></iconify-icon>
                                    <span class="hide-menu">Leave Balances</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-all-reports')
                            <p class="sidebar-section-label">Reports</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('reports.detailed') ? 'active' : '' }}"
                                   href="{{ route('reports.detailed') }}">
                                    <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                                    <span class="hide-menu">Detailed Reports</span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('reports.summary') ? 'active' : '' }}"
                                   href="{{ route('reports.summary') }}">
                                    <iconify-icon icon="mdi:chart-box-outline"></iconify-icon>
                                    <span class="hide-menu">Summary Reports</span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('reports.scheduled') ? 'active' : '' }}"
                                   href="{{ route('reports.scheduled') }}">
                                    <iconify-icon icon="mdi:calendar-clock-outline"></iconify-icon>
                                    <span class="hide-menu">Report Schedules</span>
                                </a>
                            </li>
                        @endcan

                        @can('view-organizations')
                            <p class="sidebar-section-label">Client accounts</p>
                            <li class="sidebar-item">
                                <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                                   href="{{ route('organizations.index') }}">
                                    <iconify-icon icon="mdi:office-building-outline"></iconify-icon>
                                    <span class="hide-menu">Clients</span>
                                </a>
                            </li>
                        @endcan

                    </ul>

                @endif

            </nav>

            @if($showHelpIcon && $helpPageUrl)
                <div class="sidebar-footer-help border-top mt-2 pt-2 pb-3">
                    <a href="{{ $helpPageUrl }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="sidebar-link d-flex align-items-center gap-2"
                       title="{{ $helpTooltipLabel }}">
                        <iconify-icon icon="mdi:help-circle-outline"></iconify-icon>
                        <span class="hide-menu">{{ $helpTooltipLabel }}</span>
                    </a>
                </div>
            @endif
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
