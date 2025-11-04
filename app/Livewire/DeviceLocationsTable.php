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
            // 🏷️ Device Name
            Column::make("Name", "name")
                ->sortable()
                ->format(fn($value) => "<span class='fw-semibold text-dark'>" . ucwords(str_replace(['-', '_'], ' ', $value)) . "</span>")
                ->html(),

            // 📝 Description
            Column::make("Description", "description")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>{$value}</span>")
                ->html(),

            // ✅ Active Status
            Column::make("Active", "active")
                ->sortable()
                ->collapseOnMobile()
                ->format(fn($value) => $value
                    ? "<span class='badge bg-success fw-semibold'>Active</span>"
                    : "<span class='badge bg-danger fw-semibold'>Inactive</span>"
                )
                ->html(),

            // 📅 Created At
            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>" . $value->format('F d, Y h:i A') . "</span>")
                ->html(),

            // ⚙️ Actions
            Column::make("Action")
                ->label(fn($row) => view(
                    'livewire.admin.location-assignment.device-location-actions',
                    ['device_location' => $row]
                ))
                ->html(),
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
