<?php

use Livewire\Volt\Component;

new class extends Component {
}; ?>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm p-4">
            <form>
                <div class="mb-3 row align-items-center">
                    <label for="companyName" class="col-sm-3 col-form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" id="companyName" class="form-control" value="ISUZU EAST AFRICA" required>
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label for="address" class="col-sm-3 col-form-label fw-semibold">Address <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" id="address" class="form-control" placeholder="Address" required>
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label for="location" class="col-sm-3 col-form-label fw-semibold">Location <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <div class="input-group">
                            <input type="text" id="location" class="form-control" value="ISUZU East Africa, Mombasa Road, Nairobi, Kenya" required style="border-right: none !important; border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important;">
                            <button type="button" class="btn btn-outline-warning d-flex align-items-center justify-content-center gap-1">
                                <i class="ti ti-crosshair fs-5"></i> Current Location
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label for="email" class="col-sm-3 col-form-label fw-semibold">Email <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="email" id="email" class="form-control" value="emmanuelamanga@identigate.co.ke" required>
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label for="phoneNumber" class="col-sm-3 col-form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="tel" id="phoneNumber" class="form-control" value="0800724724" required>
                    </div>
                </div>

                <div class="mb-3 row align-items-start">
                    <label for="description" class="col-sm-3 col-form-label fw-semibold">Description <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <textarea id="description" rows="3" class="form-control" required>Leading motor vehicle assembler in East Africa</textarea>
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label for="website" class="col-sm-3 col-form-label fw-semibold">Website</label>
                    <div class="col-sm-9">
                        <input type="url" id="website" class="form-control" value="https://www.isuzu.co.ke">
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Logo</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex justify-content-center align-items-center" style="height: 100px; width: 100px; background-color: #8E44AD; color: white; font-size: 2rem;">
                            <span>IA</span>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1">
                            <i class="ti ti-trash"></i> Delete
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-info">
                        <i class="ti ti-arrow-left"></i> Back
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-edit"></i> Update
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>



