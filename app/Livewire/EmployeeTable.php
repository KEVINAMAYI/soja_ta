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
            ->with(['organization', 'shift', 'user', 'assignments'])
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

            // 🕓 Shift
            Column::make("Shift", "shift_id")
                ->format(fn($value, $row) => $row->shift?->name
                    ? "<span class='fw-semibold text-primary'>{$row->shift->name}</span>"
                    : "<span class='text-muted'>—</span>"
                )
                ->html()
                ->sortable(),

            // 👤 Employee (with icon, title, email, and ID number)
            Column::make("Employee", "name")
                ->format(function ($value, $row) {
                    $icon = '<iconify-icon icon="tabler:user" class="me-2 text-primary" width="20"></iconify-icon>';

                    $title = $row->employee_title
                        ? "<small class='text-secondary d-block'>{$row->employee_title}</small>"
                        : '';

                    $email = $row->email
                        ? "<small class='text-muted d-block'><i class='ti ti-mail me-1 text-info'></i>{$row->email}</small>"
                        : '';

                    $idNumber = $row->id_number
                        ? "<small class='text-muted d-block'><i class='ti ti-id me-1 text-success'></i>ID: {$row->id_number}</small>"
                        : '';

                    return "
            <div class='d-flex align-items-start'>
                {$icon}
                <div class='d-flex flex-column'>
                    <span class='fw-semibold text-dark'>{$row->name}</span>
                    {$title}
                    {$email}
                    {$idNumber}
                </div>
            </div>
        ";
                })
                ->html()
                ->sortable(),


            // 🏢 Department
            Column::make("Department", "department_id")
                ->format(fn($value, $row) => $row->department?->name
                    ? "<span class='badge bg-light text-dark border px-3 py-2'>{$row->department->name}</span>"
                    : "<span class='text-muted'>—</span>"
                )
                ->html()
                ->sortable(),


            // 📍 Assigned Locations
            Column::make("Locations", "name")
                ->format(function ($value, $row) {

                    $locations = $row->assignments
                        ->load('location')
                        ->pluck('location.name')
                        ->filter()
                        ->unique();

                    if ($locations->isEmpty()) {
                        return "<span class='text-muted'>—</span>";
                    }

                    $badges = $locations->map(function ($loc) {
                        return "
                <span class='badge bg-primary-subtle text-primary border px-2 py-1 me-1 mb-1'>
                    <i class='ti ti-map-pin me-1'></i>{$loc}
                </span>
            ";
                    })->implode('');

                    return "<div class='d-flex flex-wrap'>{$badges}</div>";
                })
                ->html()
                ->collapseOnMobile(),

            // 🧩 Roles
            Column::make("Roles")
                ->label(fn($row) => view('livewire.admin.employees.roles', ['employee' => $row]))
                ->collapseOnMobile(),

            // 🟢 Active
            BooleanColumn::make('Active')
                ->sortable()
                ->collapseOnMobile(),

            // ⚙️ Actions
            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.employees.actions', ['employee' => $row])),
        ];
    }


    public function filters(): array
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $roleOptions = ['' => 'All Roles'] +
            Role::where('organization_id', $orgId)
                ->where('name', '!=', 'super-admin')
                ->pluck('name', 'id')
                ->toArray();

        return [

            // ✅ Active Filter (correct handling of "", 1, 0)
            'active' => SelectFilter::make('Active')
                ->options([
                    '' => 'All',
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return; // DO NOT apply filter
                    }
                    $builder->where('active', (int)$value);
                }),

            // ✅ Role Filter (organization-based + correct All behavior)
            'role' => SelectFilter::make('Role')
                ->options($roleOptions)
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return; // All roles → do not filter
                    }

                    // Filter employees that have selected role
                    $builder->whereHas('user.roles', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
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
