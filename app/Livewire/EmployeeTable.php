<?php

namespace App\Livewire;

use App\Exports\EmployeesExcelExport;
use App\Models\Attendance;
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
    public ?string $initialAttendanceStatus = null;


    public function mount($type = 'student', $initialAttendanceStatus = null): void
    {

        $this->initialAttendanceStatus = $initialAttendanceStatus;

        $isStudentOrg = Auth::user()->employee?->organization?->is_student_record ?? false;

        $this->activePersonType = $isStudentOrg ? ($type ?? 'student') : '';

        $this->entityLabel = $isStudentOrg ? 'Student' : 'Employee';

        if (request()->has('active')) {
            $this->setFilter('active', request()->query('active'));
        }
    }


    #[On('filter-by-type')]
    public function filterByType($type)
    {
        $this->activePersonType = $type;
        $this->dispatch('refreshDatatable');
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
        $isStudentOrg = auth()->user()->employee?->organization?->is_student_record ?? false;

        $query = Employee::query()
            ->select('employees.*')
            ->where('organization_id', $orgId);

        // For school orgs, eager load the last attendance record per pembroke
        if ($isStudentOrg) {
            $query->with([
                'organization',
                'user',
                'assignments',
                // Load last attendance record (most recent by date, regardless of date)
                'lastAttendance',
            ]);
        } else {
            $query->with(['organization', 'shift', 'user', 'assignments']);
        }

        if ($this->activePersonType === 'student') {
            $query->where('is_student', 1);
        } elseif ($this->activePersonType === 'staff') {
            $query->where('is_student', 0);
        }

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%');
            });
        }


        // ── Apply attendance_status directly here ──────────────────────────
        // Rappasoft filter closures only fire when user actively picks a filter
        // in the UI. For the initial load from URL param, we apply it here.
        if ($this->initialAttendanceStatus) {
            match ($this->initialAttendanceStatus) {
                'present' => $query->whereHas('lastAttendance',
                    fn($q) => $q->where('status', 'clocked_in')),

                'left' => $query->whereHas('lastAttendance',
                    fn($q) => $q->where('status', 'clocked_out')),

                'not_reported' => $query->whereDoesntHave('attendances'),


                default => null,
            };
        }


        return $query;
    }

    public function columns(): array
    {

        $isStudent = $this->activePersonType == 'student' ?? false;
        $isStudentOrg = auth()->user()->employee?->organization?->is_student_record ?? false;


        $columns = [];

        // ── For non-school orgs only: Shift column ──
        if (!$isStudentOrg) {
            $columns[] = Column::make("Shift", "shift_id")
                ->format(fn($value, $row) => $row->shift?->name
                    ? "<span class='fw-semibold text-primary'>{$row->shift->name}</span>"
                    : "<span class='text-muted'>—</span>"
                )
                ->html()
                ->sortable();
        }

        // ── Student / Employee name column ──
        $columns[] = Column::make($isStudent ? "Student" : ($isStudentOrg ? "Staff" : "Employee"), "name")
            ->format(function ($value, $row) use ($isStudentOrg) {
                $icon = '<iconify-icon icon="tabler:user" style="color:var(--primary-color) !important;" class="me-2" width="20"></iconify-icon>';

                $title = $row->employee_title
                    ? "<small class='text-secondary d-block'>{$row->employee_title}</small>"
                    : '';

                $email = $row->email
                    ? "<small class='text-muted d-block'><i class='ti ti-mail me-1 text-info'></i>{$row->email}</small>"
                    : '';

                $idNumber = $row->id_number
                    ? "<small class='text-muted d-block'><i class='ti ti-id me-1 text-success'></i>ID: {$row->id_number}</small>"
                    : '';

                $gradeOrDept = $row->is_student
                    ? ($row->grade
                        ? "<small class='text-muted d-block'><i class='ti ti-school me-1 text-primary'></i>Year Group: {$row->grade}</small>"
                        : '')
                    : ($row->department
                        ? "<small class='text-muted d-block'><i class='ti ti-building me-1'></i>Dept: {$row->department->name}</small>"
                        : '');

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
                    <div class='d-flex align-items-start'>
                        {$icon}
                        <div class='d-flex flex-column'>
                            <span class='fw-semibold text-dark'>{$row->name}</span>
                            {$title}
                            {$email}
                            {$idNumber}
                            {$gradeOrDept}
                            {$zkbioPin}
                        </div>
                    </div>
                ";
            })
            ->html()
            ->sortable();

        // ── Grade / Department column ──
        $columns[] = Column::make($isStudent ? "Year Group" : "Department", "id")
            ->format(function ($value, $row) {
                if ($row->is_student) {
                    return $row->grade
                        ? "<span style='color:var(--primary-color) !important;' class='badge bg-white border px-2 py-1'>
                               <i class='ti ti-school me-1'></i>{$row->grade}
                           </span>"
                        : "<span class='text-muted'>—</span>";
                }

                return $row->department
                    ? "<span style='color:var(--primary-color) !important;' class='badge bg-white  border px-2 py-1'>
                           <i class='ti ti-building me-1'></i>{$row->department->name}
                       </span>"
                    : "<span class='text-muted'>—</span>";
            })
            ->html()
            ->collapseOnMobile();

        // ── School Status column (school orgs only, students only) ──
        // Shows Present / Left School / Not Reported based on LAST attendance
        // record regardless of date — a pembroke who checked in 2 weeks ago
        // and never checked out is still considered Present.
        if ($isStudentOrg) {
            $columns[] = Column::make("Status")
                ->label(function ($row) {

                    $last = $row->lastAttendance;

                    // REPLACE WITH:
                    if (!$last) {
                        return "
        <span class='badge' style='background:#f1f5f9; color:#64748b; padding:5px 10px; border-radius:8px; font-size:0.75rem; font-weight:600;'>
            <i class='ti ti-scan me-1'></i>Never Scanned
        </span>
    ";
                    }

                    if ($last->status === 'clocked_in') {
                        $since = $last->check_in_time
                            ? \Carbon\Carbon::parse($last->check_in_time)->format('d M, g:i A')
                            : \Carbon\Carbon::parse($last->date)->format('d M Y');
                        return "
                            <span class='badge' style='background:#dcfce7; color:#16a34a; padding:5px 10px; border-radius:8px; font-size:0.75rem; font-weight:600;'>
                                <i class='ti ti-check me-1'></i>Present
                            </span>
                            <small class='text-muted d-block mt-1' style='font-size:0.7rem;'>Since {$since}</small>
                        ";
                    }

                    if ($last->status === 'clocked_out') {
                        $when = $last->check_out_time
                            ? \Carbon\Carbon::parse($last->check_out_time)->format('d M, g:i A')
                            : \Carbon\Carbon::parse($last->date)->format('d M Y');
                        return "
                            <span class='badge' style='background:#e0f2fe; color:#0284c7; padding:5px 10px; border-radius:8px; font-size:0.75rem; font-weight:600;'>
                                <i class='ti ti-logout me-1'></i>Left School
                            </span>
                            <small class='text-muted d-block mt-1' style='font-size:0.7rem;'>Left {$when}</small>
                        ";
                    }

                    // Any other status (absent, on_leave, etc.) — treat as not reported
                    // REPLACE WITH:
                    return "
    <span class='badge' style='background:#fff3cd; color:#856404; padding:5px 10px; border-radius:8px; font-size:0.75rem; font-weight:600;'>
        <i class='ti ti-clock me-1'></i>Not On Campus
    </span>
";
                })
                ->html();
        }

        // ── Work / School Location ──
        // ── Work / School Location ──
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
            <span style='color:var(--primary-color) !important;' class='badge bg-primary-subtle border px-2 py-1 me-1 mb-1'>
                <i class='ti ti-map-pin me-1'></i>{$loc}
            </span>
        ")->implode('');
            })
            ->html()
            ->collapseOnMobile();

        // ── Roles ──
        if (!$isStudentOrg) {
            $columns[] = Column::make("Roles")
                ->label(fn($row) => view('livewire.admin.employees.roles', ['employee' => $row]))
                ->collapseOnMobile();
        } else {
            $columns[] = Column::make("Roles")
                ->label(function ($row) {
                    if ($row->is_student) {
                        return "<span class='text-muted'>—</span>";
                    }
                    return view('livewire.admin.employees.roles', ['employee' => $row]);
                })
                ->html()
                ->collapseOnMobile();
        }

        // ── Active ──
        $columns[] = BooleanColumn::make('Active')
            ->sortable()
            ->collapseOnMobile();

        // ── Actions ──
        $columns[] = Column::make("Action")
            ->label(fn($row) => view('livewire.admin.employees.actions', ['employee' => $row]));

        return $columns;
    }

    public function filters(): array
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $isStudentOrg = auth()->user()->employee?->organization?->is_student_record ?? false;

        $roleOptions = ['' => 'All Roles'] +
            Role::where('organization_id', $orgId)
                ->where('name', '!=', 'super-admin')
                ->pluck('name', 'id')
                ->toArray();

        $filters = [
            'active' => SelectFilter::make('Active')
                ->options([
                    '' => 'All',
                    '1' => 'Active',
                    '0' => 'Inactive',
                ])
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) return;
                    $builder->where('active', (int)$value);
                }),

            'role' => SelectFilter::make('Role')
                ->options($roleOptions)
                ->filter(function ($builder, $value) {
                    if ($value === '' || $value === null) return;
                    $builder->whereHas('user.roles', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
                }),
        ];

        // For school orgs, add a status filter that uses the last attendance record
        if ($isStudentOrg) {
            $filters['attendance_status'] = SelectFilter::make('Attendance Status')
                ->options([
                    '' => 'All',
                    'present' => 'Present (Still In)',
                    'left' => 'Left School',
                    'not_reported' => 'Never Scanned',
                ])
                ->filter(function ($builder, $value) {
                    // Use prop as fallback on initial load
                    $effective = ($value !== '' && $value !== null) ? $value : $this->initialAttendanceStatus;

                    if (!$effective) return;

                    if ($effective === 'present') {
                        $builder->whereHas('lastAttendance', fn($q) => $q->where('status', 'clocked_in'));
                    } elseif ($effective === 'left') {
                        $builder->whereHas('lastAttendance', fn($q) => $q->where('status', 'clocked_out'));
                        // REPLACE WITH:
                    } elseif ($effective === 'not_reported') {
                        $builder->whereDoesntHave('attendances');
                    }
                });
        }

        return $filters;
    }

    public function bulkActions(): array
    {
        return [
            'activate' => 'Activate',
            'deactivate' => 'Deactivate',
            'exportExcel' => 'Export Excel',
            'exportPdf' => 'Export PDF',
        ];
    }

    public function exportExcel()
    {
        return Excel::download(new EmployeesExcelExport($this->getSelected()), 'employees.xlsx');
    }

    public function exportPdf()
    {
        $url = route('employees.export.pdf', ['ids' => $this->getSelected()]);
        return redirect()->to($url);
    }

    public function bulkDelete()
    {
        Employee::whereIn('id', $this->getSelected())->delete();
        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees deleted successfully.')
            ->success()->toast()->position('top-end')->show();
    }

    public function activate()
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => true]);
        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees activated successfully.')
            ->success()->toast()->position('top-end')->show();
    }

    public function deactivate()
    {
        Employee::whereIn('id', $this->getSelected())->update(['active' => false]);
        $this->clearSelected();

        LivewireAlert::title('Awesome!')
            ->text('Employees deactivated successfully.')
            ->success()->toast()->position('top-end')->show();
    }
}
