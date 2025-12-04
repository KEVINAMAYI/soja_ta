<?php

namespace App\Exports;

use App\Models\Attendance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DepartmentAttendanceExcelExport implements FromView, ShouldAutoSize, WithTitle, WithStyles
{
    protected array $selectedIds = [];
    protected $start_date;
    protected $end_date;

    public function __construct($selectedIds, $start_date, $end_date)
    {
        $this->selectedIds = $selectedIds ?? [];
        $this->start_date = $start_date;
        $this->end_date = $end_date;
    }

    public function view(): View
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId);

        // Filter by date range
        if ($this->start_date) {
            $query->where('attendances.date', '>=', $this->start_date);
        }

        if ($this->end_date) {
            $query->where('attendances.date', '<=', $this->end_date);
        }

        $query->select(
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
        )->groupBy('employees.department_id', 'departments.name');

        if (!empty($this->selectedIds)) {
            $query->whereIn('department_id', $this->selectedIds);
        }

        $attendances = $query->get();

        return view('exports.attendance.department', [
            'attendances' => $attendances,
            'title' => 'Department Attendance Report',
            'date' => now()->format('d M Y, H:i'),
            'startDate' => $this->start_date,
            'endDate' => $this->end_date,
            'isExcel' => true
        ]);
    }


    public function title(): string
    {
        return 'Employee Report';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:R1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF2c3e50'],
            ],
        ]);


        $sheet->getStyle('A2:R2')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF2c3e50'],
            ],
        ]);

        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(20);

        $sheet->getStyle('A1:G' . $sheet->getHighestRow())->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }
}
