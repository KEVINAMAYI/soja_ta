<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="employee-actions" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="employee-actions">
            @if(empty($workLocationId))
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2"
                       href="{{ route('employees.view',$employee->id) }}">
                        <iconify-icon icon="mdi:eye-outline" class="text-primary w-4 h-4"></iconify-icon>
                        <span>View</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)"
                       wire:click="$dispatch('edit-employee',{ id : {{ $employee->id }} })">
                        <iconify-icon icon="mdi:pencil-outline" class="text-warning w-4 h-4"></iconify-icon>
                        <span>Edit</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2"
                       href="javascript:void(0)"
                       wire:click="$dispatch('assign-work-location',{ id : {{ $employee->id }} })">
                        <iconify-icon icon="mdi:map-marker-radius-outline" class="text-success w-4 h-4"></iconify-icon>
                        <span>Assign Location</span>
                    </a>
                </li>
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
                <!-- Offshift Action -->
                {{--                <li>--}}
                {{--                    <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)"--}}
                {{--                       wire:click="$dispatch('set-off-shift', { id: {{ $employee->id }}, name: '{{ $employee->name }}' })">--}}
                {{--                        <iconify-icon icon="mdi:timer-sand" class="text-info w-4 h-4"></iconify-icon>--}}
                {{--                        <span>Offshift</span>--}}
                {{--                    </a>--}}
                {{--                </li>--}}
                <li>
                    @if($employee->active)
                        <a class="dropdown-item d-flex align-items-center gap-2 text-warning" href="javascript:void(0)"
                           wire:click="$dispatch('deactivate-employee', { id: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:account-off-outline" class="text-warning w-4 h-4"></iconify-icon>
                            <span>Deactivate</span>
                        </a>
                    @else
                        <a class="dropdown-item d-flex align-items-center gap-2 text-success" href="javascript:void(0)"
                           wire:click="$dispatch('activate-employee', { id: {{ $employee->id }} })">
                            <iconify-icon icon="mdi:account-check-outline" class="text-success w-4 h-4"></iconify-icon>
                            <span>Activate</span>
                        </a>
                    @endif
                </li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="javascript:void(0)"
                       wire:click="$dispatch('delete-employee', { id: {{ $employee->id }}, name: '{{ $employee->name }}' })"
                       wire:confirm="Are you sure you want to delete {{ $employee->name }}?">
                        <iconify-icon icon="mdi:delete-outline" class="text-danger w-4 h-4"></iconify-icon>
                        <span>Delete</span>
                    </a>
                </li>
            @else
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2"
                       href="javascript:void(0)"
                       wire:click="$dispatch('unassign-work-location',{ id : {{ $employee->id }} })">
                        <iconify-icon icon="mdi:map-marker-radius-outline" class="text-success w-4 h-4"></iconify-icon>
                        <span>Unassign Location</span>
                    </a>
                </li>
            @endif
        </ul>
    </div>
</div>
