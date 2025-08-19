<?php

namespace App\Livewire;

use App\Models\Shift;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;

class ShiftTable extends DataTableComponent
{
    protected $model = Shift::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id')
            ->setDefaultSort('created_at', 'desc');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {

        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Shift::query()->select('shifts.*')
            ->where('organization_id', $orgId);

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }


        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make("Name", "name")->searchable()->sortable(),
            Column::make("Start", "start_time")->sortable(),
            Column::make("End", "end_time")->sortable(),
            Column::make("Break (min)", "break_minutes"),
            Column::make("Overtime Rate", "overtime_rate"),
            Column::make("Status", "status")
        ];
    }
}

