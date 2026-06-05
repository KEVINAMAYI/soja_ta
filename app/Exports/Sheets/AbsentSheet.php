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
 * Sheet 4 — Absent Report
 *
 * A  Date
 * B  Employee Type
 * C  Employee Number
 * D  Name
 * E  Department
 * F  Section
 * G  Reason / Exception
 */
class AbsentSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'G';
    private const TOTAL_COLS = 7;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Absent Report'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16,
            'D' => 24, 'E' => 18, 'F' => 14,
            'G' => 34,
        ];
    }

    public function array(): array
    {
        $out = [];

        $out[] = ['ABSENT REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [
            'Date',               // A
            'Employee Type',      // B
            'Employee Number',    // C
            'Name',               // D
            'Department',         // E
            'Section',            // F
            'Reason / Exception', // G
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
                $this->absentReason($r),                         // G
            ];
        }

        $out[] = ['TOTAL ABSENCES: ' . count($this->records), '', '', '', '', '', ''];

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last    = 3 + count($this->records);
        $total   = $last + 1;
        $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => 'C00000']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDEDED']],
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
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('C00000'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Employee Type badge — col B
            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            // Reason badge — col G (last column)
            $reason = strtolower((string) $sheet->getCell("G{$row}")->getValue());
            if (str_contains($reason, 'leave') || str_contains($reason, 'sick')) {
                $sheet->getStyle("G{$row}")->applyFromArray($this->badgeStyle('CCE5FF', '004085'));
            } elseif (str_contains($reason, 'gate') || str_contains($reason, 'pass')) {
                $sheet->getStyle("G{$row}")->applyFromArray($this->badgeStyle('E2D9F3', '432E75'));
            } else {
                $sheet->getStyle("G{$row}")->applyFromArray($this->badgeStyle('F8D7DA', '721C24'));
            }
        }

        $sheet->mergeCells("A{$total}:{$lastCol}{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C00000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");

        return [];
    }

    private function absentReason($r): string
    {
        return match ($r->status ?? '') {
            'on_leave'   => 'Annual Leave – Approved',
            'sick_leave' => 'Sick Leave – Approved',
            'sick_off'   => 'Sick Day – Approved',
            'gate_pass'  => 'Gate Pass – ' . ($r->exception_note ?? 'Approved'),
            default      => 'Absent – No Reason',
        };
    }
}
