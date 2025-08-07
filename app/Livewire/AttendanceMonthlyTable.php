<?php

namespace App\Livewire;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
        return Attendance::with('employee')
        ->select(
            'employee_id',
            DB::raw("DATE_FORMAT(date, '%Y-%m') as attendance_month"),
            DB::raw("SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days"),
            DB::raw("SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days"),
            DB::raw("SUM(CASE WHEN status = 'Leave' THEN 1 ELSE 0 END) as leave_days"),
            DB::raw("COUNT(*) as total_days"),
            DB::raw("SUM(worked_hours) as total_worked_hours"),
            DB::raw("SUM(overtime_hours) as total_ot_hours")
        )->groupBy('employee_id', DB::raw("DATE_FORMAT(date, '%Y-%m')"));
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

            Column::make("View Details")
                ->label(fn($row) => view('livewire.admin.attendance.view-button', [
                    'employeeId' => $row->employee_id,
                    'month' => $row->attendance_month
                ])),
        ];
    }
}
