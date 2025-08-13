<?php

namespace App\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;

class DepartmentTable extends DataTableComponent
{
    protected $model = Department::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }


    public function builder(): Builder
    {
        $query = Department::query()->select('departments.*');

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
            Column::make("Name", "name")
                ->sortable(),

            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value, $row, Column $column) => $value->format('F d, Y h:i A')),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.departments.actions', ['department' => $row]))
                ->html(),
        ];
    }
}
