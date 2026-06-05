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

class AbsentSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Absent Report'; }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 14, 'C' => 22, 'D' => 16, 'E' => 14, 'F' => 30];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['ABSENT REPORT', ...array_fill(0, 5, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 5, '')];
        $out[] = ['Date', 'Employee Title', 'Employee Number', 'Name', 'Department', 'Section', 'Reason'];

        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_title ?? '',
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->absentReason($r),
            ];
        }
        $ds    = 4;
        $de    = 3 + count($this->records);
        $out[] = ["TOTAL ABSENCES: " . count($this->records), '', '', '', '', "=COUNTA(F{$ds}:F{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last  = 3 + count($this->records);
        $total = $last + 1;

        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => 'C00000']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDEDED']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle('A3:F3')->applyFromArray($this->hdrStyle('C00000'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
            }
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $reason = strtolower((string) $sheet->getCell("F{$row}")->getValue());
            if (str_contains($reason, 'leave')) {
                $sheet->getStyle("F{$row}")->applyFromArray($this->badgeStyle('CCE5FF', '004085'));
            } elseif (str_contains($reason, 'gate') || str_contains($reason, 'pass')) {
                $sheet->getStyle("F{$row}")->applyFromArray($this->badgeStyle('E2D9F3', '432E75'));
            } else {
                $sheet->getStyle("F{$row}")->applyFromArray($this->badgeStyle('F8D7DA', '721C24'));
            }
        }

        $sheet->mergeCells("A{$total}:E{$total}");
        $sheet->getStyle("A{$total}:F{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C00000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("F{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
        $sheet->getStyle("F{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter('A3:F3');
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
