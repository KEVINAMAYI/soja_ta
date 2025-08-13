<div class="btn-group" role="group">
    <button class="btn btn-sm btn-warning"
            wire:click="$dispatch('edit-role',{'id' : {{ $roles->id }} })">
        <i class="ti ti-edit"></i>
    </button>

    <button class="btn btn-sm btn-danger"
            onclick="confirm('Are you sure?') || event.stopImmediatePropagation()"
            wire:click="$dispatch('delete-role',{ 'id' : {{ $roles->id }}})">
        <i class="ti ti-trash"></i>
    </button>
</div>
