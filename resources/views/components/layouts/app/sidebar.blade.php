<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">

<head>

    <base href="{{ URL::to('/') }}">

    @include('partials.head')

    @stack('styles')

    @rappasoftTableStyles

    @rappasoftTableThirdPartyStyles

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

                <!-- ------------------------------- -->
                <!-- apps Dropdown in Small screen -->
                <!-- ------------------------------- -->
                <!--  Mobilenavbar -->
                <div class="offcanvas offcanvas-start pt-0" data-bs-scroll="true" tabindex="-1" id="mobilenavbar"
                     aria-labelledby="offcanvasWithBothOptionsLabel">
                    <nav class="sidebar-nav scroll-sidebar">
                        <div class="offcanvas-header justify-content-between">
                            <a href="default-sidebar/index.html" class="text-nowrap logo-img">
                                <img src="assets/images/logos/soja_ta_logo.png" alt="Logo"/>
                            </a>
                            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                                    aria-label="Close"></button>
                        </div>
                        <div class="offcanvas-body pt-0" data-simplebar style="height: calc(100vh - 80px)">
                            <ul id="sidebarnav">
                                <li class="sidebar-item">
                                    <a class="sidebar-link has-arrow ms-0" href="javascript:void(0)"
                                       aria-expanded="false">
                      <span>
                        <iconify-icon icon="solar:slider-vertical-line-duotone" class="fs-7"></iconify-icon>
                      </span>
                                        <span class="hide-menu">Apps</span>
                                    </a>
                                    <ul aria-expanded="false" class="collapse first-level my-3 ps-3">
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-chat.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-primary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:chat-line-bold-duotone"
                                                                  class="fs-7 text-primary"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Chat Application</h6>
                                                    <span
                                                        class="fs-11 d-block text-body-color">New messages arrived</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-invoice.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-secondary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:bill-list-bold-duotone"
                                                                  class="fs-7 text-secondary"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Invoice App</h6>
                                                    <span
                                                        class="fs-11 d-block text-body-color">Get latest invoice</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-contact2.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-warning-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:phone-calling-rounded-bold-duotone"
                                                                  class="fs-7 text-warning"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Contact Application</h6>
                                                    <span
                                                        class="fs-11 d-block text-body-color">2 Unsaved Contacts</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-email.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-danger-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:letter-bold-duotone"
                                                                  class="fs-7 text-danger"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Email App</h6>
                                                    <span class="fs-11 d-block text-body-color">Get new emails</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/page-user-profile.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-success-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:user-bold-duotone"
                                                                  class="fs-7 text-success"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">User Profile</h6>
                                                    <span
                                                        class="fs-11 d-block text-body-color">learn more information</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-calendar.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-primary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:calendar-minimalistic-bold-duotone"
                                                                  class="fs-7 text-primary"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Calendar App</h6>
                                                    <span class="fs-11 d-block text-body-color">Get dates</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-contact.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-secondary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:smartphone-2-bold-duotone"
                                                                  class="fs-7 text-secondary"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Contact List Table</h6>
                                                    <span class="fs-11 d-block text-body-color">Add new contact</span>
                                                </div>
                                            </a>
                                        </li>
                                        <li class="sidebar-item py-2">
                                            <a href="default-sidebar/app-notes.html"
                                               class="d-flex align-items-center">
                                                <div
                                                    class="bg-warning-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                    <iconify-icon icon="solar:notes-bold-duotone"
                                                                  class="fs-7 text-warning"></iconify-icon>
                                                </div>
                                                <div class="d-inline-block">
                                                    <h6 class="mb-0 bg-hover-primary">Notes Application</h6>
                                                    <span
                                                        class="fs-11 d-block text-body-color">To-do and Daily tasks</span>
                                                </div>
                                            </a>
                                        </li>
                                </li>
                            </ul>
                        </div>
                    </nav>
                </div>

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
                        <li class="nav-item d-none d-xl-flex align-items-center nav-icon-hover-bg rounded-circle">
                            <a class="nav-link" href="javascript:void(0)" data-bs-toggle="modal"
                               data-bs-target="#exampleModal">
                                <iconify-icon icon="solar:magnifer-linear" class="fs-6"></iconify-icon>
                            </a>
                        </li>
                        <li class="nav-item d-none d-lg-flex align-items-center dropdown nav-icon-hover-bg rounded-circle">
                            <div class="hover-dd">
                                <a class="nav-link" id="drop2" href="javascript:void(0)" aria-haspopup="true"
                                   aria-expanded="false">
                                    <iconify-icon icon="solar:widget-3-line-duotone" class="fs-6"></iconify-icon>
                                </a>
                                <div
                                    class="dropdown-menu dropdown-menu-nav dropdown-menu-animate-up py-0 overflow-hidden"
                                    aria-labelledby="drop2">
                                    <div class="position-relative">
                                        <div class="row">
                                            <div class="col-8">
                                                <div class="p-4 pb-3">

                                                    <div class="row">
                                                        <div class="col-6">
                                                            <div class="position-relative">
                                                                <a href="default-sidebar/app-chat.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-primary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon
                                                                            icon="solar:chat-line-bold-duotone"
                                                                            class="fs-7 text-primary"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Chat Application</h6>
                                                                        <span class="fs-11 d-block text-body-color">New messages arrived</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-invoice.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-secondary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon
                                                                            icon="solar:bill-list-bold-duotone"
                                                                            class="fs-7 text-secondary"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Invoice App</h6>
                                                                        <span class="fs-11 d-block text-body-color">Get latest invoice</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-contact2.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-warning-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon
                                                                            icon="solar:phone-calling-rounded-bold-duotone"
                                                                            class="fs-7 text-warning"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Contact Application</h6>
                                                                        <span class="fs-11 d-block text-body-color">2 Unsaved Contacts</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-email.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-danger-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon icon="solar:letter-bold-duotone"
                                                                                      class="fs-7 text-danger"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Email App</h6>
                                                                        <span class="fs-11 d-block text-body-color">Get new emails</span>
                                                                    </div>
                                                                </a>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div class="position-relative">
                                                                <a href="default-sidebar/page-user-profile.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-success-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon icon="solar:user-bold-duotone"
                                                                                      class="fs-7 text-success"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">User Profile</h6>
                                                                        <span class="fs-11 d-block text-body-color">learn more information</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-calendar.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-primary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon
                                                                            icon="solar:calendar-minimalistic-bold-duotone"
                                                                            class="fs-7 text-primary"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Calendar App</h6>
                                                                        <span class="fs-11 d-block text-body-color">Get dates</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-contact.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-secondary-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon
                                                                            icon="solar:smartphone-2-bold-duotone"
                                                                            class="fs-7 text-secondary"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Contact List Table</h6>
                                                                        <span class="fs-11 d-block text-body-color">Add new contact</span>
                                                                    </div>
                                                                </a>
                                                                <a href="default-sidebar/app-notes.html"
                                                                   class="d-flex align-items-center pb-9 position-relative">
                                                                    <div
                                                                        class="bg-warning-subtle rounded round-48 me-3 d-flex align-items-center justify-content-center">
                                                                        <iconify-icon icon="solar:notes-bold-duotone"
                                                                                      class="fs-7 text-warning"></iconify-icon>
                                                                    </div>
                                                                    <div class="d-inline-block">
                                                                        <h6 class="mb-0">Notes Application</h6>
                                                                        <span class="fs-11 d-block text-body-color">To-do and Daily tasks</span>
                                                                    </div>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <img src="assets/images/backgrounds/mega-dd-bg.jpg" alt="mega-dd"
                                                     class="img-fluid mega-dd-bg"/>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <div class="d-block d-xl-none">
                        <a href="default-sidebar/index.html" class="text-nowrap nav-link">
                            <img src="assets/images/logos/soja_ta_logo.png" alt="matdash-img"/>
                        </a>
                    </div>
                    <a class="navbar-toggler nav-icon-hover p-0 border-0 nav-icon-hover-bg rounded-circle"
                       href="javascript:void(0)" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                       aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
              <span class="p-2">
                <i class="ti ti-dots fs-7"></i>
              </span>
                    </a>
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
                                <li class="nav-item d-block d-xl-none">
                                    <a class="nav-link nav-icon-hover-bg rounded-circle" href="javascript:void(0)"
                                       data-bs-toggle="modal" data-bs-target="#exampleModal">
                                        <iconify-icon icon="solar:magnifer-line-duotone" class="fs-6"></iconify-icon>
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

    <!--  Search Bar -->
    <div class="modal fade" id="exampleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom">
                    <input type="search" class="form-control" placeholder="Search here" id="search"/>
                    <a href="javascript:void(0)" data-bs-dismiss="modal" class="lh-1">
                        <i class="ti ti-x fs-5 ms-3"></i>
                    </a>
                </div>
                <div class="modal-body message-body" data-simplebar="">
                    <h5 class="mb-0 fs-5 p-1">Quick Page Links</h5>
                    <ul class="list mb-0 py-2">
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Analytics</span>
                                <span class="fs-2 d-block text-body-secondary">/dashboards/dashboard1</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">eCommerce</span>
                                <span class="fs-2 d-block text-body-secondary">/dashboards/dashboard2</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">CRM</span>
                                <span class="fs-2 d-block text-body-secondary">/dashboards/dashboard3</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Contacts</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/contacts</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Posts</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/blog/posts</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Detail</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/blog/detail/streaming-video-way-before-it-was-cool-go-dark-tomorrow</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Shop</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/ecommerce/shop</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Modern</span>
                                <span class="fs-2 d-block text-body-secondary">/dashboards/dashboard1</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Dashboard</span>
                                <span class="fs-2 d-block text-body-secondary">/dashboards/dashboard2</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Contacts</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/contacts</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Posts</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/blog/posts</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Detail</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/blog/detail/streaming-video-way-before-it-was-cool-go-dark-tomorrow</span>
                            </a>
                        </li>
                        <li class="p-1 mb-1 bg-hover-light-black rounded px-2">
                            <a href="javascript:void(0)">
                                <span class="text-dark fw-semibold d-block">Shop</span>
                                <span class="fs-2 d-block text-body-secondary">/apps/ecommerce/shop</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
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



<!-- Adds the Core Table Scripts -->
@rappasoftTableScripts

<!-- Adds any relevant Third-Party Scripts (e.g. Flatpickr) -->
@rappasoftTableThirdPartyScripts

@stack('scripts')


</body>

</html>

