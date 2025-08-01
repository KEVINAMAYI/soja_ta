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
            Column::make("Created at", "created_at")
                ->sortable(),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.organizations.actions', ['organization' => $row]))
                ->html(),
        ];
    }


    public function builder(): Builder
    {
        return Organization::query()->select('organizations.*');
    }
}
