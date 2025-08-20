@foreach ($employee->user->getRoleNames() as $role)
    <span class="badge bg-primary me-1">{{ $role }}</span>
@endforeach
