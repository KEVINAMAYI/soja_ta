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
        $query = Role::query()
            ->select('roles.*')
            ->where('name', '!=', 'super-admin') // Exclude global super-admin
            ->where('organization_id', auth()->user()->employee->organization_id); // Org filter

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }


    public function columns(): array
    {
        return [

            // 👤 Role Name
            Column::make("Name", "name")
                ->sortable()
                ->format(fn($value) => "<span class='fw-semibold text-dark'>" . ucwords(str_replace(['-', '_'], ' ', $value)) . "</span>")
                ->html(),

            // 📅 Created At
            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>" . $value->format('F d, Y h:i A') . "</span>")
                ->html(),

            // ⚙️ Actions
            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.roles.actions', ['roles' => $row]))
                ->html(),
        ];
    }

}
