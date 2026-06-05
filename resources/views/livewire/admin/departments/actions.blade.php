<div class="btn-group" role="group">
    <button class="btn btn-sm btn-warning"
            wire:click="$dispatch('edit-department',{'id' : {{ $department->id }} })">
        <i class="ti ti-edit"></i>
    </button>

    <button class="btn btn-sm btn-danger"
            onclick="confirm('Are you sure?') || event.stopImmediatePropagation()"
            wire:click="$dispatch('delete-department',{ 'id' : {{ $department->id }}})">
        <i class="ti ti-trash"></i>
    </button>
</div>
