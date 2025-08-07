<?php

namespace App\Livewire;

use App\Models\Overtime;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use Illuminate\Database\Eloquent\Builder;

class OvertimeTable extends DataTableComponent
{
    protected $model = Overtime::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
        $this->setDefaultSort('date', 'desc');
    }

    public function builder(): Builder
    {
        return Overtime::query()
            ->select('overtimes.*')
            ->with(['employee', 'approver']);
    }

    public function columns(): array
    {
        return [
            Column::make('#', 'id')
                ->format(fn($value, $row, $column) => $row->id),

            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.overtime.employee', ['overtime' => $row])),

            Column::make('Date', 'date')
                ->sortable(),

            Column::make('Start Time', 'start_time')
                ->sortable(),

            Column::make('End Time', 'end_time')
                ->sortable(),

            Column::make('Hours', 'hours')
                ->sortable(),

            Column::make('Status')
                ->label(function ($row) {
                    return view('livewire.admin.overtime.status', ['row' => $row]);
                })
                ->html(),

            Column::make('Actions')
                ->label(function ($row) {
                    return view('livewire.admin.overtime.actions', ['row' => $row]);
                })
                ->html(),
        ];
    }
}
