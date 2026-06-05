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

/**
 * Sheet 1 — Master
 *
 * A  Date
 * B  Employee Type
 * C  Employee Number
 * D  Full Names
 * E  Department
 * F  Section
 * G  Staff Category
 * H  Shift (Day/Night)
 * I  Defined Time In
 * J  Actual Time In
 * K  Start of Lunch
 * L  End of Lunch
 * M  Defined Time Out
 * N  Actual Time Out
 * O  Defined Hours
 * P  Total Hours Worked
 * Q  OT 1
 * R  OT 2
 * S  Absent / Lost Hours
 * T  Exceptions
 * U  Interpretation
 */
class MasterSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'U';
    private const TOTAL_COLS = 21;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Master'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16,
            'D' => 24, 'E' => 18, 'F' => 14,
            'G' => 18, 'H' => 10, 'I' => 11,
            'J' => 11, 'K' => 11, 'L' => 11,
            'M' => 11, 'N' => 11, 'O' => 10,
            'P' => 11, 'Q' => 9,  'R' => 9,
            'S' => 11, 'T' => 26, 'U' => 26,
        ];
    }

    public function array(): array
    {
        $out = [];

        $out[] = ['MASTER ATTENDANCE REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];

        $out[] = [
            'Date',                             // A
            'Employee Type',                    // B
            'Employee Number',                  // C
            'Full Names',                       // D
            'Department',                       // E
            'Section',                          // F
            'Staff Category',                   // G
            "Shift\n(Day/Night)",               // H
            "Defined\nTime In",                 // I
            "Actual\nTime In",                  // J
            "Start of\nLunch",                  // K
            "End of\nLunch",                    // L
            "Defined\nTime Out",                // M
            "Actual\nTime Out",                 // N
            "Defined\nHours",                   // O
            "Total Hours\nWorked",              // P
            "OT 1",                             // Q
            "OT 2",                             // R
            "Absent /\nLost Hours",             // S
            "Exceptions\n(Gate Passes, Leave)", // T
            "Interpretation",                   // U
        ];

        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;

            $firstBreak = method_exists($r, 'breakLogs')
                ? $r->breakLogs()->where('type', 'break')->orderBy('break_start_time')->first()
                : null;

            $definedOut = '';
            if ($shift) {
                try {
                    $definedOut = $shift->getEffectiveEndTime($r->date ?? now()->toDateString())->format('H:i');
                } catch (\Throwable) {
                    $definedOut = Carbon::parse($shift->end_time)->format('H:i');
                }
            }

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
                $firstBreak ? $this->fmtTime($firstBreak->break_start_time) : '', // K
                $firstBreak ? $this->fmtTime($firstBreak->break_end_time)   : '', // L
                $definedOut,                                        // M
                $this->fmtTime($r->check_out_time),                // N
                '9.0',                                              // O
                $this->fmtHours((float)($r->worked_hours ?? 0)),   // P
                $this->fmtHours((float)($r->ot1_hours ?? 0)),      // Q
                $this->fmtHours((float)($r->ot2_hours ?? 0)),      // R
                $this->lostHours($r),                              // S
                $r->exception_note ?? '',                           // T
                $r->interpretation ?? '',                           // U
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = [
            'TOTALS',
            '', '', '', '', '', '', '', '', '', '', '', '',  // B–M
            "=SUM(O{$ds}:O{$de})",  // O
            "=SUM(P{$ds}:P{$de})",  // P
            "=SUM(Q{$ds}:Q{$de})",  // Q
            "=SUM(R{$ds}:R{$de})",  // R
            "=SUM(S{$ds}:S{$de})",  // S
            '', '',                  // T U
        ];

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastDataRow = 3 + count($this->records);
        $totalRow    = $lastDataRow + 1;
        $lastCol     = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1F3864']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBF3FB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '999999']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(40);
        foreach ([
                     'A3:A3' => '2E75B6',
                     'B3:B3' => 'C0341B',
                     'C3:F3' => '2E75B6',
                     'G3:H3' => '375623',
                     'I3:N3' => '1F6B3A',
                     'O3:O3' => '7F3F98',
                     'P3:P3' => '4472C4',
                     'Q3:R3' => 'C55A11',
                     'S3:S3' => 'C00000',
                     'T3:T3' => '833C00',
                     'U3:U3' => '404040',
                 ] as $range => $color) {
            $sheet->getStyle($range)->applyFromArray($this->hdrStyle($color));
        }

        for ($row = 4; $row <= $lastDataRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("T{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $interpVal = (string) $sheet->getCell("U{$row}")->getValue();
            if ($interpVal) {
                [$bg, $fg] = $this->interpretationBadge($interpVal);
                $sheet->getStyle("U{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $lostVal = $sheet->getCell("S{$row}")->getValue();
            if (is_numeric($lostVal) && (float) $lostVal > 0) {
                $sheet->getStyle("S{$row}")->getFont()->setBold(true)->getColor()->setRGB('C00000');
            }
        }

        $sheet->mergeCells("A{$totalRow}:N{$totalRow}");
        $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['O', 'P', 'Q', 'R', 'S'] as $col) {
            $sheet->getStyle("{$col}{$totalRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
            $sheet->getStyle("{$col}{$totalRow}")->getFont()
                ->setColor(new Color('000000'))->setBold(true);
        }
        $sheet->getRowDimension($totalRow)->setRowHeight(20);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");

        return [];
    }
}
