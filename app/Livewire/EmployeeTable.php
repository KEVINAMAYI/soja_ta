<?php

namespace App\Livewire;

use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Employee;
use Rappasoft\LaravelLivewireTables\Views\Columns\BooleanColumn;
use Rappasoft\LaravelLivewireTables\Views\Filters\SelectFilter;

class EmployeeTable extends DataTableComponent
{
    protected $model = Employee::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
        $this->setSearchEnabled();
        $this->setEagerLoadAllRelationsStatus(true);
    }


    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Employee::query()
            ->select('employees.*')
            ->with(['organization', 'employeeType']);

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }


    public function columns(): array
    {
        return [

            Column::make("Organization", "organization_id")
                ->format(fn($value, $row) => $row->organization?->name ?? '—')
                ->sortable(),

            Column::make("Employee Type", "employee_type_id")
                ->format(fn($value, $row) => $row->employeeType?->name ?? '—')
                ->sortable(),

            Column::make("Employee", "name")
                ->label(fn($row) => view('livewire.admin.employees.contact', ['employee' => $row]))
                ->sortable(),

            BooleanColumn::make('Active')
                ->sortable()
                ->collapseOnMobile(),

            Column::make("Created", "created_at")
                ->sortable()
                ->format(fn($value) => $value->format('Y-m-d')),

            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.employees.actions', ['employee' => $row]))

        ];
    }


    public function filters(): array
    {
        return [
            'active' => SelectFilter::make('Active')
                ->options([
                    '' => 'Any',
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->filter(function ($builder, $value) {
                    $builder->where('active', $value);
                }),
        ];
    }


    public function bulkActions(): array
    {
        return [
            'bulkDelete' => 'Delete Selected',
            'activate' => 'Activate',
            'deactivate' => 'Deactivate',
            'export' => 'Export',
        ];
    }


    public function bulkDelete()
    {
        Employee::whereIn('id', $this->getSelected())->delete();
        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees deleted successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

    }


    public function activate()
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => true]);

        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees activated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function deactivate()
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => false]);

        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees deactivated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

}
