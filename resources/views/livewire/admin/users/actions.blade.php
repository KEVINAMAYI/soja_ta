<div class="btn-group" role="group">
    @can('edit-users')
        <button class="btn btn-sm btn-warning"
                wire:click="$dispatch('edit-user',{'id' : {{ $user->id }} })">
            <i class="ti ti-edit"></i>
        </button>
    @endcan

    @can('edit-users')
        <button class="btn btn-sm btn-info"
                onclick="confirm('Send a password reset link to {{ $user->email }}?') || event.stopImmediatePropagation()"
                wire:click="$dispatch('reset-user-password',{ 'id' : {{ $user->id }} })">
            <i class="ti ti-key"></i>
        </button>
    @endcan

    @can('deactivate-users')
        <button class="btn btn-sm {{ $user->active ? 'btn-secondary' : 'btn-success' }}"
                onclick="confirm('{{ $user->active ? 'Deactivate' : 'Activate' }} {{ $user->name }}?') || event.stopImmediatePropagation()"
                wire:click="$dispatch('toggle-user-active',{ 'id' : {{ $user->id }} })">
            <i class="ti {{ $user->active ? 'ti-user-off' : 'ti-user-check' }}"></i>
        </button>
    @endcan

    @can('convert-to-system-user')
        <button class="btn btn-sm btn-outline-primary"
                title="Convert back to a tracked Employee"
                wire:click="$dispatch('prompt-convert-to-employee',{ 'id' : {{ $user->id }} })">
            <i class="ti ti-arrow-back-up"></i>
        </button>
    @endcan
</div>
