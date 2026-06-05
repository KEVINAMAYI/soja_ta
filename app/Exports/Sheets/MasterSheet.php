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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

class MasterSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Master'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 14, 'C' => 14, 'D' => 22, 'E' => 16, 'F' => 14,
            'G' => 18, 'H' => 11, 'I' => 11, 'J' => 11, 'K' => 11,
            'L' => 11, 'M' => 11, 'N' => 11, 'O' => 10, 'P' => 10,
            'Q' => 8,  'R' => 8,  'S' => 11, 'T' => 22, 'U' => 24,
        ];
    }

    public function array(): array
    {
        $out = [];
        $out[] = ['MASTER ATTENDANCE REPORT', ...array_fill(0, 20, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 20, '')];
        $out[] = [
            'Date', 'Employee Type', 'Employee Title', 'Employee Number', 'Full Names', 'Department', 'Section',
            "Staff Category\n(General / Admin Shift)", "Shift\n(Day / Night)",
            "Defined\nTime In", "Actual\nTime In", "Start of\nLunch", "End of\nLunch Break",
            "Defined\nTime Out", "Actual\nTime Out", "Defined\nHours",
            "Total Hours\nWorked", "OT 1", "OT 2", "Absent /\nLost Hours",
            "Exceptions\n(Gatepasses, Leave Applications)", "Interpretation\nColumn",
        ];
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_type ?? '',        // ← B
                $emp?->employee_title ?? '',       // ← C
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->staffCategory($shift),
                $this->shiftDayNight($shift),
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '',
                $this->fmtTime($r->check_in_time),
                $this->fmtTime($r->break_start_time ?? null),
                $this->fmtTime($r->break_end_time ?? null),
                $shift ? Carbon::parse($shift->end_time)->format('H:i') : '',
                $this->fmtTime($r->check_out_time),
                $shift ? $this->shiftDefinedHours($shift) : '',
                $this->fmtHours((float)($r->worked_hours ?? 0)),
                $this->fmtHours((float)($r->overtime_hours ?? 0)),
                '',
                $this->lostHours($r),
                $r->exception_note ?? '',
                $r->interpretation ?? '',
            ];
        }
        $ds  = 4;
        $de  = 3 + count($this->records);
        $out[] = [
            'TOTALS', '', '', '', '', '', '', '', '', '', '', '', '', '',
            "=SUM(O{$ds}:O{$de})", "=SUM(P{$ds}:P{$de})",
            "=SUM(Q{$ds}:Q{$de})", "=SUM(R{$ds}:R{$de})",
            "=SUM(S{$ds}:S{$de})", '', '',
        ];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastDataRow = 3 + count($this->records);
        $totalRow    = $lastDataRow + 1;

        $sheet->mergeCells("A1:V1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1F3864']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBF3FB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '999999']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:V2");
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        foreach ([
                     'A3:F3' => '2E75B6',  // identity (now includes employee type + title)
                     'G3:N3' => '375623',  // shift/time
                     'O3:S3' => '7F3F98',  // hours
                     'T3:T3' => '833C00',  // exceptions
                     'U3:U3' => 'C00000',  // interpretation
                 ] as $range => $color) {
            $sheet->getStyle($range)->applyFromArray($this->hdrStyle($color));
        }

        for ($row = 4; $row <= $lastDataRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:T{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("A{$row}:T{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("T{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Employee type badge (col B)
            $empType = $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = match ($empType) {
                    'COSMOS'     => ['E0F2FE', '0369A1'],
                    'Outsourced' => ['FDE8E3', 'C0341B'],
                    default      => ['F1F5F9', '475569'],
                };
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $interpVal = $sheet->getCell("U{$row}")->getValue();
            if ($interpVal) {
                [$bg, $fg] = $this->interpretationBadge($interpVal);
                $sheet->getStyle("U{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }
        }

        $sheet->mergeCells("A{$totalRow}:N{$totalRow}");
        $sheet->getStyle("A{$totalRow}:U{$totalRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("O{$totalRow}:S{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
        $sheet->getStyle("O{$totalRow}:S{$totalRow}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($totalRow)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:U3");
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

    private function shiftDefinedHours($shift): string
    {
        if (!$shift) return '';
        $mins = Carbon::parse($shift->start_time)->diffInMinutes(Carbon::parse($shift->end_time));
        return number_format($mins / 60, 1);
    }

    private function lostHours($r): string
    {
        $shift = $r->employee?->shift;
        if (in_array($r->status ?? '', ['absent', 'unchecked_in'])) {
            return $this->shiftDefinedHours($shift);
        }
        return '0.0';
    }
}
