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
    public function configure(): void
    {
        $this->setPrimaryKey('employee_id');
    }


    public function builder(): EloquentBuilder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        return Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->with('employee')
            ->select(
                'attendances.employee_id',
                DB::raw("DATE_FORMAT(attendances.date, '%Y-%m') as attendance_month"),
                DB::raw("SUM(CASE WHEN attendances.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'absent' OR attendances.status = 'unchecked_in' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('attendances.employee_id', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));
    }


    public function columns(): array
    {
        return [

            // 🗓 Month
            Column::make("Month")
                ->label(fn($row) => '<span class="fw-semibold text-primary">' . \Carbon\Carbon::createFromFormat('Y-m', $row->attendance_month)->format('F Y') . '</span>')
                ->html(),

            // 👤 Employee Column (beautified, consistent with other table)
            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            // ✅ Present Days
            Column::make("Present")
                ->label(fn($row) => "<span class='badge bg-success rounded-pill px-2 py-1'>{$row->present_days}</span>")
                ->html(),

            // ❌ Absent Days
            Column::make("Absent")
                ->label(fn($row) => "<span class='badge bg-danger rounded-pill px-2 py-1'>{$row->absent_days}</span>")
                ->html(),

            // 🟡 Leave Days
            Column::make("Leave")
                ->label(fn($row) => "<span class='badge bg-warning text-dark rounded-pill px-2 py-1'>{$row->leave_days}</span>")
                ->html(),

            // 🟡 Leave Days
            Column::make("Off Shift")
                ->label(fn($row) => "<span class='badge bg-info text-dark rounded-pill px-2 py-1'>{$row->off_shift_days}</span>")
                ->html(),

            // 📊 Total Days
            Column::make("Total Days")
                ->label(fn($row) => "<span class='fw-semibold'>{$row->total_days}</span>")
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


    #[On('export-monthly-excel')]
    public function exportExcel()
    {
        return Excel::download(new AttendanceMonthlyExcelExport($this->getSelected()), 'attendance.xlsx');
    }


    #[On('export-monthly-pdf')]
    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('attendance-monthly.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }


}
