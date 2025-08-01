<x-layouts.app :title="__('Dashboard')">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-4 pb-0" data-simplebar>
                    <div class="row flex-nowrap">

                        {{-- Students --}}
                        <div class="col">
                            <div class="card primary-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div class="d-flex align-items-center justify-content-center round-48 rounded text-bg-primary flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:school" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Enrolled Students</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        16,689
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- Courses --}}
                        <div class="col">
                            <div class="card warning-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div class="d-flex align-items-center justify-content-center round-48 rounded text-bg-warning flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:book-open-page-variant" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Courses</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        148
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- Lecturers --}}
                        <div class="col">
                            <div class="card secondary-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div class="d-flex align-items-center justify-content-center round-48 rounded text-bg-secondary flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:account-tie" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Lecturers</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        156
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- Modules --}}
                        <div class="col">
                            <div class="card danger-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div class="d-flex align-items-center justify-content-center round-48 rounded text-bg-danger flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:file-document-multiple" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Modules</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        64
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View Details</a>
                                </div>
                            </div>
                        </div>

                        {{-- Income --}}
                        <div class="col">
                            <div class="card success-gradient">
                                <div class="card-body text-center px-9 pb-4">
                                    <div class="d-flex align-items-center justify-content-center round-48 rounded text-bg-success flex-shrink-0 mb-3 mx-auto">
                                        <iconify-icon icon="mdi:currency-usd" class="fs-7 text-white"></iconify-icon>
                                    </div>
                                    <h6 class="fw-normal fs-3 mb-1">Total Income</h6>
                                    <h4 class="mb-3 d-flex align-items-center justify-content-center gap-1">
                                        $36,715
                                    </h4>
                                    <a href="javascript:void(0)" class="btn btn-white fs-2 fw-semibold text-nowrap">View Details</a>
                                </div>
                            </div>
                        </div>


                    </div> <!-- end row -->
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
