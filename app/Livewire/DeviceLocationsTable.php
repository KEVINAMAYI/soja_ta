<?php

namespace App\Livewire;

use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\DeviceLocation;
use Rappasoft\LaravelLivewireTables\Views\Columns\BooleanColumn;

class DeviceLocationsTable extends DataTableComponent
{
    protected $model = DeviceLocation::class;
    public $workLocationId;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
        $this->setDefaultSort('created_at', 'desc');

    }



    public function mount($workLocationId)
    {
        $this->workLocationId = $workLocationId;
    }

    public function columns(): array
    {
        return [
            Column::make("Name", "name")
                ->sortable(),
            Column::make("Description", "description")
                ->sortable(),
            BooleanColumn::make('Active')
                ->sortable()
                ->collapseOnMobile(),
            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value, $row, Column $column) => $value->format('F d, Y h:i A')),

            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.location-assignment.device-location-actions', ['device_location' => $row]))

        ];
    }

    public function builder(): Builder
    {
        $query = DeviceLocation::query()->select('device_locations.*')
            ->where('work_location_id', $this->workLocationId);

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }
}
