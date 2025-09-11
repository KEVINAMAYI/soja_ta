<?php

namespace App\Livewire;

use App\Exports\EmployeesExcelExport;
use App\Models\Role;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
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
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Employee::query()
            ->select('employees.*')
            ->with(['organization', 'shift', 'user'])
            ->where('organization_id', $orgId);


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

            Column::make("Shift", "shift_id")
                ->format(fn($value, $row) => $row->shift?->name ?? '—')
                ->sortable(),

            Column::make("Employee", "name")
                ->label(fn($row) => view('livewire.admin.employees.contact', ['employee' => $row]))
                ->sortable(),

            Column::make("Id Number", "id_number")
                ->sortable(),

            Column::make("Department", "department_id")
                ->format(fn($value, $row) => $row->department?->name ?? '—')
                ->sortable(),

            Column::make("Roles")
                ->label(fn($row) => view('livewire.admin.employees.roles', ['employee' => $row]))
                ->collapseOnMobile(),

            BooleanColumn::make('Active')
                ->sortable()
                ->collapseOnMobile(),

            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.employees.actions', ['employee' => $row]))

        ];
    }


    public function filters(): array
    {
        $roleOptions = ['all' => 'All Roles'] + Role::where('name', '!=', 'super-admin')
                ->pluck('name', 'id')
                ->toArray();

        return [
            'active' => SelectFilter::make('Active')
                ->options([
                    '' => 'All',
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->filter(function ($builder, $value) {
                    $builder->where('active', $value);
                }),

            'role' => SelectFilter::make('Role')
                ->options($roleOptions)
                ->filter(function ($builder, $value) {
                    if ($value === 'all' || empty($value)) {
                        // All roles EXCEPT super-admin
                        $builder->whereHas('user.roles', function ($q) {
                            $q->where('name', '!=', 'super-admin');
                        });
                    } else {
                        // Specific role
                        $builder->whereHas('user.roles', function ($q) use ($value) {
                            $q->where('id', $value);
                        });
                    }
                }),
        ];
    }

    public function bulkActions(): array
    {
        return [
            'activate' => 'Activate',
            'deactivate' => 'Deactivate',
            'exportExcel' => 'Export Excel',
            'exportPdf' => 'Export PDF'
        ];
    }

    public function exportExcel()
    {
        return Excel::download(new EmployeesExcelExport($this->getSelected()), 'employees.xlsx');
    }


    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('employees.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
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
