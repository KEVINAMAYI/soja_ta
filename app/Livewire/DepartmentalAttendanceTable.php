<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
                DB::raw("SUM(CASE WHEN attendances.status = 'Present' THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'Absent' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'Leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('employees.department_id', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));
    }

    public function columns(): array
    {
        return [
            Column::make("Month")
                ->label(fn($row) => \Carbon\Carbon::createFromFormat('Y-m', $row->attendance_month)->format('F Y')),

            Column::make("Department")
                ->label(fn($row) => $row->department_name),

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

//            Column::make("View Details")
//                ->label(fn($row) => view('livewire.admin.attendance.view-department-button', [
//                    'departmentId' => $row->department_id,
//                    'month' => $row->attendance_month
//                ])),
        ];
    }
}
