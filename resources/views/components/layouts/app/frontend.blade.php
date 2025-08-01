<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">

<head>
    @include('partials.head')
    @stack('styles')

    <style>

        :root {
            --primary-color: #ff8000;   /* deep orange */
            --accent-color: #ff8000;    /* same orange for accent */
            --light-gray: #F4F6F9;
            --dark-text: #212121;
            --muted-text: #666666;
            --dark-bg: #1A1A1A;
            --white: #ffffff;
        }

        body {
            background-color: var(--light-gray);
            color: var(--dark-text);
        }

        .bg-primary {
            background-color: #ff8000 !important;
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

        footer.bg-dark {
            background-color: var(--dark-bg) !important;
        }

        footer .text-light {
            color: var(--white) !important;
        }

        .link-primary {
            color: var(--accent-color) !important;
        }

        .btn.btn-dark {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }

        .top-btn {
            background-color: var(--accent-color) !important;
            color: var(--dark-text) !important;
        }

        /* Navbar background */
        .navbar {
            background-color: white !important;
        }

        /* Login and CTA buttons */
        .btn-outline-primary {
            border-color: var(--accent-color);
            color: var(--accent-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--accent-color);
            color: var(--dark-text);
        }

        .btn-dark {
            background-color: var(--accent-color);
            border: none;
            color: var(--white);
        }

        .navbar, .navbar * {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
            backface-visibility: hidden;
            transform: translateZ(0);
        }

        /* Footer Background */
        footer.bg-dark {
            background-color: #0E2F44 !important;  /* Dark Navy Blue */
            color: #ffffff !important;
            font-family: 'Arial', sans-serif !important;
        }

        /* Footer Links */
        footer a.link-primary {
            color: #F79020 !important;  /* Bright Orange */
            text-decoration: none !important;
            transition: color 0.3s ease !important;
        }

        footer a.link-primary:hover,
        footer a.link-primary:focus {
            color: #ffb347 !important; /* lighter orange hover */
            text-decoration: underline !important;
        }

        /* Section Headings */
        footer h3.fs-4 {
            color: #ffffff !important;
            font-weight: 700 !important;
            margin-bottom: 1.75rem !important;
        }

        /* Footer text */
        footer p {
            color: rgba(255, 255, 255, 0.8) !important;
        }

        /* Social Icons container */
        footer .d-flex.gap-9 a img {
            filter: brightness(0) invert(1) !important; /* make icons white */
            transition: filter 0.3s ease !important;
            color:white !important;
        }

        footer .d-flex.gap-9 a:hover img {
            filter: brightness(0) invert(0.65) sepia(1) saturate(5) hue-rotate(15deg) !important; /* orange glow */
        }

        /* Bottom footer bar */
        footer .border-top {
            border-color: rgba(255, 255, 255, 0.15) !important;
        }

        /* Footer logo */
        footer img[alt="logo"], footer img[alt="white logo"] {
            filter: brightness(0) invert(1) !important; /* make logo white */
            max-height: 50px !important;
            margin-right: 1rem !important;
        }

    </style>

</head>

<body>

<!-- ------------------------------------- -->
<div class="topbar-image bg-dark py-8 rounded-0 mb-0 alert alert-dismissible fade show" role="alert">
    <div class="d-flex justify-content-center gap-sm-3 gap-2 align-items-center text-center flex-md-nowrap flex-wrap">
        <span class="badge bg-white bg-opacity-10 fs-2 fw-bolder px-2">New</span>
        <p class="mb-0 text-white fw-bold">Frontend Pages Included!</p>
    </div>
    <button type="button" class="btn-close btn-close-white p-3 fs-2" data-bs-dismiss="alert" aria-label="Close"></button>
</div>

<!-- ------------------------------------- -->
<!-- Top Bar End -->
<!-- ------------------------------------- -->

<!-- -------------------------------------------- -->
<!-- Header start -->
<!-- -------------------------------------------- -->
<header class="header-fp p-0 w-100 bg-light-gray">
    <nav class="navbar navbar-expand-lg py-10">
        <div class="container-fluid d-flex justify-content-between">
            <a href="{{ route('front-end.home') }}" class="text-nowrap logo-img">
                <img height="120" width="120"  src="../assets/images/logos/tabor_logo_transparent.png" alt="Logo" />
            </a>
            <button class="navbar-toggler border-0 p-0 shadow-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasRight" aria-controls="offcanvasRight">
                <i class="ti ti-menu-2 fs-8"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav mx-auto mb-2 gap-xl-7 gap-8 mb-lg-0">
                    <li class="nav-item">
                        <a href="{{ route('front-end.home') }}"
                           class="{{ request()->routeIs('front-end.home')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('front-end.about') }}"
                           class="{{ request()->routeIs('front-end.about')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            About Us
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('front-end.courses') }}"
                           class="{{ request()->routeIs('front-end.courses')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            Courses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('front-end.course-application') }}"
                           class="{{ request()->routeIs('front-end.course-application')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            Apply
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('front-end.news') }}"
                           class="{{ request()->routeIs('front-end.news')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            News
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('front-end.contact') }}"
                           class="{{ request()->routeIs('front-end.contact')
               ? 'btn btn-dark btn-sm py-2 px-4 mx-1'
               : 'nav-link fs-4 fw-bold text-dark link-primary px-3' }}">
                            Contact
                        </a>
                    </li>
                </ul>
                <a href="{{ route('login') }}" class="btn btn-outline-info btn-sm py-2 px-9">College Portal</a>
                <a href="{{ route('front-end.course-application') }}" class="btn mx-2 btn-outline-primary btn-sm py-2 px-9">Enroll Now</a>
            </div>
        </div>
    </nav>
