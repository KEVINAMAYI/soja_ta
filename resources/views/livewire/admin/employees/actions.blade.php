<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="employee-actions" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="employee-actions">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="">
                    <iconify-icon icon="mdi:eye-outline" class="text-primary w-4 h-4"></iconify-icon>
                    <span>View</span>
                </a>
            </li>
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)" wire:click="$dispatch('edit-employee',{ id : {{ $employee->id }} })" >
                    <iconify-icon icon="mdi:pencil-outline" class="text-warning w-4 h-4"></iconify-icon>
                    <span>Edit</span>
                </a>
            </li>
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="javascript:void(0)" wire:click="$dispatch('delete-employee',{ id : {{ $employee->id }} })">
                    <iconify-icon icon="mdi:delete-outline" class="text-danger w-4 h-4"></iconify-icon>
                    <span>Delete</span>
                </a>
            </li>
        </ul>
    </div>
</div>
