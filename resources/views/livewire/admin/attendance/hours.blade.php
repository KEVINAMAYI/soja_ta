<div class="d-flex align-items-center gap-4 p-2 border rounded shadow-sm bg-white">
    @php
        $isSchool = auth()->user()->employee?->organization?->is_student_record ?? false;

        $statusConfig = $isSchool ? [
            'clocked_in'  => ['label' => 'Present',    'color' => 'success',   'icon' => 'ti-circle-check'],
            'clocked_out' => ['label' => 'Left School', 'color' => 'info',      'icon' => 'ti-logout'],
            'absent'      => ['label' => 'Unscanned',   'color' => 'danger',    'icon' => 'ti-alert-circle'],
            'on_leave'    => ['label' => 'On Leave',    'color' => 'warning',   'icon' => 'ti-plane-departure'],
            'sick_off'    => ['label' => 'Sick Off',    'color' => 'warning',   'icon' => 'ti-first-aid-kit'],
        ] : [
            'clocked_in'    => ['label' => 'Clocked In',    'color' => 'success',   'icon' => 'ti-circle-check'],
            'clocked_out'   => ['label' => 'Clocked Out',   'color' => 'info',      'icon' => 'ti-logout'],
            'absent'        => ['label' => 'Absent',        'color' => 'danger',    'icon' => 'ti-alert-circle'],
            'unchecked_in'  => ['label' => 'Not Checked In','color' => 'danger',    'icon' => 'ti-alert-circle'],
            'on_leave'      => ['label' => 'On Leave',      'color' => 'warning',   'icon' => 'ti-plane-departure'],
            'sick_off'      => ['label' => 'Sick Off',      'color' => 'warning',   'icon' => 'ti-first-aid-kit'],
            'off_shift'     => ['label' => 'Off Shift',     'color' => 'secondary', 'icon' => 'ti-calendar-off'],
            'not_scheduled' => ['label' => 'Not Scheduled', 'color' => 'secondary', 'icon' => 'ti-calendar-off'],
        ];

        $currentStatus = (!$isSchool && $attendance->status === 'unchecked_in')
            ? 'unchecked_in'
            : ($attendance->status === 'unchecked_in' ? 'absent' : $attendance->status);

        $config = $statusConfig[$currentStatus] ?? ['label' => ucfirst(str_replace('_', ' ', $currentStatus)), 'color' => 'secondary', 'icon' => 'ti-info-circle'];
    @endphp

    @if(!$isSchool)
        <div class="d-flex flex-column align-items-center text-center">
            <i class="ti ti-clock text-success fs-5 mb-1"></i>
            <small class="text-muted">Worked</small>
            <span class="fw-bold text-success">
                {{ $attendance->worked_hours !== null ? number_format($attendance->worked_hours, 2) . ' h' : '-' }}
            </span>
        </div>

        <div class="d-flex flex-column align-items-center text-center">
            <i class="ti ti-star text-primary fs-5 mb-1"></i>
            <small class="text-muted">Overtime</small>
            <span class="fw-bold text-primary">
                {{ $attendance->overtime_hours !== null ? number_format($attendance->overtime_hours, 2) . ' h' : '-' }}
            </span>
        </div>
    @endif

    <div class="d-flex align-items-center gap-3">
        <div class="bg-{{ $config['color'] }} bg-opacity-10 p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
            <i class="ti {{ $config['icon'] }} text-{{ $config['color'] }} fs-4"></i>
        </div>
        <div class="d-flex flex-column">
            <small class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Current Status</small>
            <span class="fw-bold text-{{ $config['color'] }}">{{ $config['label'] }}</span>
        </div>
    </div>
</div>
