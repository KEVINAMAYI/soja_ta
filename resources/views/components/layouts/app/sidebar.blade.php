@php
    $org = auth()->check() && auth()->user()->employee
        ? \App\Models\Organization::find(auth()->user()->employee->organization_id)
        : null;

    $primaryColor = $org?->primary_color ?? '#072639';
    $logoHeight   = $org?->logo_height   ?? 60;
    $logoWidth    = $org?->logo_width    ?? 200;
    $sidebarBg = $org?->sidebar_bg_color ?? '';
    $pageBg    = $org?->page_bg_color    ?? '';
@endphp
    <!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">

<head>

    <base href="{{ URL::to('/') }}">

    @include('partials.head')

    @stack('styles')

    @stack('vite')

    @rappasoftTableStyles

    @rappasoftTableThirdPartyStyles

    <style>
        :root {
            --primary-color: {{ $primaryColor }};
            --accent-color: #e14326;
            --light-gray: #F4F6F9;
            --dark-text: #212121;
            --muted-text: #666666;
            --dark-bg: #1A1A1A;
            --white: #ffffff;
            --primary-color: {{ $primaryColor }};
            @if($sidebarBg) --sidebar-bg: {{ $sidebarBg }}; @endif
            @if($pageBg)    --page-bg:    {{ $pageBg }};    @endif
        }

        [data-bs-theme=light][data-color-theme=Blue_Theme]:root {
            --bs-primary: {{ $primaryColor }};
            --bs-primary-rgb: 225, 67, 38;
            --bs-secondary: #16CDC7;
            --bs-secondary-rgb: 22, 205, 199;
        }

        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }



        .btn-outline-primary {
            border-color: var(--accent-color) !important;
            color: var(--accent-color) !important;
        }

        .btn-outline-info {
            border-color: var(--primary-color) !important;
            color: var(--primary-color) !important;
        }

        .btn-outline-primary:hover,
        .btn-outline-info:hover {
            background-color: var(--accent-color) !important;
            color: var(--dark-text) !important;
        }

        .btn-outline-info:hover {
            border: 0px !important;
        }

        .topbar-image {
            background-color: var(--primary-color) !important;
        }

        header.header-fp {
            background-color: var(--light-gray) !important;
        }

        .nav-link {
            color: var(--dark-text) !important;
        }

        .nav-link.active,
        .nav-link:hover {
            color: var(--primary-color) !important;
        }

        .org-logo {
            height: {{ $logoHeight }}px;
            width: {{ $logoWidth }}px;
            margin-left: -10px;
            object-fit: contain;
        }
    </style>

</head>

<body class="link-sidebar">

<div id="main-wrapper" {{ $sidebarBg ? "style=background-color:{$sidebarBg};" : '' }}>
    @include('partials.aside')
    <div class="page-wrapper">
        <header {{ $sidebarBg ? "style=background-color:{$sidebarBg};" : '' }} class="topbar">
            <div {{ $sidebarBg ? "style=background-color:{$sidebarBg};" : '' }} class="with-vertical">
                @include('partials.vertical-layout-header')
            </div>
            <div class="app-header with-horizontal">
                <nav class="navbar navbar-expand-xl container-fluid p-0">
                    <ul class="navbar-nav align-items-center">
                        <li class="nav-item d-flex d-xl-none">
                            <a class="nav-link sidebartoggler nav-icon-hover-bg rounded-circle" id="sidebarCollapse"
                               href="javascript:void(0)">
                                <iconify-icon icon="solar:hamburger-menu-line-duotone" class="fs-7"></iconify-icon>
                            </a>
                        </li>
                        <li class="nav-item d-none d-xl-flex align-items-center">
                            <a href="horizontal/index.html" class="text-nowrap nav-link">
                                @if($org?->logo_path)
                                    <img src="{{ asset('storage/' . $org->logo_path) }}" class="org-logo"
                                         alt="{{ $org->name }}"/>
                                @else
                                    <div
                                        class="org-logo d-flex align-items-center justify-content-center fw-bold text-white"
                                        style="background-color: {{ $primaryColor }}; font-size: 1.2rem;">
                                        {{ strtoupper(substr($org?->name ?? 'ORG', 0, 2)) }}
                                    </div>
                                @endif
                            </a>
                        </li>
                    </ul>
                    <div class="d-block d-xl-none">
                        <a href="default-sidebar/index.html" class="text-nowrap nav-link">
                            @if($org?->logo_path)
                                <img src="{{ asset('storage/' . $org->logo_path) }}" class="org-logo"
                                     alt="{{ $org->name }}"/>
                            @else
                                <div
                                    class="org-logo d-flex align-items-center justify-content-center fw-bold text-white"
                                    style="background-color: {{ $primaryColor }}; font-size: 1.2rem;">
                                    {{ strtoupper(substr($org?->name ?? 'ORG', 0, 2)) }}
                                </div>
                            @endif
                        </a>
                    </div>

                    {{-- rest of navbar unchanged --}}
                    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                        {{-- ... --}}
                    </div>
                </nav>
            </div>
        </header>

        <div {{ $pageBg ? "style=background-color:{$pageBg};" : '' }}  class="body-wrapper">
            <div class="container-fluid">
                {{ $slot }}
            </div>
        </div>

        <button
            class="btn btn-danger p-3 rounded-circle d-flex align-items-center justify-content-center customizer-btn"
            type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasExample"
            aria-controls="offcanvasExample">
            <i class="icon ti ti-settings fs-7"></i>
        </button>

        @include('partials.theme-settings')

        <script>
            function handleColorTheme(e) {
                document.documentElement.setAttribute("data-color-theme", e);
            }
        </script>
    </div>
