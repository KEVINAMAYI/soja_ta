<?php

namespace App\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class RoleTable extends DataTableComponent
{
    protected $model = Role::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }


    public function builder(): Builder
    {
        $query = Role::query()->select('roles.*')
            ->where('name', '!=', 'super-admin'); // Exclude super-admin

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
                ->sortable()
                ->format(fn($value) => ucwords(str_replace(['-', '_'], ' ', $value))),

            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value, $row, Column $column) => $value->format('F d, Y h:i A')),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.roles.actions', ['roles' => $row]))
                ->html(),
        ];
    }
}
