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
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Department::query()->select('departments.*')
            ->with(['manager'])
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
            Column::make("Name", "name")
                ->sortable(),

            Column::make("Manager", "manager.name")
                ->label(function ($row) {
                    if ($row->manager) {
                        return "<span class='badge bg-primary'>{$row->manager->name}</span>";
                    }
                    return "<span class='text-muted'>—</span>";
                })
                ->html()
                ->sortable(function ($builder, $direction) {
                    $builder->join('users as managers', 'departments.manager_id', '=', 'managers.id')
                        ->orderBy('managers.name', $direction);
                }),

            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value, $row, Column $column) => $value->format('F d, Y h:i A')),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.departments.actions', ['department' => $row]))
                ->html(),
        ];

    }
}
