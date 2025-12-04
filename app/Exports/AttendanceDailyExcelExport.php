<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceDailyExcelExport implements FromView, ShouldAutoSize, WithTitle, WithStyles
{
    protected array $selectedIds;
    protected $startDate;
    protected $endDate;
    protected $status;

    public function __construct(
        array   $selectedIds = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null
    )
    {
        $this->selectedIds = $selectedIds;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->status = $status;
    }

    public function view(): View
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->with(['employee.user', 'employee.department', 'employee.shift'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
            );

        // 🔹 Filter by selected employees (if any)
        if (!empty($this->selectedIds)) {
            $query->whereIn('employee_id', $this->selectedIds);
        }

        // 🔹 Filter by date range (only if BOTH dates are provided)
        if ($this->startDate && $this->endDate) {

            $this->startDate = Carbon::parse($this->startDate)->toDateString();
            $this->endDate = Carbon::parse($this->endDate)->toDateString();
            $query->whereBetween('date', [$this->startDate, $this->endDate]);
        }

        // 🔹 Filter by status (if provided)
        if ($this->status) {
            if ($this->status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            }  else if ($this->status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } elseif ($this->status === 'unchecked_in') {
                $query->where('status', 'unchecked_in');
            } else {
                $query->where('status', $this->status);
            }
        }


        $attendances = $query->get();

        return view('exports.attendance.daily', [
            'attendances' => $attendances,
            'title' => 'Attendance Report',
            'date' => now()->format('d M Y, H:i'),
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'isExcel' => true
        ]);
    }


    public function title(): string
    {
        return 'Attendance Report';
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