</div>

<div class="dark-transparent sidebartoggler"></div>

<script src="assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/libs/simplebar/dist/simplebar.min.js"></script>
<script src="assets/js/theme/app.init.js"></script>
<script src="assets/js/theme/theme.js"></script>
<script src="assets/js/theme/app.min.js"></script>
<script src="assets/js/theme/sidebarmenu-default.js"></script>
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
<script src="assets/libs/fullcalendar/index.global.min.js"></script>
<script src="assets/js/apps/calendar-init.js"></script>
<script src="assets/js/vendor.min.js"></script>
<script src="assets/libs/apexcharts/dist/apexcharts.min.js"></script>
<script src="assets/js/dashboards/dashboard3.js"></script>
<script src="../assets/js/extra-libs/moment/moment.min.js"></script>
<script src="../assets/libs/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="../assets/js/forms/datepicker-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/apex-chart/apex.pie.init.js"></script>
<script src="../assets/js/apex-chart/apex.bar.init.js"></script>
<script src="../assets/js/apex-chart/apex.line.init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Select2's own bundled CSS sets .select2-selection__rendered { line-height: 28px },
       which fights the app theme's 40px rule and leaves the label sitting near the top
       of the (taller) box. Scoped + !important so this wins regardless of load order,
       without touching any other Select2 instance in the app. */
    .dept-group-select + .select2-container--default .select2-selection--single {
        height: 40px !important;
    }
    .dept-group-select + .select2-container--default .select2-selection--single .select2-selection__rendered {
        height: 40px !important;
        line-height: 40px !important;
    }
    .dept-group-select + .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 38px !important;
    }
</style>

<script>
    // Grouped department filter (see resources/views/livewire/admin/partials/department-group-filter.blade.php).
    // The <select> lives inside a wire:ignore wrapper, so Livewire never touches it after the
    // initial render — we own its Select2 lifecycle and forward changes to Livewire ourselves.
    function initDeptGroupSelects(context = document) {
        $(context).find('select.dept-group-select').each(function () {
            const $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) {
                return;
            }

            $el.select2({
                width: '100%',
                placeholder: 'All Departments',
                allowClear: false,
                minimumResultsForSearch: 0,
            });

            $el.on('change', function () {
                const val = $(this).val();
                const eventName = $el.data('dispatch-event');
                if (!eventName) {
                    return;
                }
                Livewire.dispatch(eventName, {
                    department_ids: val && val !== 'all' ? val.split(',') : [],
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => initDeptGroupSelects());
</script>

@rappasoftTableScripts
@rappasoftTableThirdPartyScripts

@stack('scripts')

</body>
</html>
