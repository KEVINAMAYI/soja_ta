<style>
    /* ============================================================
     LAYOUT / SCROLL SCAFFOLDING — required for sidebar scrolling
     ============================================================ */
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

    /* ============================================================
       LIST RESETS
       ============================================================ */
    .sidebar-menu,
    .sidebar-dropdown {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .sidebar-item {
        margin: 0;
        padding: 0;
    }

    /* ============================================================
       LINK STYLING — single source of truth
       ============================================================ */
    .sidebar-item .sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 7px 12px;
        margin: 1px 4px;
        border-radius: 8px;
        cursor: pointer;
        color: #374151;
        font-weight: 500;
        font-size: 13.5px;
        line-height: 1.3;
        text-decoration: none !important;
        white-space: nowrap;
        position: relative;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .sidebar-item .sidebar-link iconify-icon {
        font-size: 17px;
        color: #6b7280;
        flex-shrink: 0;
        width: 20px;
        display: flex;
        justify-content: center;
        transition: color 0.15s ease;
    }

    .sidebar-item .sidebar-link:hover {
        background-color: rgba(93, 135, 255, 0.08);
        color: #111827;
    }

    .sidebar-item .sidebar-link:hover iconify-icon {
        color: #374151;
    }

    /* Active state uses the theme's dynamic primary color */
    .sidebar-item .sidebar-link.active {
        background-color: color-mix(in srgb, var(--primary-color) 12%, transparent);
        color: var(--primary-color);
        font-weight: 600;
    }

    .sidebar-item .sidebar-link.active::before {
        content: '';
        position: absolute;
        left: -4px;
        top: 4px;
        bottom: 4px;
        width: 3px;
        border-radius: 0 3px 3px 0;
        background-color: var(--primary-color);
    }

    .sidebar-item .sidebar-link.active iconify-icon {
        color: var(--primary-color);
    }

    /* ============================================================
       DROPDOWN SUB-MENU (kept for any future dropdown items)
       ============================================================ */
    .dropdown-icon {
        margin-left: auto;
        transition: transform 0.3s ease;
    }

    .sidebar-item.has-dropdown.active .dropdown-icon {
        transform: rotate(180deg);
    }

    .sidebar-dropdown {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background-color: transparent;
    }

    .sidebar-item.has-dropdown.active .sidebar-dropdown {
        max-height: 500px;
    }

    .sidebar-dropdown li {
        list-style: disc;
        margin-left: 45px;
    }

    .sidebar-sublink {
        display: inline-block;
        padding: 8px 10px;
        text-decoration: none;
        color: #6b7280 !important;
        transition: all 0.3s ease;
        font-size: 13px;
    }

    .sidebar-sublink:hover {
        color: var(--primary-color) !important;
        font-weight: 500;
        padding-left: 15px;
    }

    .sidebar-sublink.active {
        color: var(--primary-color) !important;
        font-weight: 600;
    }

    .sidebar-item.has-dropdown > .sidebar-link:hover {
        background-color: rgba(93, 135, 255, 0.08);
    }

    .sidebar-item.has-dropdown.active > .sidebar-link {
        background-color: color-mix(in srgb, var(--primary-color) 10%, transparent);
    }

    /* ============================================================
       ACCORDION SECTIONS (replaces static section labels)
       ============================================================ */
    .sidebar-menu {
        padding: 10px 8px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .sidebar-section {
        border-radius: 8px;
    }

    .sidebar-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        background-color: #f3f4f6;
        border: none;
        padding: 11px 14px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #6b7280;
        border-radius: 8px;
        transition: background-color 0.15s ease;
        font-family: inherit;
    }

    .sidebar-section-header:hover {
        background-color: #e5e7eb;
    }

    .sidebar-section-header .section-chevron {
        font-size: 17px;
        color: #9ca3af;
        flex-shrink: 0;
        transition: transform 0.2s ease;
        transform: rotate(0deg);
    }

    .sidebar-section.open .sidebar-section-header .section-chevron {
        transform: rotate(90deg);
    }

    .sidebar-section.open .sidebar-section-header {
        background-color: #eef1f5;
        color: #374151;
    }

    .sidebar-section-items {
        list-style: none;
        margin: 0;
        padding: 0 2px;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.25s ease, padding 0.25s ease;
    }

    .sidebar-section.open .sidebar-section-items {
        max-height: 800px;
        padding: 6px 2px 4px;
    }

    /* ============================================================
       FOOTER HELP LINK
       ============================================================ */
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
                @endphp

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- SCHOOL SIDEBAR                                          --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                @if($isSchool)

                    @php
                        $overviewActive = request()->routeIs('dashboard');
                        $workforceActive = (request()->routeIs('employees.index') && request()->query('type') === 'student')
                            || (request()->routeIs('employees.index') && request()->query('type') === 'staff');
                        $timeAttendanceActive = request()->routeIs('attendance.index');
                        $eventsActive = request()->routeIs('special-activities.index');
                        $reportsActive = request()->routeIs('reports.*');
                    @endphp

                    <ul class="sidebar-menu" id="sidebarnav">

                        @can('view-dashboard')
                            <li class="sidebar-section {{ $overviewActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Overview</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                                           href="{{ route('dashboard') }}">
                                            <iconify-icon icon="solar:widget-add-line-duotone"></iconify-icon>
                                            <span class="hide-menu">Dashboard</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan

                        @canany(['view-students', 'view-employees'])
                            <li class="sidebar-section {{ $workforceActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Workforce</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
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
                                </ul>
                            </li>
                        @endcanany

                        @can('view-school-attendance')
                            <li class="sidebar-section {{ $timeAttendanceActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Time and attendance</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}"
                                           href="{{ route('attendance.index') }}">
                                            <iconify-icon icon="mdi:calendar-check-outline"></iconify-icon>
                                            <span class="hide-menu">Attendance</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan

                        {{-- EVENTS section --}}
                        @can('manage-special-activities')
                            <li class="sidebar-section {{ $eventsActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Events</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('special-activities.index') ? 'active' : '' }}"
                                           href="{{ route('special-activities.index') }}">
                                            <iconify-icon icon="mdi:arrow-right-circle-outline"></iconify-icon>
                                            <span class="hide-menu">Special Activities</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan

                        {{-- REPORTS section --}}
                        @can('view-all-reports')
                            <li class="sidebar-section {{ $reportsActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Reports</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}"
                                           href="{{ route('reports.school-summary') }}">
                                            <iconify-icon icon="mdi:file-chart-outline"></iconify-icon>
                                            <span class="hide-menu">All Reports</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan

                    </ul>

                    {{-- ══════════════════════════════════════════════════════ --}}
                    {{-- REGULAR ORG SIDEBAR                                     --}}
                    {{-- ══════════════════════════════════════════════════════ --}}
                @else

                    @php
                        $overviewActive = request()->routeIs('dashboard') || request()->routeIs('analytics');
                        $workforceActive = request()->routeIs('employees.index');
                        $timeAttendanceActive = request()->routeIs('shifts.coverage')
                            || request()->routeIs('attendance.index')
                            || request()->routeIs('timesheet.index');
                        $leaveActive = request()->routeIs('leaves.index');
                        $reportsActive = request()->routeIs('reports.detailed')
                            || request()->routeIs('reports.summary')
                            || request()->routeIs('reports.scheduled');
                        $clientsActive = request()->routeIs('organizations.index');
                    @endphp

                    <ul class="sidebar-menu" id="sidebarnav">

                        @canany(['view-dashboard', 'view-employees'])
                            <li class="sidebar-section {{ $overviewActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Overview</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
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
                                </ul>
                            </li>
                        @endcanany

                        @can('view-employees')
                            <li class="sidebar-section {{ $workforceActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Workforce</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('employees.index') ? 'active' : '' }}"
                                           href="{{ route('employees.index') }}">
                                            <iconify-icon icon="mdi:account-group-outline"></iconify-icon>
                                            <span class="hide-menu">Employees</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan

                        @canany(['view-shift-monitoring', 'view-all-attendance'])
                            <li class="sidebar-section {{ $timeAttendanceActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Time and attendance</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    @can('view-shift-monitoring')
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

                                    @can('view-all-attendance')
                                        <li class="sidebar-item">
                                            <a class="sidebar-link {{ request()->routeIs('timesheet.index') ? 'active' : '' }}"
                                               href="{{ route('timesheets.index') }}">
                                                <iconify-icon icon="mdi:clipboard-clock-outline"></iconify-icon>
                                                <span class="hide-menu">Timesheets</span>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endcanany

                        @canany(['create-leave-request', 'approve-leave-request'])
                            <li class="sidebar-section {{ $leaveActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Leave</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('leaves.index') ? 'active' : '' }}"
                                           href="{{ route('leaves.index') }}">
                                            <iconify-icon icon="mdi:exit-run"></iconify-icon>
                                            <span class="hide-menu">Leave Requests</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcanany

                        @can('view-all-reports')
                            <li class="sidebar-section {{ $reportsActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Reports</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
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
                                </ul>
                            </li>
                        @endcan

                        @can('view-organizations')
                            <li class="sidebar-section {{ $clientsActive ? 'open' : '' }}">
                                <button type="button" class="sidebar-section-header">
                                    <span>Client accounts</span>
                                    <iconify-icon class="section-chevron" icon="mdi:chevron-right"></iconify-icon>
                                </button>
                                <ul class="sidebar-section-items">
                                    <li class="sidebar-item">
                                        <a class="sidebar-link {{ request()->routeIs('organizations.index') ? 'active' : '' }}"
                                           href="{{ route('organizations.index') }}">
                                            <iconify-icon icon="mdi:office-building-outline"></iconify-icon>
                                            <span class="hide-menu">Clients</span>
                                        </a>
                                    </li>
                                </ul>
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

        // Accordion behaviour for sidebar sections
        const sections = document.querySelectorAll('.sidebar-section');

        const anyOpen = Array.from(sections).some(s => s.classList.contains('open'));
        if (!anyOpen && sections.length > 0) {
            sections[0].classList.add('open');
        }

        sections.forEach(section => {
            const header = section.querySelector('.sidebar-section-header');
            header.addEventListener('click', function () {
                const isOpen = section.classList.contains('open');
                sections.forEach(s => s.classList.remove('open'));
                if (!isOpen) {
                    section.classList.add('open');
                }
            });
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
