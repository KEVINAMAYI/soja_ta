<div class="btn-group" role="group">
    <button class="btn btn-sm btn-warning"
            wire:click="$dispatch('edit-organization',{'id' : {{ $organization->id }} })">
        <i class="ti ti-edit"></i>
    </button>

    <button class="btn btn-sm btn-danger"
            onclick="confirm('Are you sure?') || event.stopImmediatePropagation()"
            wire:click="$dispatch('delete-organization',{ 'id' : {{ $organization->id }}})">
        <i class="ti ti-trash"></i>
    </button>
</div>
