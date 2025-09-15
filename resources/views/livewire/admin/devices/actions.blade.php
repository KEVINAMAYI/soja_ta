<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="employee-actions" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="employee-actions">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)"
                   wire:click="$dispatch('remove-device',{ id : {{ $device->id }} })">
                    <iconify-icon icon="mdi:trash-can-outline" class="text-danger w-4 h-4"></iconify-icon>
                    <span>Remove</span>
                </a>
            </li>
        </ul>
    </div>
</div>
