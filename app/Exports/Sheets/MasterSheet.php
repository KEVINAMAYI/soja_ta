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
 * K  Meal Time Out
 * L  Meal Time In
 * M  Total Break
 * N  Defined Time Out
 * O  Actual Time Out
 * P  Defined Hours
 * Q  Total Hours Worked
 * R  OT 1
 * S  OT 2
 * T  Absent / Lost Hours
 * U  Exceptions
 * V  Interpretation
 */
class MasterSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL = 'V';
    private const TOTAL_COLS = 22;

    private array $shiftCache = [];

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    )
    {
        $shiftIds = collect($records)->pluck('shift_id')->filter()->unique()->values()->toArray();
        if (!empty($shiftIds)) {
            $this->shiftCache = \App\Models\Shift::whereIn('id', $shiftIds)->get()->keyBy('id')->all();
        }
    }

    public function title(): string
    {
        return 'Master';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16, 'D' => 24,
            'E' => 18, 'F' => 14, 'G' => 18, 'H' => 10,
            'I' => 11, 'J' => 11, 'K' => 11, 'L' => 11,
            'M' => 11, 'N' => 11, 'O' => 11, 'P' => 10,
            'Q' => 11, 'R' => 9,  'S' => 9,  'T' => 11,
            'U' => 26, 'V' => 26,
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
            "Meal Time\nOut",                   // K
            "Meal Time\nIn",                    // L
            "Total\nBreak",                     // M
            "Defined\nTime Out",                // N
            "Actual\nTime Out",                 // O
            "Defined\nHours",                   // P
            "Total Hours\nWorked",              // Q
            "OT 1",                             // R
            "OT 2",                             // S
            "Absent /\nLost Hours",             // T
            "Exceptions\n(Gate Passes, Leave)", // U
            "Interpretation",                   // V
        ];

        foreach ($this->records as $r) {
            $emp = $r->employee ?? null;
            $shift = $this->resolveShiftObject($r, $this->shiftCache);

            $firstBreak = $r->relationLoaded('breakLogs')
                ? $r->breakLogs->where('type', 'break')->sortBy('break_start_time')->first()
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
                $firstBreak ? $this->fmtTime($firstBreak->break_end_time) : '',   // L
                $this->totalBreakTime($r),                          // M
                $definedOut,                                        // N
                $this->fmtTime($r->check_out_time),                // O
                (function() use ($r, $shift) {
                    $dow     = \Carbon\Carbon::parse($r->date)->format('l');
                    $isNight = ($shift?->shift_type ?? '') === 'night';
                    return match(true) {
                        in_array($dow, ['Saturday', 'Sunday']) => '8.0',
                        $dow === 'Friday' && $isNight          => '11.0',
                        $dow === 'Friday'                      => '8.0',
                        $isNight                               => '11.0',
                        default                                => '9.0',
                    };
                })(),                                               // P
                $this->fmtHours((float)($r->worked_hours ?? 0)),   // Q
                $this->fmtHours((float)($r->ot1_hours ?? 0)),      // R
                $this->fmtHours((float)($r->ot2_hours ?? 0)),      // S
                $this->lostHours($r),                              // T
                '',                                                 // U
                $r->interpretation ?? '',                           // V
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = [
            'TOTALS',
            '', '', '', '', '', '', '', '', '', '', '', '', '',  // B–O
            "=SUM(P{$ds}:P{$de})",  // P
            "=SUM(Q{$ds}:Q{$de})",  // Q
            "=SUM(R{$ds}:R{$de})",  // R
            "=SUM(S{$ds}:S{$de})",  // S
            "=SUM(T{$ds}:T{$de})",  // T
            '', '',                  // U V
        ];

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastDataRow = 3 + count($this->records);
        $totalRow = $lastDataRow + 1;
        $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1F3864']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBF3FB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '999999']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(40);
        foreach ([
                     'A3:A3' => '2E75B6',
                     'B3:B3' => 'C0341B',
                     'C3:F3' => '2E75B6',
                     'G3:H3' => '375623',
                     'I3:O3' => '1F6B3A',
                     'P3:P3' => '7F3F98',
                     'Q3:Q3' => '4472C4',
                     'R3:S3' => 'C55A11',
                     'T3:T3' => 'C00000',
                     'U3:U3' => '833C00',
                     'V3:V3' => '404040',
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
            $sheet->getStyle("U{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $empType = (string)$sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $interpVal = (string)$sheet->getCell("V{$row}")->getValue();
            if ($interpVal) {
                [$bg, $fg] = $this->interpretationBadge($interpVal);
                $sheet->getStyle("V{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $lostVal = $sheet->getCell("T{$row}")->getValue();
            if (is_numeric($lostVal) && (float)$lostVal > 0) {
                $sheet->getStyle("T{$row}")->getFont()->setBold(true)->getColor()->setRGB('C00000');
            }
        }

        $sheet->mergeCells("A{$totalRow}:O{$totalRow}");
        $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['P', 'Q', 'R', 'S', 'T'] as $col) {
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
