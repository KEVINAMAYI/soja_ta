<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\EmployeeType;

class EmployeeTypeTable extends DataTableComponent
{
    protected $model = EmployeeType::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function columns(): array
    {
        return [

            Column::make("Name", "name")
                ->sortable(),
            Column::make("Description", "description")
                ->sortable(),
            Column::make("Created at", "created_at")
                ->sortable(),
            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.employee-types.actions', ['employeeType' => $row]))
                ->html(),
        ];
    }

    public function builder(): Builder
    {
        $query = EmployeeType::query()->select('employee_types.*');

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }

}
