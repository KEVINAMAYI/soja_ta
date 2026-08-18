<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\Shift;
use Carbon\Carbon;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;

class DevicesTable extends DataTableComponent
{
    protected $model = Device::class;


    public function configure(): void
    {
        $this->setPrimaryKey('id')
            ->setDefaultSort('created_at', 'desc');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {

        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Device::query()->select('devices.*')
            ->with(['deviceLocation'])
            ->where('devices.organization_id', $orgId);

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

            Column::make("Device Name", "device_name")
                ->searchable()
                ->sortable(),

            Column::make("Platform", "platform")
                ->format(fn($value) => match (strtolower($value)) {
                    'android' => '<span class="badge" style="background-color: #007bff; color: white;">Android</span>', // Bootstrap blue
                    'ios' => '<span class="badge" style="background-color: #fd7e14; color: white;">iOS</span>',         // Bootstrap orange
                    default => '<span class="badge bg-secondary text-white">' . ucfirst($value) . '</span>',
                })
                ->html()
                ->sortable(),

            Column::make("Checkpoint ID", "checkpoint_id")
                ->format(fn($value) => '<code>' . e($value) . '</code>')
                ->html()
                ->sortable(),

            Column::make("KIOSK MODE PIN", "pin")
                ->format(fn($value) => $value ? '<span class="text-muted">' . e($value) . '</span>' : '<em class="text-muted">Auto</em>')
                ->html(),

            Column::make("Location", "deviceLocation.name") // Assumes relation deviceLocation
            ->format(fn($value, $row) => $row->deviceLocation?->name ?? '<span class="text-muted">N/A</span>')
                ->html()
                ->sortable(),

            Column::make("Status", "active")
                ->format(fn($value) => $value
                    ? '<span class="badge text-success">Online</span>'
                    : '<span class="badge text-danger">Offline</span>'
                )
                ->html(),

            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.devices.actions', ['device' => $row]))
        ];
    }

}

