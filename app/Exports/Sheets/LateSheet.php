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
 * Sheet 3 — Late Report
 *
 * A  Date
 * B  Employee Type
 * C  Employee Number
 * D  Name
 * E  Department
 * F  Section
 * G  Actual Time In
 * H  Actual Time Out
 * I  Expected Hours (9h)
 * J  Lateness (HH:MM)
 */
class LateSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'J';
    private const TOTAL_COLS = 10;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Late Report'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16,
            'D' => 24, 'E' => 18, 'F' => 14,
            'G' => 11, 'H' => 11, 'I' => 16, 'J' => 13,
        ];
    }

    public function array(): array
    {
        $out = [];

        $out[] = ['LATENESS REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [
            'Date',                          // A
            'Employee Type',                 // B
            'Employee Number',               // C
            'Name',                          // D
            'Department',                    // E
            'Section',                       // F
            "Actual\nTime In",               // G
            "Actual\nTime Out",              // H
            "Expected Hours\n(Defined: 9h)", // I
            "Lateness\n(HH:MM)",             // J
        ];

        foreach ($this->records as $r) {
            $emp = $r->employee ?? null;
            $out[] = [
                $this->fmtDate($r->date),                       // A
                $emp?->employee_type ?? '',                      // B
                $emp?->ad_employee_id ?? $emp?->id ?? '',        // C
                $emp?->name ?? '',                               // D
                $emp?->department?->name ?? '',                  // E
                $emp?->section ?? '',                            // F
                $this->fmtTime($r->check_in_time),              // G
                $this->fmtTime($r->check_out_time),             // H
                '9:00',                                          // I
                $r->minutes_late > 0
                    ? $this->minutesToHHMM((int) $r->minutes_late)
                    : '',                                        // J
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = [
            'TOTAL LATE: ' . count($this->records),
            ...array_fill(0, self::TOTAL_COLS - 2, ''),
            "=COUNTA(J{$ds}:J{$de})",
        ];

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last    = 3 + count($this->records);
        $total   = $last + 1;
        $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '856404']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF8E1']],
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
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('856404'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDE7');
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            // Lateness bold amber — col J (last)
            $sheet->getStyle("J{$row}")->getFont()->setBold(true)->getColor()->setRGB('856404');
        }

        $sheet->mergeCells("A{$total}:I{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '856404']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("J{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
        $sheet->getStyle("J{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");

        return [];
    }

    private function minutesToHHMM(int $minutes): string
    {
        $h = (int) floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }
}
