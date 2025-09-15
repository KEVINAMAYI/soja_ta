<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="work-location-actions" data-bs-toggle="dropdown"
           aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="work-location-actions">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)"
                   wire:click="$dispatch('show-devices',{ id : {{ $device_location->id }} })">
                    <iconify-icon icon="mdi:cellphone" class="text-success w-4 h-4"></iconify-icon>
                    <span>Devices</span>
                </a>
            </li>
        </ul>
    </div>
</div>
