<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\AttendanceMonthlyExcelExport;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use Rappasoft\LaravelLivewireTables\Views\Filters\DateFilter;
use Illuminate\Support\Facades\DB;

class AttendanceMonthlyTable extends DataTableComponent
{

    public $startDate;
    public $endDate;
    public $unitId = null;
    public $departmentId = null;
    public $sectionId = null;
    public $subsectionId = null;
    public $includeOutsourced = false;
    public $employeeIds = [];

    public function mount(bool $hideSearch = false)
    {
        // The reports/export page already has its own employee search+picker
        // above this table — its native search box would just be a redundant
        // second way to do the same thing there.
        if ($hideSearch) {
            $this->setSearchDisabled();
        }
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }


    public function configure(): void
    {
        $this->setPrimaryKey('employee_id');
    }


    #[On('timesheet-range-updated')]
    public function filterByDateRange(
        $startDate, $endDate, $unit_id = null, $department_id = null,
        $section_id = null, $subsection_id = null, $include_outsourced = null,
        $employee_ids = null
    ) {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->unitId = $unit_id;
        $this->departmentId = $department_id;
        $this->sectionId = $section_id;
        $this->subsectionId = $subsection_id;
        $this->includeOutsourced = (bool) $include_outsourced;
        $this->employeeIds = $employee_ids ?? [];

        $this->dispatch('refreshDatatable');

    }


    public function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId);

        // Unit > Department > Section > Subsection filter. Outsourced employees have
        // null unit/department/section/subsection by definition, so they're excluded
        // unless includeOutsourced ORs them back in.
        $hierarchyActive = $this->unitId || $this->departmentId || $this->sectionId || $this->subsectionId;
        if ($hierarchyActive) {
            $query->where(function ($q) {
                $q->where(function ($h) {
                    if ($this->unitId) $h->where('employees.unit_id', $this->unitId);
                    if ($this->departmentId) $h->where('employees.department_id', $this->departmentId);
                    if ($this->sectionId) $h->where('employees.section_id', $this->sectionId);
                    if ($this->subsectionId) $h->where('employees.subsection_id', $this->subsectionId);
                });
                if ($this->includeOutsourced) {
                    $q->orWhere('employees.employee_type', 'Outsourced');
                }
            });
        }

        // Specific, named employees picked from the report's employee search
        if (!empty($this->employeeIds)) {
            $query->whereIn('attendances.employee_id', $this->employeeIds);
        }

        // Date filtering
        if ($this->startDate || $this->endDate) {
            $query->whereBetween('attendances.date', [$this->startDate, $this->endDate]);
        }

        return $query;
    }

    public function builder(): EloquentBuilder
    {
        return $this->baseQuery()
            ->with([
                'employee',
                'employee.department',
                'employee.shift',
            ])
            ->select(
                'attendances.employee_id',
                DB::raw("COUNT(DISTINCT attendances.date) as total_days"),
                DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('clocked_in','clocked_out') THEN attendances.date END) as present_days"),
                DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('absent','unchecked_in') THEN attendances.date END) as absent_days"),
                DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'on_leave' THEN attendances.date END) as leave_days"),
                DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'sick_leave' THEN attendances.date END) as sick_days"),
                DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'off_shift' THEN attendances.date END) as off_shift_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('attendances.employee_id');
    }

    /**
     * Helper function to format hours into "Xh Ym" format
     */
    private function formatHoursMinutes($hours): string
    {
        if (empty($hours) || $hours == 0) {
            return '0h 0m';
        }

        $totalMinutes = round($hours * 60);
        $h = floor($totalMinutes / 60);
        $m = $totalMinutes % 60;

        return "{$h}h {$m}m";
    }


    public function columns(): array
    {
        return [
            // 👤 Employee Column
            Column::make(auth()->user()->employee?->organization?->is_student_record  ? "Student" : "Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee-timesheet', ['attendance' => $row])),

            // ✅ Present Days
            Column::make("Present")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-success mb-0">' . $row->present_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days</small>
                    </div>
                ')
                ->html(),

            // ❌ Absent Days
            Column::make("Absent")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-danger mb-0">' . $row->absent_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days</small>
                    </div>
                ')
                ->html(),

            // 🟡 Leave Days
            Column::make("Leave")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-warning mb-0">' . $row->leave_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days</small>
                    </div>
                ')
                ->html(),

            // 🤒 Sick Off
            Column::make("Sick Off")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-secondary mb-0">' . $row->sick_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days</small>
                    </div>
                ')
                ->html(),

            // 🔵 Off Shift
            Column::make("Off Shift")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-info mb-0">' . $row->off_shift_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days</small>
                    </div>
                ')
                ->html(),

            // ⏱ Working Hours
            Column::make("Working Hours")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-5 fw-semibold text-dark mb-0">' . $this->formatHoursMinutes($row->total_worked_hours) . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">of ' . $this->formatHoursMinutes($row->total_days * 8) . '</small>
                    </div>
                ')
                ->html(),

            // ⏰ Extra Hours (OT)
            Column::make("Overtime")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-warning mb-0">+' . $this->formatHoursMinutes($row->total_ot_hours) . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">overtime</small>
                    </div>
                ')
                ->html(),

        ];
    }


    #[On('export-monthly-excel')]
    public function exportExcel()
    {
        return Excel::download(
            new AttendanceMonthlyExcelExport(
                selected: $this->getSelected(),
                unitId: $this->unitId,
                departmentId: $this->departmentId,
                sectionId: $this->sectionId,
                subsectionId: $this->subsectionId,
                includeOutsourced: $this->includeOutsourced,
                startDate: $this->startDate,
                endDate: $this->endDate,
                employeeIds: $this->employeeIds
            ),
            'attendance.xlsx'
        );
    }


    #[On('export-monthly-pdf')]
    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('attendance-monthly.export.pdf', [
            'ids' => $this->getSelected(),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            // AttendanceReportService::getMonthly() still takes a department_ids array
            // (out of scope for this change) — adapt the new singular filter to that
            // shape rather than reworking that deeper service here.
            'department_ids' => $this->departmentId ? [$this->departmentId] : [],
        ]);

        return redirect()->to($url);
    }

}
