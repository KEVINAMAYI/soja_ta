<div class="d-flex align-items-start">
    <!-- Employee Icon -->
    <iconify-icon icon="tabler:user" class="me-2 text-primary" width="20"></iconify-icon>

    <!-- Employee Details -->
    <div class="d-flex flex-column">
        <!-- Name -->
        <span class="fw-semibold text-dark">{{ $attendance->employee->name }}</span>

        <!-- Title -->
        @if($attendance->employee->employee_title)
            <small class="text-secondary d-block">{{ $attendance->employee->employee_title }}</small>
        @endif

        <!-- Department -->
        @if($attendance->employee->department?->name)
            <small class="text-muted d-block">
                <i class="ti ti-building me-1 text-primary"></i>
                {{ $attendance->employee->department->name }}
            </small>
        @endif

        <!-- Shift -->
        @if($attendance->employee->shift?->name)
            <small class="text-muted d-block">
                <i class="ti ti-clock me-1 text-warning"></i>
                {{ $attendance->employee->shift->name }}
            </small>
        @endif

    </div>
</div>
