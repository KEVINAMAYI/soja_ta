<div class="d-flex align-items-start">
    <iconify-icon style="color:var(--primary-color) !important;" icon="tabler:user" class="me-2" width="20"></iconify-icon>
    <div class="d-flex flex-column">
        <span class="fw-semibold text-dark">{{ $attendance->employee->name }}</span>

        @if($attendance->employee->employee_title)
            <small class="text-secondary d-block">{{ $attendance->employee->employee_title }}</small>
        @endif

        @if($attendance->employee->email)
            <small class="text-muted d-block">
                <i class="ti ti-mail me-1 text-info"></i>{{ $attendance->employee->email }}
            </small>
        @endif

        @if($attendance->employee->id_number)
            <small class="text-muted d-block">
                <i class="ti ti-id me-1 text-success"></i>ID: {{ $attendance->employee->id_number }}
            </small>
        @endif

        @if($attendance->employee->ad_employee_id)
            <small class="text-muted d-block">
                <i class="ti ti-badge me-1 text-primary"></i>
                EMP No: <span class="badge bg-primary-subtle text-primary border px-2">{{ $attendance->employee->ad_employee_id }}</span>
            </small>
        @endif

        @if($attendance->employee->division)
            <small class="text-muted d-block">
                <i class="ti ti-layout-distribute-horizontal me-1"></i>
                <span class="text-muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.4px;">Division</span>
                &nbsp;{{ $attendance->employee->division }}
            </small>
        @endif

        @if($attendance->employee->department)
            <small class="text-muted d-block">
                <i class="ti ti-building me-1"></i>
                <span class="text-muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.4px;">Dept</span>
                &nbsp;{{ $attendance->employee->department->name }}
            </small>
        @endif

        @if($attendance->employee->section)
            <small class="text-muted d-block">
                <i class="ti ti-sitemap me-1"></i>
                <span class="text-muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.4px;">Section</span>
                &nbsp;{{ $attendance->employee->section }}
            </small>
        @endif

        @if($attendance->employee->organization?->zkbio_enabled && $attendance->employee->zkbio_pin)
            <small class="text-muted d-block">
                <i class="ti ti-fingerprint me-1 text-warning"></i>
                Device PIN: <span class="badge bg-warning-subtle text-warning border px-2">{{ $attendance->employee->zkbio_pin }}</span>
            </small>
        @elseif($attendance->employee->organization?->zkbio_enabled && !$attendance->employee->zkbio_pin)
            <small class="text-danger d-block">
                <i class="ti ti-alert-circle me-1"></i>No device PIN assigned
            </small>
        @endif

        <a style="margin-top:5px;"
           href="{{ route('attendance.employee-detailed-attendance', ['employeeId' => $attendance->employee_id]) }}"
           class="employee-detail-link-clean">
            <span>View attendance details</span>
            <iconify-icon icon="tabler:chevron-right" width="14"></iconify-icon>
        </a>
    </div>
</div>

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
        text-decoration: none;
    }
</style>
