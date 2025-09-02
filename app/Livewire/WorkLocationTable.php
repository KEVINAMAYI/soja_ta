<?php

namespace App\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\WorkLocation;
use Rappasoft\LaravelLivewireTables\Views\Columns\BooleanColumn;

class WorkLocationTable extends DataTableComponent
{
    protected $model = WorkLocation::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }


    public function builder(): \Illuminate\Database\Eloquent\Builder
    {

        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = WorkLocation::query()->select('work_locations.*')
            ->with(['assignments'])
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
            Column::make("Type", "type")
                ->sortable(),
            Column::make("Address", "address")
                ->sortable(),
            Column::make("Radius m", "radius_m")
                ->sortable(),
            Column::make("Description", "description")
                ->sortable(),
            BooleanColumn::make('Active')
                ->sortable()
                ->collapseOnMobile(),
        ];
    }
}
