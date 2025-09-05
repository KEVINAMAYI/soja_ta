<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\AttendanceMonthlyExcelExport;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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

    public function filters(): array
    {
        return [
            DateFilter::make('Month')
                ->config([
                    'type' => 'month', // This is for frontend display
                ])
                ->filter(function ($query, string $value) {

                    $ym = Carbon::parse($value)->format('Y-m');
                    $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$ym]);
                }),
        ];
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
                // Only check for NOT NULL for DATETIME column
                DB::raw("SUM(CASE WHEN attendances.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('attendances.employee_id', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));
    }


    public function columns(): array
    {
        return [

            Column::make("Month")
                ->label(fn($row) => \Carbon\Carbon::createFromFormat('Y-m', $row->attendance_month)->format('F Y')),

            Column::make("Employee")
                ->label(fn($row) => optional($row->employee)->name ?? 'N/A'),

            Column::make("Present")
                ->label(fn($row) => "<span class='badge bg-success'>{$row->present_days}</span>")
                ->html(),

            Column::make("Absent")
                ->label(fn($row) => "<span class='badge bg-danger'>{$row->absent_days}</span>")
                ->html(),

            Column::make("Leave")
                ->label(fn($row) => "<span class='badge bg-warning text-dark'>{$row->leave_days}</span>")
                ->html(),

            Column::make("Total Days")
                ->label(fn($row) => $row->total_days),

            Column::make("Working Hours")
                ->label(fn($row) => number_format($row->total_worked_hours, 2)),

            Column::make("OT Hours")
                ->label(fn($row) => number_format($row->total_ot_hours, 2)),

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
        return Excel::download(new AttendanceMonthlyExcelExport($this->getSelected()), 'attendance.xlsx');
    }


    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('attendance-monthly.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }


}
