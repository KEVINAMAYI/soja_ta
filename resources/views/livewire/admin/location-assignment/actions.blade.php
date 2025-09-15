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
                <a class="dropdown-item d-flex align-items-center gap-2 {{ $work_location->active ? 'text-warning' : 'text-success' }}"
                   href="javascript:void(0)"
                   wire:click="$dispatch('toggle-work-location', { id : {{ $work_location->id }}})">
                    <iconify-icon
                        icon="{{ $work_location->active ? 'mdi:power' : 'mdi:power-plug' }}"
                        class="{{ $work_location->active ? 'text-warning' : 'text-success' }} w-4 h-4">
                    </iconify-icon>
                    <span>{{ $work_location->active ? 'Deactivate' : 'Activate' }}</span>
                </a>
            </li>
        </ul>
    </div>
</div>
