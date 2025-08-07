<div class="ms-auto">
    <div class="dropdown dropstart">
        <a href="javascript:void(0)" class="link" id="overtime-actions-{{ $row->id }}" data-bs-toggle="dropdown"
           aria-expanded="false">
            <i class="ti ti-dots fs-6 text-dark"></i>
        </a>
        <ul class="dropdown-menu" aria-labelledby="overtime-actions-{{ $row->id }}">
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2 text-success" href="javascript:void(0)"
                   wire:click="$dispatch('approve',{ id : {{ $row->id }} })">
                    <iconify-icon icon="mdi:check-bold" class="text-success w-4 h-4"></iconify-icon>
                    <span>Approve</span>
                </a>
            </li>
            <li>
                <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="javascript:void(0)"
                   wire:click="$dispatch('reject',{ id : {{ $row->id }} })">
                    <iconify-icon icon="mdi:close-thick" class="text-danger w-4 h-4"></iconify-icon>
                    <span>Reject</span>
                </a>
            </li>
        </ul>
    </div>
</div>

