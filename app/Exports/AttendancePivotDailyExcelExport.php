<?php

namespace App\Exports;

use App\Models\Attendance;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AttendancePivotDailyExcelExport implements WithEvents, WithTitle, ShouldAutoSize
{
    protected array $selectedIds;
    protected $startDate;
    protected $endDate;
    protected $status;

    // Built during registerEvents
    protected array $pivotMap = [];
    protected array $dates    = [];
    protected string $title   = '';

    public function __construct(
        array   $selectedIds = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null
    ) {
        $this->selectedIds = $selectedIds;
        $this->startDate   = $startDate;
        $this->endDate     = $endDate;
        $this->status      = $status;
    }

    public function title(): string
    {
        return 'Attendance Report';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->buildData();
                $sheet = $event->sheet->getDelegate();
                $dates    = $this->dates;
                $pivotMap = $this->pivotMap;

                $darkBg    = 'FF2C3E50';
                $darkerBg  = 'FF34495E';
                $white     = 'FFFFFFFF';
                $lightGrey = 'FFecf0f1';
                $altRow    = 'FFf9f9f9';

                $totalCols = 1 + count($dates) * 3;
                $lastCol   = $this->colLetter($totalCols);

                // ── Row 1: Title ──
                $sheet->setCellValue('A1', $this->title);
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => $white]],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $darkBg]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(35);

                // ── Row 2: Meta ──
                $period = '';
                if ($this->startDate && $this->endDate) {
                    $period = ' | Period: '
                        . Carbon::parse($this->startDate)->format('d M Y')
                        . ' – '
                        . Carbon::parse($this->endDate)->format('d M Y');
                }
                $sheet->setCellValue('A2', 'Generated on ' . now()->format('d M Y, H:i') . $period);
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF7f8c8d']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $lightGrey]],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(20);

                // ── Row 3: Date group headers ──
                $sheet->setCellValue('A3', 'Employee');
                $sheet->mergeCells('A3:A4'); // Employee spans rows 3 & 4
                $col = 2;
                foreach ($dates as $date) {
                    $startLetter = $this->colLetter($col);
                    $endLetter   = $this->colLetter($col + 2);
                    $sheet->setCellValue("{$startLetter}3", Carbon::parse($date)->format('M d, Y'));
                    $sheet->mergeCells("{$startLetter}3:{$endLetter}3");
                    $sheet->getStyle("{$startLetter}3")->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $col += 3;
                }
                $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['argb' => $white]],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $darkBg]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getRowDimension(3)->setRowHeight(25);

                // ── Row 4: Sub-column headers ──
                $sheet->getStyle('A4')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => $white]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $darkBg]],
                ]);
                $col = 2;
                foreach ($dates as $date) {
                    $sheet->setCellValue($this->colLetter($col) . '4', 'Clock In');
                    $sheet->setCellValue($this->colLetter($col + 1) . '4', 'Clock Out');
                    $sheet->setCellValue($this->colLetter($col + 2) . '4', 'Worked (hrs)');
                    $col += 3;
                }
                $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['argb' => $white]],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $darkerBg]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(4)->setRowHeight(20);

                // ── Rows 5+: Data ──
                $rowNum = 5;
                foreach ($pivotMap as $employeeId => $row) {
                    $employee = $row['employee'];
                    $sheet->setCellValue("A{$rowNum}", $employee->name ?? '-');

                    $col = 2;
                    foreach ($dates as $date) {
                        $att = $row['days'][$date] ?? null;

                        if ($att) {
                            $isPresent = in_array($att->status, ['clocked_in', 'clocked_out']);
                            $stillIn   = $att->status === 'clocked_in' && !$att->check_out_time;

                            $clockIn  = $att->check_in_time
                                ? Carbon::parse($att->check_in_time)->format('g:i A')
                                : '-';
                            $clockOut = $stillIn
                                ? 'Still In'
                                : ($att->check_out_time
                                    ? Carbon::parse($att->check_out_time)->format('g:i A')
                                    : '-');
                            $worked   = number_format($att->worked_hours ?? 0, 2);
                        } else {
                            $clockIn  = '-';
                            $clockOut = '-';
                            $worked   = '0.00';
                        }

                        $sheet->setCellValue($this->colLetter($col) . $rowNum, $clockIn);
                        $sheet->setCellValue($this->colLetter($col + 1) . $rowNum, $clockOut);
                        $sheet->setCellValue($this->colLetter($col + 2) . $rowNum, $worked);

                        $sheet->getStyle($this->colLetter($col) . $rowNum)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->getStyle($this->colLetter($col + 1) . $rowNum)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->getStyle($this->colLetter($col + 2) . $rowNum)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                        $col += 3;
                    }

                    // Alternating row background
                    $bg = ($rowNum % 2 === 0) ? $altRow : $white;
                    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);

                    $sheet->getRowDimension($rowNum)->setRowHeight(18);
                    $rowNum++;
                }

                // ── Borders on header + data ──
                $lastRow = $rowNum - 1;
                $sheet->getStyle("A3:{$lastCol}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['argb' => 'FFcccccc'],
                        ],
                    ],
                ]);

                // Bold employee column
                $sheet->getStyle("A5:A{$lastRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);
            },
        ];
    }

    protected function buildData(): void
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Attendance::query()
            ->with(['employee.user', 'employee.department', 'employee.shift', 'employee.organization'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId));

        if (!empty($this->selectedIds)) {
            $query->whereIn('id', $this->selectedIds);
        }

        if ($this->startDate && $this->endDate) {
            $this->startDate = Carbon::parse($this->startDate)->toDateString();
            $this->endDate   = Carbon::parse($this->endDate)->toDateString();
            $query->whereBetween('date', [$this->startDate, $this->endDate]);
        }

        if ($this->status) {
            if ($this->status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } elseif ($this->status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } elseif ($this->status === 'unchecked_in') {
                $query->where('status', 'unchecked_in');
            } else {
                $query->where('status', $this->status);
            }
        }

        $attendances = $query->orderBy('date')->get();

        $dates    = [];
        $pivotMap = [];

        foreach ($attendances as $attendance) {
            $date       = Carbon::parse($attendance->date)->toDateString();
            $employeeId = $attendance->employee_id;

            $dates[$date] = $date;

            if (!isset($pivotMap[$employeeId])) {
                $pivotMap[$employeeId] = [
                    'employee' => $attendance->employee,
                    'days'     => [],
                ];
            }

            $pivotMap[$employeeId]['days'][$date] = $attendance;
        }

        ksort($dates);

        $this->dates    = array_values($dates);
        $this->pivotMap = $pivotMap;
        $this->title    = (auth()->user()->employee->organization->name ?? 'Organization') . ' - Attendance Report';
    }

    /**
     * Convert a 1-based column index to an Excel column letter (A, B, ... Z, AA, AB, ...)
     */
    protected function colLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index  = intdiv($index, 26);
        }
        return $letter;
    }
}
