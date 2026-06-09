<?php

namespace App\Exports\Sheets;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

/**
 * Sheet 2 — Present
 *
 * A  Date
 * B  Employee Type
 * C  Employee Number
 * D  Name
 * E  Department
 * F  Section
 * G  Staff Category
 * H  Shift (Day/Night)
 * I  Defined Time In
 * J  Actual Time In
 * K  Defined Time Out
 * L  Actual Time Out
 * M  Total Hours Worked
 */
class PresentSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'M';
    private const TOTAL_COLS = 13;

    private array $shiftCache = [];

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {
        $shiftIds = collect($records)->pluck('shift_id')->filter()->unique()->values()->toArray();
        if (!empty($shiftIds)) {
            $this->shiftCache = \App\Models\Shift::whereIn('id', $shiftIds)->get()->keyBy('id')->all();
        }
    }

    public function title(): string { return 'Present'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16,
            'D' => 24, 'E' => 18, 'F' => 14,
            'G' => 18, 'H' => 10, 'I' => 11,
            'J' => 11, 'K' => 11, 'L' => 11,
            'M' => 13,
        ];
    }

    public function array(): array
    {
        $out = [];

        $out[] = ['PRESENT REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [
            'Date',                 // A
            'Employee Type',        // B
            'Employee Number',      // C
            'Name',                 // D
            'Department',           // E
            'Section',              // F
            'Staff Category',       // G
            "Shift\n(Day/Night)",   // H
            "Defined\nTime In",     // I
            "Actual\nTime In",      // J
            "Defined\nTime Out",    // K
            "Actual\nTime Out",     // L
            "Total Hours\nWorked",  // M
        ];

        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $this->resolveShiftObject($r, $this->shiftCache);

            $out[] = [
                $this->fmtDate($r->date),                          // A
                $emp?->employee_type ?? '',                         // B
                $emp?->ad_employee_id ?? $emp?->id ?? '',           // C
                $emp?->name ?? '',                                  // D
                $emp?->department?->name ?? '',                     // E
                $emp?->section ?? '',                               // F
                $this->staffCategory($shift),                       // G
                $this->shiftDayNight($shift),                       // H
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '', // I
                $this->fmtTime($r->check_in_time),                 // J
                $shift ? Carbon::parse($shift->end_time)->format('H:i') : '',   // K
                $this->fmtTime($r->check_out_time),                // L
                $this->fmtHours((float)($r->worked_hours ?? 0)),   // M
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = ['TOTALS', ...array_fill(0, self::TOTAL_COLS - 2, ''), "=SUM(M{$ds}:M{$de})"];

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last    = 3 + count($this->records);
        $total   = $last + 1;
        $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('1B5E20'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }
        }

        $sheet->mergeCells("A{$total}:L{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("M{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
        $sheet->getStyle("M{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");

        return [];
    }
}
