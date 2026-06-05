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

class PresentSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Present'; }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 14, 'C' => 22, 'D' => 16, 'E' => 14, 'F' => 18, 'G' => 11, 'H' => 11, 'I' => 11, 'J' => 11, 'K' => 11, 'L' => 13];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['PRESENT REPORT', ...array_fill(0, 11, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 11, '')];
        $out[] = [
            'Date', 'Employee Title', 'Employee Number', 'Name', 'Department', 'Section',
            "Staff Category\n(General / Admin Shift)", "Shift\n(Day / Night)",
            "Defined\nTime In", "Actual\nTime In", "Defined\nTime Out", "Actual\nTime Out", "Total Hours\nWorked",
        ];
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_title ?? '',
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->staffCategory($shift),
                $this->shiftDayNight($shift),
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '',
                $this->fmtTime($r->check_in_time),
                $shift ? Carbon::parse($shift->end_time)->format('H:i') : '',
                $this->fmtTime($r->check_out_time),
                $this->fmtHours((float)($r->worked_hours ?? 0)),
            ];
        }
        $ds    = 4;
        $de    = 3 + count($this->records);
        $out[] = ['TOTALS', ...array_fill(0, 10, ''), "=SUM(L{$ds}:L{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last  = 3 + count($this->records);
        $total = $last + 1;

        $sheet->mergeCells("A1:L1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1B5E20']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:L2");
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle('A3:L3')->applyFromArray($this->hdrStyle('1B5E20'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:L{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("A{$row}:L{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $sheet->mergeCells("A{$total}:K{$total}");
        $sheet->getStyle("A{$total}:L{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("L{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
        $sheet->getStyle("L{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter('A3:L3');
        return [];
    }

    private function staffCategory($shift): string
    {
        if (!$shift) return '';
        return str_contains(strtolower($shift->name ?? ''), 'admin') ? 'Admin Shift' : 'General Shift';
    }

    private function shiftDayNight($shift): string
    {
        if (!$shift) return '';
        $start = (int) Carbon::parse($shift->start_time)->format('H');
        return ($start >= 6 && $start < 18) ? 'Day' : 'Night';
    }
}
