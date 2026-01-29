<!-- ORIGINAL CODE (Keep as is): -->
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

        <!-- 👇 CLEAN MINIMAL LINK - ADD THIS 👇 -->
        <a style="margin-top:5px;" href="{{ route('attendance.employee-detailed-attendance', ['employeeId' => $attendance->employee_id]) }}"
           class="employee-detail-link-clean">
            <span>View attendance details</span>
            <iconify-icon icon="tabler:chevron-right" width="14"></iconify-icon>
        </a>
        <!-- 👆 END OF LINK 👆 -->

    </div>
</div>

<!-- 🎨 CLEAN MINIMAL CSS -->
<style>
    .employee-detail-link-clean {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        font-size: 0.75rem;
        color: #64748b;
        text-decoration: none;
        margin-top: 3px;
        font-weight: 400;
        transition: all 0.2s ease;
    }

    .employee-detail-link-clean:hover {
        color: #3b82f6;
        gap: 4px;
    }

    .employee-detail-link-clean:hover {
        text-decoration: none;
    }
</style>
