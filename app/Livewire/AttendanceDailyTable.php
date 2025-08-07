<?php

namespace App\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Attendance;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;

    public $status = '';

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee']);

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
        return [
            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            Column::make("Date", "date")
                ->sortable(),
            Column::make("Check in time", "check_in_time")
                ->sortable(),
            Column::make("Check out time", "check_out_time")
                ->sortable(),
            Column::make("Worked hours", "worked_hours")
                ->sortable(),
            Column::make("Overtime hours", "overtime_hours")
                ->sortable(),
            Column::make("Status", "status")
                ->sortable(),
            Column::make("Created at", "created_at")
                ->sortable(),
        ];
    }
}
