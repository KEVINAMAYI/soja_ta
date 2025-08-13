<nav class="navbar navbar-expand-lg p-0">
    <ul class="navbar-nav">
        <li class="nav-item nav-icon-hover-bg rounded-circle d-flex">
            <a class="nav-link  sidebartoggler" id="headerCollapse" href="javascript:void(0)">
                <iconify-icon icon="solar:hamburger-menu-line-duotone" class="fs-6"></iconify-icon>
            </a>
        </li>
    </ul>

    <div class="d-block d-lg-none py-9 py-xl-0">
        <img src="assets/images/logos/logo.svg" alt="matdash-img"/>
    </div>
    <a class="navbar-toggler p-0 border-0 nav-icon-hover-bg rounded-circle" href="javascript:void(0)"
       data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav"
       aria-expanded="false" aria-label="Toggle navigation">
        <iconify-icon icon="solar:menu-dots-bold-duotone" class="fs-6"></iconify-icon>
    </a>




    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
        <div class="d-flex align-items-center justify-content-between">
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
                    <a class="nav-link moon dark-layout nav-icon-hover-bg rounded-circle"
                       href="javascript:void(0)">
                        <iconify-icon icon="solar:moon-line-duotone" class="moon fs-6"></iconify-icon>
                    </a>
                    <a class="nav-link sun light-layout nav-icon-hover-bg rounded-circle"
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
                                <img
                                    src="{{ Auth::user()->profile_photo_url ?? asset('assets/images/profile/user-1.jpg') }}"
                                    class="rounded-circle"
                                    width="56"
                                    height="56"
                                    alt="{{ Auth::user()->name ?? 'User' }}"/>

                                <div>
                                    <h5 class="mb-0 fs-12">{{ Auth::user()->name }}</h5>
                                    <p class="mb-0 text-dark">{{ Auth::user()->email }}</p>
                                </div>
                            </div>
                            <div class="message-body">
                                <a href="{{ route('system-settings.index') }}"
                                   class="p-2 dropdown-item h6 rounded-1 d-flex justify-content-between align-items-center">
                                    My Profile
                                    <iconify-icon icon="mdi:user" style="font-size: 1.2em;"></iconify-icon>
                                </a>
                                <a href="{{ route('system-settings.index') }}"
                                   class="p-2 dropdown-item h6 rounded-1 d-flex justify-content-between align-items-center">
                                    System Settings
                                    <iconify-icon icon="mdi:cog-outline" style="font-size: 1.2em;"></iconify-icon>
                                </a>
                                <a href="{{ route('account-settings.index') }}"
                                   class="p-2 dropdown-item h6 rounded-1 d-flex justify-content-between align-items-center">
                                    Account Settings
                                    <iconify-icon icon="mdi:tune" style="font-size: 1.2em;"></iconify-icon>
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                            class="p-2 dropdown-item h6 rounded-1 w-100 text-start border-0 bg-transparent d-flex justify-content-between align-items-center">
                                        Sign Out
                                        <iconify-icon icon="mdi:logout" style="font-size: 1.2em;"></iconify-icon>
                                    </button>
                                </form>
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
