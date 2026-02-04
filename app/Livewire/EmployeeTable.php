<?php

namespace App\Livewire;

use App\Exports\EmployeesExcelExport;
use App\Models\Role;
use App\Models\Shift;
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

    public function mount(): void
    {
        // If the URL contains ?active=0 or ?active=1
        if (request()->has('active')) {
            $this->setFilter('active', request()->query('active'));
        }
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
            ->with([
                'organization',
                'shift', // Legacy single shift
                'currentShift', // Current active shift
                'shifts' => function ($query) {
                    $query->withPivot(['priority', 'is_active', 'effective_from', 'effective_until'])
                        ->orderByPivot('priority', 'desc');
                },
                'activeShifts', // All active shifts
                'user',
                'assignments',
                'department'
            ])
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

                    $department = $row->department
                        ? "<small class='text-muted d-block'><i class='ti ti-building me-1'></i>Department: {$row->department->name}</small>"
                        : '';

                    return "
                        <div class='d-flex align-items-start'>
                            {$icon}
                            <div class='d-flex flex-column'>
                                <span class='fw-semibold text-dark'>{$row->name}</span>
                                {$title}
                                {$email}
                                {$idNumber}
                                {$department}
                            </div>
                        </div>
                    ";
                })
                ->html()
                ->sortable(),

            // 🕓 Current Active Shift
            Column::make("Current Shift", "current_shift_id")
                ->format(function ($value, $row) {
                    if ($row->currentShift) {
                        $shiftName = htmlspecialchars($row->currentShift->name);
                        $shiftTime = $row->currentShift->start_time && $row->currentShift->end_time
                            ? "<small class='d-block text-muted'>" . date('g:i A', strtotime($row->currentShift->start_time)) . " - " . date('g:i A', strtotime($row->currentShift->end_time)) . "</small>"
                            : '';

                        return "
                            <div class='d-flex flex-column'>
                                <span class='badge bg-success-subtle text-success border border-success px-2 py-1'>
                                    <i class='ti ti-clock me-1'></i>{$shiftName}
                                </span>
                                {$shiftTime}
                            </div>
                        ";
                    }

                    return "<span class='text-muted'>—</span>";
                })
                ->html()
                ->sortable(),

            // 🕓 All Assigned Shifts
            Column::make("Assigned Shifts", "id")
                ->format(function ($value, $row) {
                    // Get only ACTIVE shifts using the activeShifts relationship
                    $activeShifts = $row->activeShifts;

                    if ($activeShifts->isEmpty()) {
                        return "<span class='text-muted'>—</span>";
                    }

                    $badges = $activeShifts->map(function ($shift) use ($row) {
                        $isCurrent = $row->current_shift_id == $shift->id;
                        $priority = $shift->pivot->priority ?? 1;

                        // Determine badge style
                        if ($isCurrent) {
                            $badgeClass = 'bg-success text-white';
                            $icon = '<i class="ti ti-star-filled me-1"></i>';
                            $label = 'Current';
                        } else {
                            $badgeClass = 'bg-primary-subtle text-primary border border-primary';
                            $icon = '<i class="ti ti-clock me-1"></i>';
                            $label = 'Assigned';
                        }

                        $shiftName = htmlspecialchars($shift->name);
                        $priorityBadge = $priority > 1 ? "<small class='ms-1 opacity-75'>P{$priority}</small>" : '';

                        $tooltip = $this->buildShiftTooltip($shift, $isCurrent);

                        return "
                            <span class='badge {$badgeClass} px-2 py-1 me-1 mb-1'
                                  data-bs-toggle='tooltip'
                                  data-bs-html='true'
                                  title='{$tooltip}'>
                                {$icon}{$shiftName}{$priorityBadge}
                            </span>
                        ";
                    })->implode('');

                    $count = $activeShifts->count();
                    $countLabel = $count === 1 ? '1 shift' : "{$count} shifts";

                    return "
                        <div class='d-flex flex-wrap align-items-center'>
                            {$badges}
                            <small class='text-muted ms-2'>({$countLabel})</small>
                        </div>
                    ";
                })
                ->html()
                ->collapseOnMobile(),


            // 📍 Assigned Locations
            Column::make("Work Locations", "name")
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

    /**
     * Build tooltip content for shift badge
     */
    private function buildShiftTooltip($shift, bool $isCurrent = false): string
    {
        $details = [];

        // Current status
        if ($isCurrent) {
            $details[] = '<strong>Status:</strong> <span class="text-success">✓ Currently Active</span>';
        }

        // Time range
        if ($shift->start_time && $shift->end_time) {
            $details[] = '<strong>Time:</strong> ' . date('g:i A', strtotime($shift->start_time)) . ' - ' . date('g:i A', strtotime($shift->end_time));
        }

        // Pattern type
        if ($shift->pattern_type) {
            $patternLabel = ucfirst(str_replace('_', ' ', $shift->pattern_type));
            $details[] = '<strong>Pattern:</strong> ' . $patternLabel;
        }

        // Days (if custom or rotating)
        if (in_array($shift->pattern_type, ['custom', 'rotating']) && !empty($shift->pattern_days)) {
            $days = is_array($shift->pattern_days) ? implode(', ', $shift->pattern_days) : $shift->pattern_days;
            $details[] = '<strong>Days:</strong> ' . $days;
        }

        // Grace period
        if ($shift->grace_period_enabled && $shift->grace_period_minutes) {
            $details[] = '<strong>Grace Period:</strong> ' . $shift->grace_period_minutes . ' mins';
        }

        return implode('<br>', $details);
    }

    public function filters(): array
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $roleOptions = ['' => 'All Roles'] +
            Role::where('organization_id', $orgId)
                ->where('name', '!=', 'super-admin')
                ->pluck('name', 'id')
                ->toArray();

        $shiftOptions = ['' => 'All Shifts'] +
            Shift::where('organization_id', $orgId)
                ->pluck('name', 'id')
                ->toArray();

        return [
            // ✅ Active Filter
            'active' => SelectFilter::make('Active')
                ->options([
                    '' => 'All',
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return;
                    }
                    $builder->where('active', (int)$value);
                }),

            // ✅ Role Filter
            'role' => SelectFilter::make('Role')
                ->options($roleOptions)
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return;
                    }

                    $builder->whereHas('user.roles', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
                }),

            // ✅ Current Shift Filter
            'current_shift' => SelectFilter::make('Current Shift')
                ->options($shiftOptions)
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return;
                    }
                    $builder->where('current_shift_id', $value);
                }),

            // ✅ Assigned Shift Filter (any of employee's shifts)
            'assigned_shift' => SelectFilter::make('Has Shift Assigned')
                ->options($shiftOptions)
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) {
                        return;
                    }

                    $builder->whereHas('shifts', function ($q) use ($value) {
                        $q->where('shifts.id', $value);
                    });
                }),

            // ✅ Shift Status Filter
            'shift_status' => SelectFilter::make('Shift Status')
                ->options([
                    '' => 'All Statuses',
                    'active' => 'Active',
                    'off_shift' => 'Off Shift',
                    'sick_off' => 'Sick Off',
                ])
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null || $value === 'active') {
                        return;
                    }
                    $builder->where('shift_status', $value);
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
