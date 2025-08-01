<div class="btn-group" role="group">
    <button class="btn btn-sm btn-warning"
            wire:click="$dispatch('edit-employee-type',{'id' : {{ $employeeType->id }} })">
        <i class="ti ti-edit"></i>
    </button>

    <button class="btn btn-sm btn-danger"
            wire:click="$dispatch('delete-employee-type',{ 'id' : {{ $employeeType->id }}})">
        <i class="ti ti-trash"></i>
    </button>
</div>

