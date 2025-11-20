<div class="d-flex align-items-center gap-4 p-2 border rounded shadow-sm">

    <!-- Worked Hours -->
    <div class="d-flex flex-column align-items-center text-center">
        <i class="ti ti-clock text-success fs-5 mb-1"></i>
        <small class="text-muted">Worked</small>
        <span class="fw-bold text-success">
            {{ $attendance->worked_hours !== null ? number_format($attendance->worked_hours, 2) . ' h' : '-' }}
        </span>
    </div>

    <!-- Overtime -->
    <div class="d-flex flex-column align-items-center text-center">
        <i class="ti ti-star text-primary fs-5 mb-1"></i>
        <small class="text-muted">Overtime</small>
        <span class="fw-bold text-primary">
            {{ $attendance->overtime_hours !== null ? number_format($attendance->overtime_hours, 2) . ' h' : '-' }}
        </span>
    </div>

    <!-- Status -->
    <div class="d-flex flex-column align-items-center text-center">
        <i class="ti ti-info-circle text-secondary fs-5 mb-1"></i>
        <small class="text-muted">Status</small>
        @php
            $statusLabel = $attendance->status;

            // Map colors
            $colors = [
                'clocked_in' => 'success',
                'clocked_out' => 'primary',
                'absent' => 'danger',
                'on_leave' => 'warning',
                'off_shift' => 'secondary',
                'sick_off' => 'info',
            ];

            // Handle unchecked_in as Absent
            if ($statusLabel === 'unchecked_in') {
                $statusLabel = 'absent';
                $color = 'primary'; // override color
                $label = 'Absent';
            } else {
                $color = $colors[$statusLabel] ?? 'secondary';
                $label = ucfirst(str_replace('_', ' ', $statusLabel));
            }
        @endphp
        <span class="badge bg-{{ $color }} px-2 py-1 mt-1">{{ $label }}</span>
    </div>


</div>
