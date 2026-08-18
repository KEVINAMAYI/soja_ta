@if ($employee->jobTitle())
    <span style="background:var(--primary-color) !important; color:white;" class="badge me-2 mb-1">{{ $employee->jobTitle()->first()->name }}</span>
@endif