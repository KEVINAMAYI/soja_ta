<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="work-location-actions" data-bs-toggle="dropdown"
           aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="work-location-actions">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2"
                   href="{{ route('work-location.view',$work_location) }}">
                    <iconify-icon icon="mdi:eye-outline" class="text-primary w-4 h-4"></iconify-icon>
                    <span>View</span>
                </a>
            </li>
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="javascript:void(0)"
                   wire:click="$dispatch('delete-work-location',{ id : {{ $work_location->id }} })">
                    <iconify-icon icon="mdi:delete-outline" class="text-danger w-4 h-4"></iconify-icon>
                    <span>Delete</span>
                </a>
            </li>
        </ul>
    </div>
</div>