</header>
<!-- -------------------------------------------- -->
<!-- Header End -->
<!-- -------------------------------------------- -->

<!-- ------------------------------------- -->
<!-- Responsive Header Start -->
<!-- ------------------------------------- -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasRight" aria-labelledby="offcanvasRightLabel">
    <div class="offcanvas-header">
        <a href="../main/frontend-landingpage.html" class="text-nowrap logo-img">
            <img src="../assets/images/logos/logo.svg" alt="Logo" />
        </a>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <ul class="list-unstyled ps-0">
            <li class="mb-1">
                <a href="{{ route('front-end.home') }}"
                   class="px-0 fs-4 d-block w-100 py-2 text-dark link-primary {{ request()->routeIs('front-end.home') ? 'active' : '' }}">
                    Home
                </a>
            </li>
            <li class="mb-1">
                <a href="{{ route('front-end.about') }}"
                   class="px-0 fs-4 d-block text-dark link-primary w-100 py-2 {{ request()->routeIs('front-end.about') ? 'active' : '' }}">
                    About Us
                </a>
            </li>

            <li class="mb-1">
                <a href="{{ route('front-end.courses') }}"
                   class="px-0 fs-4 d-block w-100 py-2 text-dark link-primary {{ request()->routeIs('front-end.courses') ? 'active' : '' }}">
                    Courses
                </a>
            </li>

            <li class="mb-1">
                <a href="{{ route('front-end.news') }}"
                   class="px-0 fs-4 d-flex align-items-center justify-content-start gap-2 w-100 py-2 text-dark link-primary {{ request()->routeIs('front-end.news') ? 'active' : '' }}">
                    News
                    <span class="badge text-primary bg-primary-subtle fs-2 fw-bolder hstack">New</span>
                </a>
            </li>

            <li class="mb-1">
                <a href="{{ route('front-end.course-application') }}"
                   class="px-0 fs-4 d-block w-100 py-2 text-dark link-primary {{ request()->routeIs('front-end.course-application') ? 'active' : '' }}">
                    Apply
                </a>
            </li>

            <li class="mb-1">
                <a href="{{ route('front-end.contact') }}"
                   class="px-0 fs-4 d-block w-100 py-2 text-dark link-primary {{ request()->routeIs('front-end.contact') ? 'active' : '' }}">
                    Contact
                </a>
            </li>

            <li class="mt-3">
                <a href="{{ route('login') }}" class="btn btn-primary w-100">Log In</a>
            </li>
        </ul>
    </div>
