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

        <!-- Email -->
        @if($attendance->employee->email)
            <small class="text-muted d-block">
                <i class="ti ti-mail me-1 text-info"></i>
                {{ $attendance->employee->email }}
            </small>
        @endif

        <!-- ID Number -->
        @if($attendance->employee->id_number)
            <small class="text-muted d-block">
                <i class="ti ti-id me-1 text-success"></i>
                ID: {{ $attendance->employee->id_number }}
            </small>
        @endif
    </div>
</div>
