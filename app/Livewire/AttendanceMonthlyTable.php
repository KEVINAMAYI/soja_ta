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

    public $department_id;
    public $startDate;
    public $endDate;

    public function mount()
    {
        $this->department_id = 'all';
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }


    public function configure(): void
    {
        $this->setPrimaryKey('employee_id');
    }


    #[On('timesheet-range-updated')]
    public function filterByDateRange($startDate, $endDate, $department_id)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->department_id = $department_id;

        $this->dispatch('refreshDatatable');

    }


    public function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId);

        // Filter by department
        if ($this->department_id && $this->department_id !== 'all') {
            $query->where('employees.department_id', $this->department_id);
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
                DB::raw("SUM(CASE WHEN attendances.status IN ('clocked_in','clocked_out') THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status IN ('absent','unchecked_in') THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'sick_leave' THEN 1 ELSE 0 END) as sick_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_days"),
                DB::raw("COUNT(*) as total_days"),
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
            Column::make("Employee")
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
                department_id: $this->department_id,
                startDate: $this->startDate,
                endDate: $this->endDate
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
            'department_id' => $this->department_id,
        ]);

        return redirect()->to($url);
    }

}
