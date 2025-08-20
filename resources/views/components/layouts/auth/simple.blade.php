<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">

<head>
    @include('partials.head')

    @if (app()->environment('local'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('build/assets/app-BFrd5Ati.css') }}">
        <script type="module" src="{{ asset('build/assets/app-l0sNRNKZ.js') }}"></script>
    @endif


    <style>
        body {
            color: rgb(11, 41, 71);
        }
    </style>

</head>

<body>

<div id="main-wrapper">
    <div class="position-relative overflow-hidden radial-gradient min-vh-100 w-100">
        <div class="position-relative z-index-5">
            <div class="row gx-0">

                <div class="col-lg-6 col-xl-5 col-xxl-4">
                    <div class="min-vh-100 bg-body row justify-content-center align-items-center p-5">
                        <div class="col-12 auth-card">
                            <div class="d-flex justify-content-center mb-4">
                                <a href="../main/index.html" class="text-nowrap">
                                    <img src="../assets/images/logos/soja_ta_logo.png" width="145" height="145"
                                         class="dark-logo" alt="Logo-Dark"/>
                                </a>
                            </div>
                            <div class="px-10 py-8">{{ $slot }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-7 col-xxl-8 d-none d-lg-block position-relative overflow-hidden" style="background-color: rgb(10,37,64);">
                    <!-- Optional filter layer (can be removed if not needed) -->
                    <div class="position-absolute top-0 start-0 w-100 h-100" style="filter: brightness(0.9) contrast(1.1) saturate(1.1); transform: scale(1.02);"></div>

                    <!-- Gradient overlay (semi-transparent) -->
                    <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(to right, rgba(0, 0, 0, 0.35), rgba(0, 0, 0, 0.1));"></div>

                    <!-- Centered text layer -->
                    <div class="position-absolute top-50 start-50 translate-middle text-white text-center" style="z-index: 2;">
                        <h1 style="color:white; font-size: 3rem; font-weight: 700; letter-spacing: 2px;">SOJA TIME & ATTENDANCE</h1>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<div class="dark-transparent sidebartoggler"></div>
<!-- Import Js Files -->
<script src="../assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/libs/simplebar/dist/simplebar.min.js"></script>
<script src="../assets/js/theme/app.init.js"></script>
<script src="../assets/js/theme/theme.js"></script>
<script src="../assets/js/theme/app.min.js"></script>

<!-- solar icons -->
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

@fluxScripts

</body>
</html>




