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

                // ✅ Updated: count both 'clocked_in' and 'clocked_out'
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('clocked_in', 'clocked_out') THEN 1
                        ELSE 0
                    END
                ) as present_days
            "),

                // ✅ Updated: count both 'absent' and 'unchecked_in'
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('absent', 'unchecked_in') THEN 1
                        ELSE 0
                    END
                ) as absent_days
            "),

                DB::raw("SUM(CASE WHEN attendances.status = 'leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('employees.department_id', 'departments.name', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));
    }


    public function columns(): array
    {
        return [

            // 🗓 Month (bold and highlighted)
            Column::make("Month")
                ->label(fn($row) => '<span class="fw-semibold text-primary">' . \Carbon\Carbon::createFromFormat('Y-m', $row->attendance_month)->format('F Y') . '</span>')
                ->html(),

            // 🏢 Department (subtle and elegant)
            Column::make("Department")
                ->label(fn($row) => '<span class="text-dark fw-medium">' . ($row->department_name ?? 'N/A') . '</span>')
                ->html(),

            // ✅ Present Days (rounded badge)
            Column::make("Present")
                ->label(fn($row) => "<span class='badge bg-success rounded-pill px-3 py-1'>{$row->present_days}</span>")
                ->html(),

            // ❌ Absent Days (rounded badge)
            Column::make("Absent")
                ->label(fn($row) => "<span class='badge bg-danger rounded-pill px-3 py-1'>{$row->absent_days}</span>")
                ->html(),

            // 🟡 Leave Days (rounded badge)
            Column::make("Leave")
                ->label(fn($row) => "<span class='badge bg-warning text-dark rounded-pill px-3 py-1'>{$row->leave_days}</span>")
                ->html(),

            // 📊 Total Days (clean emphasis)
            Column::make("Total Days")
                ->label(fn($row) => "<span class='fw-semibold text-dark'>{$row->total_days}</span>")
                ->html(),

            // ⏱ Working Hours
            Column::make("Working Hours")
                ->label(fn($row) => "<span class='text-muted'>" . number_format($row->total_worked_hours, 2) . "</span>")
                ->html(),

            // ⏰ OT Hours
            Column::make("OT Hours")
                ->label(fn($row) => "<span class='text-muted'>" . number_format($row->total_ot_hours, 2) . "</span>")
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
