<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Models\Employee;
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

class AttendanceMonthlyExcelExport implements FromView, ShouldAutoSize, WithTitle, WithStyles
{
    protected array $selected;
    protected $department_id;
    protected $startDate;
    protected $endDate;

    public function __construct($selected = [], $department_id = null, $startDate = null, $endDate = null)
    {
        $this->selected = $selected;
        $this->department_id = $department_id;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function baseQuery()
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
        if ($this->startDate) {
            $query->where('attendances.date', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $query->where('attendances.date', '<=', $this->endDate);
        }

        return $query;
    }


    public function view(): View
    {
        $query = $this->baseQuery()
            ->with([
                'employee',
                'employee.department',
                'employee.shift',
            ])
            ->select(
                'attendances.employee_id',

                // Present
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('clocked_in','clocked_out')
                        OR attendances.check_in_time IS NOT NULL
                        THEN 1 ELSE 0
                    END
                ) as present_days
            "),

                // Absent
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('absent','unchecked_in')
                        THEN 1 ELSE 0
                    END
                ) as absent_days
            "),

                // Leave
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status = 'on_leave' THEN 1 ELSE 0
                    END
                ) as leave_days
            "),

                // Sick leave
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('sick_leave','sick_off')
                        THEN 1 ELSE 0
                    END
                ) as sick_days
            "),

                // Off shift days
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status = 'off_shift'
                        THEN 1 ELSE 0
                    END
                ) as off_shift_days
            "),

                // Totals
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('attendances.employee_id');

        // Filter selected employees
        if (!empty($this->selected)) {
            $query->whereIn('attendances.employee_id', $this->selected);
        }

        return view('exports.attendance.monthly', [
            'attendances' => $query->get(),
            'title' => 'TimeSheets Report',
            'date' => now()->format('d M Y, H:i'),
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'isExcel' => true,
        ]);
    }


    public function title(): string
    {
        return 'Timesheets Report';
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
