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
    protected array $selectedIds;

    public function __construct(array $selectedIds = [])
    {
        $this->selectedIds = $selectedIds;
    }

    public function view(): View
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $today = now()->toDateString();

        $query = Attendance::query()
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

        if (!empty($this->selectedIds)) {
            $query->whereIn('employee_id', $this->selectedIds);
        }

        $attendances = $query->get();

        return view('exports.attendance.monthly', [
            'attendances' => $attendances,
            'title' => 'Monthly Attendance Report',
            'date' => now()->format('d M Y, H:i'),
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
