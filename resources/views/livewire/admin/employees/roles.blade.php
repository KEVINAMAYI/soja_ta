@if ($employee->user)
    @foreach ($employee->user->getRoleNames() as $role)
        <span style="background:var(--primary-color) !important; color:white;" class="badge me-2 mb-1">{{ $role }}</span>
    @endforeach
@endif