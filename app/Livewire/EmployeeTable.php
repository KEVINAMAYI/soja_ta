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
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeTable extends DataTableComponent
{
    protected $model = Employee::class;

    public ?int $roleId;

    public function mount($roleId = null)
    {
        $this->roleId = $roleId;
    }


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

        if (!empty($this->roleId)) {
            $query->whereHas('user.roles', function ($q) {
                $q->where('id', $this->roleId);
            });
        }

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
