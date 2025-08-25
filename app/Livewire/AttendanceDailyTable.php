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

        if (!empty($status)) {
            $query->where('status', $status);
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

            Column::make("Clocked In", "check_in_time")
                ->sortable()
                ->format(function ($value) {
                    if (!$value) return '<span class="text-muted">-</span>';
                    $formatted = Carbon::parse($value)->format('M d, Y g:i A');
                    return "<span style='font-weight: 600; color: #198754;'>$formatted</span>";  // Bootstrap green color (#198754)
                })
                ->html(),

            Column::make("Clocked Out", "check_out_time")
                ->sortable()
                ->format(function ($value) {
                    if (!$value) return '<span class="text-muted">-</span>';
                    $formatted = Carbon::parse($value)->format('M d, Y g:i A');
                    return "<span style='font-weight: 600; color: #dc3545;'>$formatted</span>";  // Bootstrap red color (#dc3545)
                })
                ->html(),

            Column::make("Worked hours", "worked_hours")
                ->sortable(),

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

        ];
    }
}
