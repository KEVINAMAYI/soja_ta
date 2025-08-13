<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Organization;

class OrganizationTable extends DataTableComponent
{
    protected $model = Organization::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function columns(): array
    {
        return [

            Column::make("Name", "name")
                ->sortable(),

            Column::make("Email", "email")
                ->sortable(),

            Column::make("Phone", "phone_number")
                ->sortable(),

            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value, $row, Column $column) => $value->format('F d, Y h:i A')),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.organizations.actions', ['organization' => $row]))
                ->html(),
        ];
    }


    public function builder(): Builder
    {
        $query =  Organization::query()->select('organizations.*');

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }


        return $query;
    }
}
