<?php

namespace App\Livewire;

use App\Exports\DepartmentAttendanceExcelExport;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class DepartmentalAttendanceTable extends DataTableComponent
{
    public $startDate;
    public $endDate;

    public function mount()
    {
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }

    public function configure(): void
    {
        $this->setPrimaryKey('department_id');
    }

    #[On('dept-date-range-updated')]
    public function updateDateRange($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
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

    public function builder(): EloquentBuilder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId);

        // Filter by date range
        if ($this->startDate) {
            $query->where('attendances.date', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $query->where('attendances.date', '<=', $this->endDate);
        }

        return $query->select(
            'employees.department_id',
            'departments.name as department_name',

            // Count unique employees in department
            DB::raw("COUNT(DISTINCT employees.id) as employee_count"),

            // Present Days (clocked_in/clocked_out)
            DB::raw("SUM(CASE WHEN attendances.status IN ('clocked_in', 'clocked_out') THEN 1 ELSE 0 END) as present_days"),

            // Absent Days
            DB::raw("SUM(CASE WHEN attendances.status IN ('absent', 'unchecked_in') THEN 1 ELSE 0 END) as absent_days"),

            // Leave Days
            DB::raw("SUM(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days"),

            // Sick Off Days
            DB::raw("SUM(CASE WHEN attendances.status = 'sick_leave' THEN 1 ELSE 0 END) as sick_days"),

            // Off Shift Days
            DB::raw("SUM(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_days"),

            // Total days
            DB::raw("COUNT(*) as total_days"),

            // Hours
            DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
            DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
        )
            ->groupBy('employees.department_id', 'departments.name');
    }

    public function columns(): array
    {
        return [
            // 🏢 Department Name
            Column::make("Department")
                ->label(fn($row) => '
                    <div>
                        <div class="fw-semibold text-dark fs-5">' . ($row->department_name ?? 'N/A') . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">' . $row->employee_count . ' employees</small>
                    </div>
                ')
                ->html(),

            // ✅ Present Days
            Column::make("Present")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-4 fw-bold text-success mb-0">' . $row->present_days . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">days worked</small>
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
                        <small class="text-muted" style="font-size: 0.7rem;">total hours</small>
                    </div>
                ')
                ->html(),

            // ⏰ Extra Hours
            Column::make("Extra Hours")
                ->label(fn($row) => '
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-warning mb-0">+' . $this->formatHoursMinutes($row->total_ot_hours) . '</div>
                        <small class="text-muted" style="font-size: 0.7rem;">overtime</small>
                    </div>
                ')
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
