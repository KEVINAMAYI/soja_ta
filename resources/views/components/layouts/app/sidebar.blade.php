<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">

<head>

    <base href="{{ URL::to('/') }}">

    @include('partials.head')

    @stack('styles')

    @rappasoftTableStyles

    @rappasoftTableThirdPartyStyles

    <style>
        :root {
            --primary-color: #e14326; /* Updated primary color */
            --accent-color: #e14326; /* Updated accent color */
            --light-gray: #F4F6F9;
            --dark-text: #212121;
            --muted-text: #666666;
            --dark-bg: #1A1A1A;
            --white: #ffffff;
        }


        [data-bs-theme=light][data-color-theme=Blue_Theme]:root {
            --bs-primary: #e14326; /* Updated primary color */
            --bs-primary-rgb: 225, 67, 38; /* RGB for #e14326 */
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
    </style>

</head>

<body class="link-sidebar">

<div id="main-wrapper">
    <!-- Sidebar Start -->
    @include('partials.aside')
    <!--  Sidebar End -->
    <div class="page-wrapper">
        <!--  Header Start -->
        <header class="topbar">
            <div class="with-vertical">
                <!-- ---------------------------------- -->
                <!-- Start Vertical Layout Header -->
                <!-- ---------------------------------- -->
                @include('partials.vertical-layout-header')
                <!-- ---------------------------------- -->
                <!-- End Vertical Layout Header -->
                <!-- ---------------------------------- -->


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
                                <img src="assets/images/logos/soja_ta_logo.png" alt="matdash-img"/>
                            </a>
                        </li>
                    </ul>
                    <div class="d-block d-xl-none">
                        <a href="default-sidebar/index.html" class="text-nowrap nav-link">
                            <img src="assets/images/logos/soja_ta_logo.png" alt="matdash-img"/>
                        </a>
                    </div>
                    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                        <div class="d-flex align-items-center justify-content-between px-0 px-xl-8">
                            <ul class="navbar-nav flex-row mx-auto ms-lg-auto align-items-center justify-content-center">
                                <li class="nav-item dropdown">
                                    <a href="javascript:void(0)"
                                       class="nav-link nav-icon-hover-bg rounded-circle d-flex d-lg-none align-items-center justify-content-center"
                                       type="button" data-bs-toggle="offcanvas" data-bs-target="#mobilenavbar"
                                       aria-controls="offcanvasWithBothOptions">
                                        <iconify-icon icon="solar:sort-line-duotone" class="fs-6"></iconify-icon>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link nav-icon-hover-bg rounded-circle moon dark-layout"
                                       href="javascript:void(0)">
                                        <iconify-icon icon="solar:moon-line-duotone" class="moon fs-6"></iconify-icon>
                                    </a>
                                    <a class="nav-link nav-icon-hover-bg rounded-circle sun light-layout"
                                       href="javascript:void(0)" style="display: none">
                                        <iconify-icon icon="solar:sun-2-line-duotone" class="sun fs-6"></iconify-icon>
                                    </a>
                                </li>

                                <!-- ------------------------------- -->
                                <!-- start notification Dropdown -->
                                <!-- ------------------------------- -->
                                <li class="nav-item dropdown nav-icon-hover-bg rounded-circle">
                                    <a class="nav-link position-relative" href="javascript:void(0)" id="drop2"
                                       aria-expanded="false">
                                        <iconify-icon icon="solar:bell-bing-line-duotone" class="fs-6"></iconify-icon>
                                    </a>
                                    <div class="dropdown-menu content-dd dropdown-menu-end dropdown-menu-animate-up"
                                         aria-labelledby="drop2">
                                        <div class="d-flex align-items-center justify-content-between py-3 px-7">
                                            <h5 class="mb-0 fs-5 fw-semibold">Notifications</h5>
                                            <span class="badge text-bg-primary rounded-4 px-3 py-1 lh-sm">5 new</span>
                                        </div>
                                        <div class="message-body" data-simplebar>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-danger-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-danger">
                            <iconify-icon icon="solar:widget-3-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Launch Admin</h6>
                                                        <span class="d-block fs-2">9:30 AM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">Just see the my new admin!</span>
                                                </div>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-primary-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-primary">
                            <iconify-icon icon="solar:calendar-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Event today</h6>
                                                        <span class="d-block fs-2">9:15 AM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">Just a reminder that you have event</span>
                                                </div>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-secondary-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-secondary">
                            <iconify-icon icon="solar:settings-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Settings</h6>
                                                        <span class="d-block fs-2">4:36 PM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">You can customize this template as you want</span>
                                                </div>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-warning-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-warning">
                            <iconify-icon icon="solar:widget-4-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Launch Admin</h6>
                                                        <span class="d-block fs-2">9:30 AM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">Just see the my new admin!</span>
                                                </div>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-primary-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-primary">
                            <iconify-icon icon="solar:calendar-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Event today</h6>
                                                        <span class="d-block fs-2">9:15 AM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">Just a reminder that you have event</span>
                                                </div>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="py-6 px-7 d-flex align-items-center dropdown-item gap-3">
                          <span
                              class="flex-shrink-0 bg-secondary-subtle rounded-circle round d-flex align-items-center justify-content-center fs-6 text-secondary">
                            <iconify-icon icon="solar:settings-line-duotone"></iconify-icon>
                          </span>
                                                <div class="w-75 d-inline-block ">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <h6 class="mb-1 fw-semibold">Settings</h6>
                                                        <span class="d-block fs-2">4:36 PM</span>
                                                    </div>
                                                    <span class="d-block text-truncate text-truncate fs-11">You can customize this template as you want</span>
                                                </div>
                                            </a>
                                        </div>
                                        <div class="py-6 px-7 mb-1">
                                            <button class="btn btn-primary w-100">See All Notifications</button>
                                        </div>

                                    </div>
                                </li>
                                <!-- ------------------------------- -->
                                <!-- end notification Dropdown -->
                                <!-- ------------------------------- -->

                                <!-- ------------------------------- -->
                                <!-- start language Dropdown -->
                                <!-- ------------------------------- -->
                                <li class="nav-item dropdown nav-icon-hover-bg rounded-circle">
                                    <a class="nav-link" href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown"
                                       aria-expanded="false">
                                        <img src="assets/images/flag/icon-flag-en.svg" alt="matdash-img" width="20px"
                                             height="20px" class="rounded-circle object-fit-cover round-20"/>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-animate-up"
                                         aria-labelledby="drop2">
                                        <div class="message-body">
                                            <a href="javascript:void(0)"
                                               class="d-flex align-items-center gap-2 py-3 px-4 dropdown-item">
                                                <div class="position-relative">
                                                    <img src="assets/images/flag/icon-flag-en.svg" alt="matdash-img"
                                                         width="20px" height="20px"
                                                         class="rounded-circle object-fit-cover round-20"/>
                                                </div>
                                                <p class="mb-0 fs-3">English (UK)</p>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="d-flex align-items-center gap-2 py-3 px-4 dropdown-item">
                                                <div class="position-relative">
                                                    <img src="assets/images/flag/icon-flag-cn.svg" alt="matdash-img"
                                                         width="20px" height="20px"
                                                         class="rounded-circle object-fit-cover round-20"/>
                                                </div>
                                                <p class="mb-0 fs-3">中国人 (Chinese)</p>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="d-flex align-items-center gap-2 py-3 px-4 dropdown-item">
                                                <div class="position-relative">
                                                    <img src="assets/images/flag/icon-flag-fr.svg" alt="matdash-img"
                                                         width="20px" height="20px"
                                                         class="rounded-circle object-fit-cover round-20"/>
                                                </div>
                                                <p class="mb-0 fs-3">français (French)</p>
                                            </a>
                                            <a href="javascript:void(0)"
                                               class="d-flex align-items-center gap-2 py-3 px-4 dropdown-item">
                                                <div class="position-relative">
                                                    <img src="assets/images/flag/icon-flag-sa.svg" alt="matdash-img"
                                                         width="20px" height="20px"
                                                         class="rounded-circle object-fit-cover round-20"/>
                                                </div>
                                                <p class="mb-0 fs-3">عربي (Arabic)</p>
                                            </a>
                                        </div>
                                    </div>
                                </li>
                                <!-- ------------------------------- -->
                                <!-- end language Dropdown -->
                                <!-- ------------------------------- -->

                                <!-- ------------------------------- -->
                                <!-- start profile Dropdown -->
                                <!-- ------------------------------- -->
                                <li class="nav-item dropdown">
                                    <a class="nav-link" href="javascript:void(0)" id="drop1" aria-expanded="false">
                                        <div class="d-flex align-items-center gap-2 lh-base">
                                            <img src="assets/images/profile/user-1.jpg" class="rounded-circle"
                                                 width="35" height="35" alt="matdash-img"/>
                                            <iconify-icon icon="solar:alt-arrow-down-bold" class="fs-2"></iconify-icon>
                                        </div>
                                    </a>
                                    <div
                                        class="dropdown-menu profile-dropdown dropdown-menu-end dropdown-menu-animate-up"
                                        aria-labelledby="drop1">
                                        <div class="position-relative px-4 pt-3 pb-2">
                                            <div class="d-flex align-items-center mb-3 pb-3 border-bottom gap-6">
                                                <img src="assets/images/profile/user-1.jpg" class="rounded-circle"
                                                     width="56" height="56" alt="matdash-img"/>
                                                <div>
                                                    <h5 class="mb-0 fs-12">David McMichael <span
                                                            class="text-success fs-11">Pro</span>
                                                    </h5>
                                                    <p class="mb-0 text-dark">
                                                        david@wrappixel.com
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="message-body">
                                                <a href="default-sidebar/page-user-profile.html"
                                                   class="p-2 dropdown-item h6 rounded-1">
                                                    My Profile
                                                </a>
                                                <a href="default-sidebar/page-pricing.html"
                                                   class="p-2 dropdown-item h6 rounded-1">
                                                    My Subscription
                                                </a>
                                                <a href="default-sidebar/app-invoice.html"
                                                   class="p-2 dropdown-item h6 rounded-1">
                                                    My Invoice <span
                                                        class="badge bg-danger-subtle text-danger rounded ms-8">4</span>
                                                </a>
                                                <a href="default-sidebar/page-account-settings.html"
                                                   class="p-2 dropdown-item h6 rounded-1">
                                                    Account Settings
                                                </a>
                                                <a href="default-sidebar/authentication-login2.html"
                                                   class="p-2 dropdown-item h6 rounded-1">
                                                    Sign Out
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                                <!-- ------------------------------- -->
                                <!-- end profile Dropdown -->
                                <!-- ------------------------------- -->
                            </ul>
                        </div>
                    </div>
                </nav>

            </div>
        </header>
        <!--  Header End -->

        <div class="body-wrapper">
            <div class="container-fluid">
                {{ $slot  }}
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
<!-- Import Js Files -->
<script src="assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/libs/simplebar/dist/simplebar.min.js"></script>
<script src="assets/js/theme/app.init.js"></script>
<script src="assets/js/theme/theme.js"></script>
<script src="assets/js/theme/app.min.js"></script>
<script src="assets/js/theme/sidebarmenu-default.js"></script>

<!-- solar icons -->
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


<!-- Adds the Core Table Scripts -->
@rappasoftTableScripts

<!-- Adds any relevant Third-Party Scripts (e.g. Flatpickr) -->
@rappasoftTableThirdPartyScripts

@stack('scripts')


</body>

</html>

