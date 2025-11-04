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

            // 🏷️ Location Name
            Column::make("Name", "name")
                ->sortable()
                ->format(function ($value) {
                    $formattedName = str_replace('_', ' ', $value);
                    return "<span class='fw-semibold text-dark'>" . ucwords($formattedName) . "</span>";
                })
                ->html(),

            // 🏷️ Type
            Column::make("Type", "type")
                ->sortable()
                ->format(fn($value) => "<span class='text-primary fw-semibold'>{$value}</span>")
                ->html(),

            // 🏠 Address
            Column::make("Address", "address")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>{$value}</span>")
                ->html(),

            // 📏 Geofence Radius
            Column::make("Geofence Radius(m)", "radius_m")
                ->sortable()
                ->format(fn($value) => "<span class='fw-semibold text-info'>{$value} m</span>")
                ->html(),

            // 📝 Description
            Column::make("Description", "description")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>{$value}</span>")
                ->html(),

            // ✅ Active Status
            BooleanColumn::make('Active')
                ->sortable()
                ->format(fn($value) => $value
                    ? "<span class='badge bg-success'>Active</span>"
                    : "<span class='badge bg-danger'>Inactive</span>")
                ->html()
                ->collapseOnMobile(),

            // ⚙️ Actions
            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.location-assignment.actions', ['work_location' => $row]))
                ->html(),
        ];
    }
}
