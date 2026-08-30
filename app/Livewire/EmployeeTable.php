<?php

namespace App\Livewire;

use App\Exports\EmployeesExcelExport;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Employee;
use Rappasoft\LaravelLivewireTables\Views\Columns\BooleanColumn;
use Rappasoft\LaravelLivewireTables\Views\Filters\SelectFilter;

class EmployeeTable extends DataTableComponent
{
    protected $model = Employee::class;
    public $entityLabel = 'Employee';
    public ?string $activePersonType = '';
    public string $empTypeFilter = '';
    public string $activeFilter = '';
    public string $unitFilter = '';
    public string $departmentFilter = '';
    public string $sectionFilter = '';
    public string $subsectionFilter = '';
    public bool $includeOutsourced = false;

    public function mount($type = 'student'): void
    {
        $isStudentOrg = Auth::user()->employee?->organization?->is_student_record ?? false;

        $this->activePersonType = $isStudentOrg ? ($type ?? 'student') : '';
        $this->entityLabel = $isStudentOrg ? 'Student' : 'Employee';

        if (request()->has('active')) {
            $this->setFilter('active', request()->query('active'));
        }
    }

    #[On('filter-by-type')]
    public function filterByType(string $empType = '', string $active = ''): void
    {
        $this->empTypeFilter = $empType;
        $this->activeFilter  = $active;
        $this->dispatch('refreshDatatable'); // ← this was missing
    }

    #[On('employee-hierarchy-filter-updated')]
    public function filterByHierarchy(
        string $unitId = '', string $departmentId = '', string $sectionId = '',
        string $subsectionId = '', bool $includeOutsourced = false
    ): void {
        $this->unitFilter = $unitId;
        $this->departmentFilter = $departmentId;
        $this->sectionFilter = $sectionId;
        $this->subsectionFilter = $subsectionId;
        $this->includeOutsourced = $includeOutsourced;
        $this->dispatch('refreshDatatable');
    }

