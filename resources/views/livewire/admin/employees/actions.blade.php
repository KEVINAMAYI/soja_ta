<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="employee-actions" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="employee-actions">
            @can('view-employees')
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2"
                       href="{{ route('employees.view',$employee->id) }}">
                        <iconify-icon icon="mdi:eye-outline" class="text-primary w-4 h-4"></iconify-icon>
                        <span>View</span>
                    </a>
                </li>
            @endcan

            @can('edit-employees')
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)"
                       wire:click="$dispatch('edit-employee',{ id : {{ $employee->id }} })">
                        <iconify-icon icon="mdi:pencil-outline" class="text-warning w-4 h-4"></iconify-icon>
                        <span>Edit</span>
                    </a>
                </li>
            @endcan

            @can('assign-locations')
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2"
                       href="javascript:void(0)"
                       wire:click="$dispatch('assign-work-location',{ id : {{ $employee->id }} })">
                        <iconify-icon icon="mdi:map-marker-radius-outline" class="text-success w-4 h-4"></iconify-icon>
                        <span>Assign Location</span>
                    </a>
                </li>
            @endcan

            @can('unassign-locations')
                @if($employee->assignments->isNotEmpty())
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2"
                           href="javascript:void(0)"
                           wire:click="$dispatch('unassign-work-location',{ id : {{ $employee->id }} })">
                            <iconify-icon icon="mdi:map-marker-radius-outline" class="text-danger w-4 h-4"></iconify-icon>
                            <span>Unassign Location</span>
                        </a>
                    </li>
                @endif
            @endcan

            @can('manage-zkbio-areas')
                @if($employee->organization?->zkbio_enabled && $employee->zkbio_pin)
                    <li>
                        <a href="javascript:void(0)"
                           class="dropdown-item d-flex align-items-center gap-2"
                           wire:click="$dispatch('manage-employee-areas', { employeeId: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:microsoft-azure" style="font-size:15px; color:#0078d4;"></iconify-icon>
                            Manage Device Areas
                        </a>
                    </li>
                @endif
            @endcan

            @can('sync-to-zkbio')
                @if($employee->organization?->zkbio_enabled && $employee->zkbio_pin)
                    <li>
                        <a href="javascript:void(0)"
                           class="dropdown-item d-flex align-items-center gap-2"
                           wire:click="$dispatch('sync-to-zkbio', { employeeId: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:fingerprint" style="font-size:15px; color:#e65100;"></iconify-icon>
                            Sync to ZKBio
                        </a>
                    </li>
                @endif
            @endcan

            @if($employee->active)
                @can('deactivate-employees')
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 text-warning" href="javascript:void(0)"
                           wire:click="$dispatch('deactivate-employee', { id: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:account-off-outline" class="text-warning w-4 h-4"></iconify-icon>
                            <span>Deactivate</span>
                        </a>
                    </li>
                @endcan
            @else
                @can('activate-employees')
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 text-success" href="javascript:void(0)"
                           wire:click="$dispatch('activate-employee', { id: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:account-check-outline" class="text-success w-4 h-4"></iconify-icon>
                            <span>Activate</span>
                        </a>
                    </li>
                @endcan
            @endif

            @can('delete-employees')
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="javascript:void(0)"
                       wire:click="$dispatch('delete-employee', { id: {{ $employee->id }}, name: '{{ $employee->name }}' })"
                       wire:confirm="Are you sure you want to delete {{ $employee->name }}?">
                        <iconify-icon icon="mdi:delete-outline" class="text-danger w-4 h-4"></iconify-icon>
                        <span>Delete</span>
                    </a>
                </li>
            @endcan
        </ul>
    </div>
</div>
