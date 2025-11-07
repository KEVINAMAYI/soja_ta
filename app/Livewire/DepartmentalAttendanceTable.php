<?php

namespace App\Livewire;

use App\Exports\DepartmentAttendanceExcelExport;
use App\Exports\AttendanceMonthlyExcelExport;
use App\Models\Attendance;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use Rappasoft\LaravelLivewireTables\Views\Filters\DateFilter;
use Illuminate\Support\Facades\DB;

class DepartmentalAttendanceTable extends DataTableComponent
{
    public function configure(): void
    {
        $this->setPrimaryKey('department_id');
        $this->setDefaultSort('attendance_month', 'desc');
    }

    public function filters(): array
    {
        return [
            DateFilter::make('Month')
                ->config([
                    'type' => 'month',
                ])
                ->filter(function ($query, string $value) {
                    $ym = Carbon::parse($value)->format('Y-m');
                    $query->whereRaw("DATE_FORMAT(attendances.date, '%Y-%m') = ?", [$ym]);
                }),
        ];
    }

    public function builder(): EloquentBuilder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        return Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId)
            ->select(
                'employees.department_id',
                'departments.name as department_name',
                DB::raw("DATE_FORMAT(attendances.date, '%Y-%m') as attendance_month"),

                // NEW AGGREGATION: Count of unique employees in the department for this month
                DB::raw("COUNT(DISTINCT employees.id) as employee_count"),

                // Present Days (clocked_in/clocked_out)
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('clocked_in', 'clocked_out') THEN 1
                        ELSE 0
                    END
                ) as present_days
            "),

                // Absent Days (absent/unchecked_in)
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('absent', 'unchecked_in') THEN 1
                        ELSE 0
                    END
                ) as absent_days
            "),

                // Leave Days (on_leave)
                DB::raw("SUM(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days"),

                // Off Shift Days (off_shift)
                DB::raw("SUM(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_days"),

                // Total expected attendance days (denominator for rates)
                DB::raw("COUNT(*) as total_days"),

                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('employees.department_id', 'departments.name', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));
    }


    public function columns(): array
    {
        return [
            // 🗓 Month
            Column::make("Month")
                ->label(fn($row) => '<span class="fw-semibold text-primary">' . \Carbon\Carbon::createFromFormat('Y-m', $row->attendance_month)->format('F Y') . '</span>')
                ->html(),

            // 🏢 Department
            Column::make("Department")
                ->label(fn($row) => '<span class="text-dark fw-medium">' . ($row->department_name ?? 'N/A') . '</span>')
                ->html(),

            // 👤 Employee Count (NEW)
            Column::make("Employees")
                ->label(fn($row) => "<span class='badge bg-info text-white rounded-pill px-3 py-1'>{$row->employee_count}</span>")
                ->html(),

            // ✅ Attendance Rate (DERIVED)
            Column::make("Attendance %", 'present_days')
                ->label(function($row) {
                    $rate = ($row->total_days > 0) ? ($row->present_days / $row->total_days) * 100 : 0;
                    $class = $rate >= 90 ? 'bg-success' : ($rate >= 80 ? 'bg-warning text-dark' : 'bg-danger');
                    return "<span class='badge {$class} rounded-pill px-3 py-1'>" . number_format($rate, 1) . "%</span>";
                })
                ->sortable()
                ->html(),

            // ❌ Absenteeism Rate (DERIVED)
            Column::make("Absenteeism %", 'absent_days')
                ->label(function($row) {
                    $rate = ($row->total_days > 0) ? ($row->absent_days / $row->total_days) * 100 : 0;
                    $class = $rate < 10 ? 'bg-success' : ($rate < 20 ? 'bg-warning text-dark' : 'bg-danger');
                    return "<span class='badge {$class} rounded-pill px-3 py-1'>" . number_format($rate, 1) . "%</span>";
                })
                ->sortable()
                ->html(),

            // --- Raw Data (Now simple text output) ---

            Column::make("Present Days", 'present_days')
                ->label(fn($row) => "{$row->present_days} days"),

            Column::make("Absent Days", 'absent_days')
                ->label(fn($row) => "{$row->absent_days} days"),

            Column::make("Leave Days", 'leave_days')
                ->label(fn($row) => "{$row->leave_days} days"),

            // New Column: Off Shift Days
            Column::make("Off Shift Days", 'off_shift_days')
                ->label(fn($row) => "{$row->off_shift_days} days"),

            // ⏱ Working Hours (Kept HTML for styling/formatting)
            Column::make("Working Hours", 'total_worked_hours')
                ->label(fn($row) => "<span class='text-muted'>" . number_format($row->total_worked_hours, 2) . "h</span>")
                ->html(),

            // ⏰ OT Hours (Kept HTML for styling/formatting)
            Column::make("OT Hours", 'total_ot_hours')
                ->label(fn($row) => "<span class='text-muted'>" . number_format($row->total_ot_hours, 2) . "h</span>")
                ->html(),
        ];
    }


    public function bulkActions(): array
    {
        return [
            'exportExcel' => 'Export Excel',
            'exportPdf' => 'Export PDF'
        ];
    }


    public function exportExcel()
    {
        return Excel::download(new DepartmentAttendanceExcelExport($this->getSelected()), 'department-attendance.xlsx');
    }


    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('department-attendance.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }
}