</div>
<!-- ------------------------------------- -->
<!-- Responsive Header End -->
<!-- ------------------------------------- -->

{{ $slot }}

<footer class="bg-dark">
    <div class="container-fluid">
        <div class="row py-7 py-md-14 py-lg-11">
            <!-- Featured Links -->
            <div class="col-md-3 col-6 mb-7 mb-md-0">
                <h3 class="fs-6 text-white fw-semibold mb-7">Featured Links</h3>
                <ul class="d-flex flex-column gap-3 mt-3 mb-0">
                    <li><a href="#" class="fs-4 text-light">Home</a></li>
                    <li><a href="#" class="fs-4 text-light">About</a></li>
                    <li><a href="#" class="fs-4 text-light">Courses</a></li>
                    <li><a href="#" class="fs-4 text-light">Course Application</a></li>
                    <li><a href="#" class="fs-4 text-light">Contact</a></li>
                </ul>
            </div>

            <!-- Contact Info with Iconify icons -->
            <div class="col-md-3 col-6 mb-7 mb-md-0">
                <h3 class="fs-4 text-white fw-semibold mb-7">Contact Us</h3>
                <p class="fs-3 text-light mb-2">
                    <iconify-icon icon="mdi:email-outline" class="fs-6 me-2"></iconify-icon>
                    <a href="mailto:office@tabor.ac.ke" class="text-light">office@tabor.ac.ke</a>
                </p>
                <p class="fs-3 text-light mb-2">
                    <iconify-icon icon="mdi:phone-outline" class="fs-6 me-2"></iconify-icon>
                    +254 798 496129, +254 726 241095
                </p>
                <p class="fs-3 text-light mb-0">
                    <iconify-icon icon="mdi:map-marker-outline" class="fs-6 me-2"></iconify-icon>
                    Showbe Plaza, Pangani, Thika Highway, Nairobi, Kenya
                </p>
            </div>

            <!-- Description & Website -->
            <div class="col-md-3 col-6 mb-7 mb-md-0">
                <h3 class="fs-5 text-white fw-semibold mb-7">About Tabor TVET</h3>
                <p class="fs-3 text-light">
                    We offer TVET courses that help you build practical skills and industry-specific knowledge. Some courses prepare students for global markets, including Australia, the US, Canada, KSA and the Gulf region.
                </p>
            </div>
            <!-- Social Share -->
            <div class="col-md-3 col-6 mb-7 mb-md-0">
                <h3 class="fs-4 text-white fw-semibold mb-7">Share Us</h3>
                <div class="d-flex gap-4">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=https://www.tabor.ac.ke/" target="_blank">
                        <img src="../assets/images/frontend-pages/icon-facebook.svg" alt="Facebook">
                    </a>
                    <a href="https://x.com/intent/post?url=https://www.tabor.ac.ke/" target="_blank">
                        <img src="../assets/images/frontend-pages/icon-twitter.svg" alt="X (Twitter)">
                    </a>
                    <a href="https://www.instagram.com/" target="_blank">
                        <img src="../assets/images/frontend-pages/icon-instagram.svg" alt="Instagram">
                    </a>
                    <a href="https://www.linkedin.com/" target="_blank">
                        <img src="../assets/images/frontend-pages/icon-linckedin-dark.svg" alt="LinkedIn">
                    </a>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between flex-wrap align-items-center py-5 border-top border-dark-subtle">
            <p class="text-white opacity-50 mb-0">© 2025 Tabor Training Institute. All rights reserved.</p>
            <p class="text-white mb-0">
                Developed by <a href="https://techqast.com/" class="text-primary">Techqast</a>.
            </p>
        </div>
    </div>
</footer>


<!-- Scroll Top -->
<a href="javascript:void(0)" class="top-btn btn btn-primary d-flex align-items-center justify-content-center round-54 p-0 rounded-circle">
    <i class="ti ti-arrow-up fs-7"></i>
</a>


<!-- Import Js Files -->
@include('partials.footer')

<script src="../assets/libs/owl.carousel/dist/owl.carousel.min.js"></script>
<script src="../assets/js/frontend-landingpage/homepage.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


@stack('scripts')

</body>

</html>
