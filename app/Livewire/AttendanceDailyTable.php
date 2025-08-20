<?php

namespace App\Livewire;

use Carbon\Carbon;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Attendance;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;

    public $status = '';
    public $min_ot_threshold = 0;
    public $employee;

    public function mount()
    {
        $this->employee = auth()->user()->employee()->with('organization')->first();
        $this->min_ot_threshold = $this->employee->organization->getSetting('min_ot_threshold', 0);
    }

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {

        $orgId = $this->employee->organization->id;

        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereHas('employee', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            });

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('status', 'like', '%' . $this->search . '%')
                    ->orWhereHas('employee', function ($q) {
                        $q->where('name', 'like', '%' . $this->search . '%');
                    });
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

            Column::make("Time In", "check_in_time")
                ->sortable()
                ->format(function ($value) {
                    if (!$value) return '<span class="text-muted">-</span>';
                    $formatted = Carbon::parse($value)->format('M d, Y g:i A');
                    return "<span style='font-weight: 600; color: #198754;'>$formatted</span>";  // Bootstrap green color (#198754)
                })
                ->html(),

            Column::make("Time Out", "check_out_time")
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
                ->sortable(),
            Column::make("Created at", "created_at")
                ->sortable(),
        ];
    }
}
