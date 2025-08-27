<?php

namespace App\Livewire;

use App\Models\Employee;
use Carbon\Carbon;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Attendance;
use App\Services\AttendanceSeeder;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;
    public $min_ot_threshold = 0;
    public $status;

    protected AttendanceSeeder $seeder;


    public function mount(AttendanceSeeder $seeder, $status = null)
    {
        $this->status = $status;
        $this->seeder = $seeder;
        $orgId = auth()->user()->employee->organization_id ?? null;

        if ($status == 'unchecked_in' || $status == 'absent') {
            $this->seeder->seedMissingAttendanceRecords($orgId);
        }

        $this->min_ot_threshold = auth()->user()->employee->organization()->first()->getSetting('min_ot_threshold', 0);

    }


    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $today = now()->toDateString();
        $status = $this->status;
        $search = $this->search;

        // Base Attendance query
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereDate('date', $today)
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId));


        // Add subselects for last check_in/out from *previous* records
        $query->addSelect([
            'last_check_in' => Attendance::select('check_in_time')
                ->whereColumn('employee_id', 'attendances.employee_id')
                ->whereNotNull('check_in_time')
                ->orderByDesc('date')
                ->limit(1),
            'last_check_out' => Attendance::select('check_out_time')
                ->whereColumn('employee_id', 'attendances.employee_id')
                ->whereNotNull('check_out_time')
                ->orderByDesc('date')
                ->limit(1),
        ]);


        if (!empty($status)) {
            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } elseif ($status === 'off_shift') {
                $query->whereHas('employee.shift', function ($q) {
                    $q->where('status', 'inactive');
                });
            } else {
                $query->where('status', $status);
            }
        }


        // Apply search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%")
                    );
            });
        }

        return $query;
    }


    public function columns(): array
    {
        $threshold = $this->min_ot_threshold;

        $isAbsentFilter = in_array($this->status, ['absent', 'unchecked_in', 'off_shift']);


        return [

            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            // Updated Shift Column with start and end times
            Column::make("Shift")
                ->label(function ($row) {
                    if (!$row->employee->shift) {
                        return '<span class="text-muted">-</span>';
                    }
                    $shift = $row->employee->shift;
                    $formattedStart = Carbon::parse($shift->start_time)->format('g:i A');
                    $formattedEnd = Carbon::parse($shift->end_time)->format('g:i A');

                    return "<strong>{$shift->name}</strong><br><small>{$formattedStart} - {$formattedEnd}</small>";
                })
                ->html(),

            Column::make($isAbsentFilter ? "Last Clock-In" : "Check In", "check_in_time")
                ->format(function ($value, $row) {
                    $label = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $value = $row->last_check_in;
                        if (is_null($this->status)) {
                            $label = "<br><small class='text-muted'>(Last Check-in)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';

                    return "<div>
                    <span class='fw-semibold text-success'>{$formatted}</span>
                    {$label}
                    </div>";
                })
                ->html(),

            Column::make($isAbsentFilter ? "Last Clock-Out" : "Check Out", "check_out_time")
                ->format(function ($value, $row) {
                    $label = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $value = $row->last_check_out;
                        if (is_null($this->status)) {
                            $label = "<br><small class='text-muted'>(Last Check-Out)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';

                    return "<div>
                    <span class='fw-semibold text-danger'>{$formatted}</span>
                    {$label}
                   </div>";
                })
                ->html(),

            Column::make("Overtime(hours)", "overtime_hours")
                ->sortable()
                ->format(function ($value) use ($threshold) {
                    $badgeClass = $value >= $threshold ? 'badge bg-success' : 'badge bg-secondary';
                    $badgeText = $value >= $threshold ? 'Threshold Met' : 'Threshold Not Met';

                    return $value . '<br><span style=font-weight:bold;" class="' . $badgeClass . '" style="font-size: 0.55rem;">' . $badgeText . '</span>';
                })
                ->html(),

            Column::make("Status", "status")
                ->sortable()
                ->format(function ($value, $row, $column) {
                    // Map statuses to colors
                    $colors = [
                        'clocked_in' => 'success', // green
                        'clocked_out' => 'warning', // orange
                        'unchecked_in' => 'danger',  // red
                        'absent' => 'danger',  // red
                    ];

                    // Convert status into a readable label
                    $label = ucwords(str_replace('_', ' ', $value));

                    // Pick color class
                    $color = $colors[$value] ?? 'secondary';

                    return "<span class='badge bg-{$color}'>$label</span>";
                })
                ->html(),


        ];
    }
}
