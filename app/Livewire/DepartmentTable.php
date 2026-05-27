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
            // 🏷️ Department Name
            Column::make("Name", "name")
                ->sortable()
                ->format(fn($value) => "<span class='fw-semibold text-dark'>" . ucwords(str_replace(['-', '_'], ' ', $value)) . "</span>")
                ->html(),

            // 👤 Manager
            Column::make("Manager", "manager.name")
                ->label(function ($row) {
                    if ($row->manager) {
                        return "<span class='badge bg-primary fw-semibold'>{$row->manager->name}</span>";
                    }
                    return "<span class='text-muted'>—</span>";
                })
                ->html()
                ->sortable(function ($builder, $direction) {
                    $builder->join('users as managers', 'departments.manager_id', '=', 'managers.id')
                        ->orderBy('managers.name', $direction);
                }),

            // 📅 Created At
            Column::make("Created at", "created_at")
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>" . $value->format('F d, Y h:i A') . "</span>")
                ->html(),

            // ⚙️ Actions
            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.departments.actions', ['department' => $row]))
                ->html(),
        ];
    }
}
