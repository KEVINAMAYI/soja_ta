@foreach ($employee->user->getRoleNames() as $role)
    <span class="badge bg-primary me-2 mb-1">{{ $role }}</span>
@endforeach