    public function configure(): void
    {
        $this->setPrimaryKey('id');
        $this->setSearchEnabled();
        $this->setEagerLoadAllRelationsStatus(true);

        // Keep selections when the user searches or changes filters
        $this->setClearSelectedOnSearchDisabled();
        $this->setClearSelectedOnFilterDisabled();
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $isStudentOrg = auth()->user()->employee?->organization?->is_student_record ?? false;

        $query = Employee::query()
            ->select('employees.*')
            ->where('organization_id', $orgId);

        if ($isStudentOrg) {
            $query->with(['organization', 'user', 'assignments', 'lastAttendance']);
        } else {
            $query->with(['organization', 'shifts', 'user', 'assignments', 'department', 'unit', 'sectionRecord', 'subsectionRecord']);
        }

        if ($this->activePersonType === 'student') {
            $query->where('is_student', 1);
        } elseif ($this->activePersonType === 'staff') {
            $query->where('is_student', 0);
        }

        if ($this->empTypeFilter !== '') {
            $query->where('employee_type', $this->empTypeFilter);
        }

        if ($this->activeFilter !== '') {
            $query->where('active', (int)$this->activeFilter);
        }

        // Once an inactive employee has been deactivated longer than the org's
        // retention window, it's fully hidden from the list — regardless of which
        // active/inactive tab is selected. See System Settings > Employee Defaults.
        $retentionDays = (int) (\App\Models\OrganizationSetting::where('organization_id', $orgId)
            ->where('key', 'deleted_record_retention_days')
            ->value('value') ?? 90);

        if ($retentionDays > 0) {
            $query->where(function ($q) use ($retentionDays) {
                $q->where('active', 1)
                    ->orWhereNull('deactivated_at')
                    ->orWhere('deactivated_at', '>=', now()->subDays($retentionDays));
            });
        }

        $hierarchyActive = $this->unitFilter !== '' || $this->departmentFilter !== ''
            || $this->sectionFilter !== '' || $this->subsectionFilter !== '';

        if ($hierarchyActive) {
            $query->where(function ($q) {
                $q->where(function ($h) {
                    if ($this->unitFilter !== '') $h->where('unit_id', $this->unitFilter);
                    if ($this->departmentFilter !== '') $h->where('department_id', $this->departmentFilter);
                    if ($this->sectionFilter !== '') $h->where('section_id', $this->sectionFilter);
                    if ($this->subsectionFilter !== '') $h->where('subsection_id', $this->subsectionFilter);
                });
                if ($this->includeOutsourced) {
                    $q->orWhere('employee_type', 'Outsourced');
                }
            });
        }

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%')
                    ->orWhere('ad_employee_id', 'like', '%' . $this->search . '%')
                    ->orWhere('section', 'like', '%' . $this->search . '%')
                    ->orWhere('division', 'like', '%' . $this->search . '%')
                    ->orWhere('employee_type', 'like', '%' . $this->search . '%')
                    ->orWhereHas('unit', fn ($u) => $u->where('name', 'like', '%' . $this->search . '%'))
                    ->orWhereHas('sectionRecord', fn ($s) => $s->where('name', 'like', '%' . $this->search . '%'));
            });
        }

        return $query;
    }

    public function columns(): array
    {
        $isStudent = $this->activePersonType === 'student';
        $isStudentOrg = auth()->user()->employee?->organization?->is_student_record ?? false;

        $columns = [];

        // Shift — non-school orgs only
        if (!$isStudentOrg) {
            $columns[] = Column::make("Shift", "shift_id")
                ->format(function ($value, $row) {
                    // Get primary shift from pivot
                    $primaryShift = $row->shifts
                        ->firstWhere('pivot.is_primary', true)
                        ?? $row->shifts->first(); // fallback to any shift

                    if (!$primaryShift) {
                        return "<span class='text-muted'>—</span>";
                    }

                    $start = \Carbon\Carbon::parse($primaryShift->start_time)->format('g:i A');
                    $end   = \Carbon\Carbon::parse($primaryShift->end_time)->format('g:i A');

                    // Show additional shifts count if more than one
                    $extraCount = $row->shifts->count() - 1;
                    $extra = $extraCount > 0
                        ? "<br><span style='font-size:0.65rem;color:#6366f1;font-weight:600;'>+{$extraCount} more shift(s)</span>"
                        : '';

                    return "<span class='fw-semibold text-primary'>{$primaryShift->name}</span>
                    <br><small class='text-muted'>{$start} - {$end}</small>
                    {$extra}";
                })
                ->html()
                ->sortable();
        }

        // Name / identity column
        $columns[] = Column::make(
            $isStudent ? "Student" : ($isStudentOrg ? "Staff" : "Employee"),
            "name"
        )
            ->format(function ($value, $row) {
                $icon = '<iconify-icon icon="tabler:user" style="color:var(--primary-color);" class="me-2" width="20"></iconify-icon>';

                $title = $row->employee_title
                    ? "<small class='text-secondary d-block'>{$row->employee_title}</small>"
                    : '';

                $idNumber = $row->id_number
                    ? "<small class='text-muted d-block'><i class='ti ti-id me-1 text-success'></i>ID: {$row->id_number}</small>"
                    : '';

                $adEmployeeId = $row->ad_employee_id
                    ? "<small class='text-muted d-block'><i class='ti ti-badge me-1 text-primary'></i>EMP No: <strong>{$row->ad_employee_id}</strong></small>"
                    : '';

                $zkbioPin = '';
                if ($row->organization?->zkbio_enabled && $row->zkbio_pin) {
                    $zkbioPin = "<small class='text-muted d-block'>
                        <i class='ti ti-fingerprint me-1 text-warning'></i>
                        Device PIN: <span class='badge bg-warning-subtle text-warning border px-2'>{$row->zkbio_pin}</span>
                    </small>";
                } elseif ($row->organization?->zkbio_enabled && !$row->zkbio_pin) {
                    $zkbioPin = "<small class='text-danger d-block'>
                        <i class='ti ti-alert-circle me-1'></i>No device PIN assigned
                    </small>";
                }

                return "
                    <div class='d-flex align-items-start' style='max-width:260px;'>
                        {$icon}
                        <div class='d-flex flex-column' style='min-width:0;width:100%;'>
                            <span class='fw-semibold text-dark text-truncate' title='{$row->name}'>{$row->name}</span>
                            {$title}
                            <small class='text-muted d-block text-truncate'>
                                <i class='ti ti-mail me-1 text-info'></i>{$row->email}
                            </small>
                            {$idNumber}
                            {$adEmployeeId}
                            {$zkbioPin}
                        </div>
                    </div>";
            })
            ->html()
            ->sortable();

        // Org Path column (Unit > Department > Section > Subsection)
        $columns[] = Column::make(
            $isStudent ? "Year Group" : "Org Path",
            "id"
        )
            ->format(function ($value, $row) {
                if ($row->is_student) {
                    return $row->grade
                        ? "<span class='text-primary fw-semibold'>{$row->grade}</span>"
                        : "<span class='text-muted'>—</span>";
                }

                if ($row->employee_type === 'Outsourced') {
                    return "<span class='text-muted fst-italic' style='font-size:0.78rem;'>Not applicable — installed manually</span>";
                }

                $levels = [
                    ['label' => $row->unit?->name, 'bg' => '#1d4f4d'],
                    ['label' => $row->department?->name, 'bg' => '#2b6e6b'],
                    ['label' => $row->sectionRecord?->name, 'bg' => '#5a9c99'],
                    ['label' => $row->subsectionRecord?->name, 'bg' => '#9cc4c2'],
                ];

                $chips = collect($levels)->map(fn ($l) => $l['label']
                    ? "<span style='background:{$l['bg']};color:#fff;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:5px;margin:0 3px 3px 0;display:inline-block;'>{$l['label']}</span>"
                    : "<span style='font-size:0.68rem;color:#94a3b8;font-style:italic;border:1px dashed #cbd5e1;border-radius:5px;padding:1px 5px;margin:0 3px 3px 0;display:inline-block;'>—</span>"
                )->implode('');

                return "<div style='max-width:280px;'>{$chips}</div>";
            })
            ->html()
            ->collapseOnMobile();

        // ── Employee Type badge — non-student orgs only ──────────────────────
        if (!$isStudentOrg) {
            $columns[] = Column::make("Type", "employee_type")
                ->format(function ($value, $row) {
                    if (empty($value)) {
                        return "<span class='text-muted'>—</span>";
                    }

                    [$bg, $color, $icon] = match ($value) {
                        'COSMOS' => ['#e0f2fe', '#0369a1', 'mdi:office-building'],
                        'Outsourced' => ['#fde8e3', '#c0341b', 'mdi:account-switch'],
                        default => ['#f1f5f9', '#475569', 'mdi:account'],
                    };

                    return "<span style='background:{$bg}; color:{$color}; font-size:0.72rem;
                                font-weight:700; padding:3px 10px; border-radius:99px;
                                white-space:nowrap; display:inline-flex; align-items:center; gap:4px;'>
                                <iconify-icon icon='{$icon}' style='font-size:12px;'></iconify-icon>
                                {$value}
                            </span>";
                })
                ->html()
                ->sortable()
                ->collapseOnMobile();
        }

        // School attendance status
        if ($isStudentOrg) {
            $columns[] = Column::make("Status")
                ->label(function ($row) {
                    $last = $row->lastAttendance;

                    if (!$last) {
                        return "<span class='badge' style='background:var(--primary-color);color:white;
                                    padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;'>
                                    <i class='ti ti-circle-x me-1'></i>Not Enrolled
                                </span>";
                    }

                    if ($last->status === 'clocked_in') {
                        $since = $last->check_in_time
                            ? \Carbon\Carbon::parse($last->check_in_time)->format('d M, g:i A')
                            : \Carbon\Carbon::parse($last->date)->format('d M Y');
                        return "<span class='badge' style='background:#dcfce7;color:#16a34a;
                                    padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;'>
                                    <i class='ti ti-check me-1'></i>Present
                                </span>
                                <small class='text-muted d-block mt-1' style='font-size:0.7rem;'>Since {$since}</small>";
                    }

                    if ($last->status === 'clocked_out') {
                        $when = $last->check_out_time
                            ? \Carbon\Carbon::parse($last->check_out_time)->format('d M, g:i A')
                            : \Carbon\Carbon::parse($last->date)->format('d M Y');
                        return "<span class='badge' style='background:#e0f2fe;color:#0284c7;
                                    padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;'>
                                    <i class='ti ti-logout me-1'></i>Left School
                                </span>
                                <small class='text-muted d-block mt-1' style='font-size:0.7rem;'>Left {$when}</small>";
                    }

                    return "<span class='badge' style='background:var(--primary-color);color:white;
                                padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;'>
                                <i class='ti ti-circle-x me-1'></i>Not Enrolled
                            </span>";
                })
                ->html();
        }

        // Work / school location
        $columns[] = Column::make($isStudentOrg ? "School Location" : "Work Location")
            ->label(function ($row) {
                $locations = $row->assignments
                    ->load('location')
                    ->pluck('location.name')
                    ->filter()
                    ->unique();

                if ($locations->isEmpty()) {
                    return "<span class='text-muted'>—</span>";
                }

                return $locations->map(fn($loc) => "
                    <span style='color:var(--primary-color);'
                          class='badge bg-primary-subtle border px-2 py-1 me-1 mb-1'>
                        <i class='ti ti-map-pin me-1'></i>{$loc}
                    </span>"
                )->implode('');
            })
            ->html()
            ->collapseOnMobile();

        $columns[] = BooleanColumn::make('Active')->sortable()->collapseOnMobile();

        $columns[] = Column::make("Action")
            ->label(fn($row) => view('livewire.admin.employees.actions', ['employee' => $row]));

        return $columns;
    }

    public function bulkActions(): array
    {
        return [
            'activate' => 'Activate',
            'deactivate' => 'Deactivate',
            'promptAssignDevice' => 'Assign to Device',
            'exportExcel' => 'Export Excel',
//            'exportPdf' => 'Export PDF',
        ];
    }

    public function promptAssignDevice(): void
    {
        if (empty($this->getSelected())) {
            return;
        }
        $this->dispatch('open-bulk-device-modal', ids: $this->getSelected());
    }

    #[On('bulk-device-assigned')]
    public function onBulkDeviceAssigned(): void
    {
        $this->clearSelected();
    }

    public function exportExcel()
    {
        return Excel::download(
            new EmployeesExcelExport(
                selectedIds:   $this->getSelected(),
                empTypeFilter: $this->empTypeFilter,
                activeFilter:  $this->activeFilter,
            ),
            'employees.xlsx'
        );
    }

    public function exportPdf()
    {
        $url = route('employees.export.pdf', [
            'ids'      => $this->getSelected(),
            'emp_type' => $this->empTypeFilter,
            'active'   => $this->activeFilter,
        ]);
        return redirect()->to($url);
    }



    public function activate(): void
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => true]);
        $this->clearSelected();
        LivewireAlert::title('Awesome!')
            ->text('Employees activated successfully.')
            ->success()->toast()->position('top-end')->show();
    }

    public function deactivate(): void
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => false]);
        $this->clearSelected();
        LivewireAlert::title('Awesome!')
            ->text('Employees deactivated successfully.')
            ->success()->toast()->position('top-end')->show();
    }
}
