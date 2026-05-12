<?php

/**
 * ============================================================
 * T&A REPORT – EXCEL SHEET CLASSES
 * ============================================================
 * Place this file at: app/Exports/Sheets/TaReportSheets.php
 *
 * Or split each class into its own file under app/Exports/Sheets/
 * The namespace below matches either approach.
 * ============================================================
 */

namespace App\Exports\Sheets;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

// ─────────────────────────────────────────────────────────────────────────────
// Shared helpers trait
// ─────────────────────────────────────────────────────────────────────────────
trait TaSheetHelpers
{
    private function hdrStyle(string $hexFill): array
    {
        return [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9, 'name' => 'Arial'],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $hexFill]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function dataStyle(string $align = 'center', bool $bold = false): array
    {
        return [
            'font'      => ['name' => 'Arial', 'size' => 9, 'bold' => $bold],
            'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function badgeStyle(string $bgHex, string $fgHex): array
    {
        return [
            'font'      => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $fgHex]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgHex]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function interpretationBadge(string $interp): array
    {
        return match ($interp) {
            'Attendance OK'                    => ['D4EDDA', '155724'],
            'Late In'                          => ['FFF3CD', '856404'],
            'Late In & Late Out'               => ['F8D7DA', '721C24'],
            'Early Out'                        => ['FFF3CD', '856404'],
            'Extended Lunch'                   => ['D1ECF1', '0C5460'],
            'Absent'                           => ['F8D7DA', '721C24'],
            'Absent with Approved Leave'       => ['CCE5FF', '004085'],
            'Absent with Approved Gate Pass'   => ['E2D9F3', '432E75'],
            default                            => ['F8D7DA', '721C24'],
        };
    }

    private function fmtTime(?string $t): string
    {
        if (!$t) return '';
        try { return Carbon::parse($t)->format('H:i'); } catch (\Throwable) { return ''; }
    }

    private function fmtDate(?string $d): string
    {
        if (!$d) return '';
        try { return Carbon::parse($d)->format('d/m/Y'); } catch (\Throwable) { return ''; }
    }

    private function fmtHours(float $h): string
    {
        return $h > 0 ? number_format($h, 1) : '0.0';
    }

    private function periodLabel(?string $start, ?string $end): string
    {
        $s = $start ? Carbon::parse($start)->format('d M Y') : '—';
        $e = $end   ? Carbon::parse($end)->format('d M Y')   : $s;
        return "Period: {$s} – {$e}  |  Generated: " . now()->format('d M Y H:i');
    }

    /** Apply thin border to a range */
    private function applyBorder(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->setColor(new Color('CCCCCC'));
    }
}

// =============================================================================
// SHEET 1 – MASTER
// =============================================================================
class MasterSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private array $rows = [];

    public function __construct(
        private readonly array  $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Master'; }

    public function columnWidths(): array
    {
        return [
            'A'=>12,'B'=>14,'C'=>22,'D'=>16,'E'=>14,
            'F'=>18,'G'=>11,'H'=>11,'I'=>11,'J'=>11,
            'K'=>11,'L'=>11,'M'=>11,'N'=>10,'O'=>10,
            'P'=>8, 'Q'=>8, 'R'=>11,'S'=>22,'T'=>24,
        ];
    }

    public function array(): array
    {
        $out = [];
        // Row 1: title (merged in styles)
        $out[] = ['MASTER ATTENDANCE REPORT', ...array_fill(0, 19, '')];
        // Row 2: period sub-title
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 19, '')];
        // Row 3: column headers
        $out[] = [
            'Date','Employee Number','Full Names','Department','Section',
            "Staff Category\n(General / Admin Shift)","Shift\n(Day / Night)",
            "Defined\nTime In","Actual\nTime In","Start of\nLunch","End of\nLunch Break",
            "Defined\nTime Out","Actual\nTime Out","Defined\nHours",
            "Total Hours\nWorked","OT 1","OT 2","Absent /\nLost Hours",
            "Exceptions\n(Gatepasses, Leave Applications)","Interpretation\nColumn",
        ];
        // Data rows
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_number ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',   // map to your employee.section field name
                $this->staffCategory($shift),
                $this->shiftDayNight($shift),
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '',
                $this->fmtTime($r->check_in_time),
                $this->fmtTime($r->break_start_time ?? null),  // adjust field name as needed
                $this->fmtTime($r->break_end_time   ?? null),
                $shift ? Carbon::parse($shift->end_time)->format('H:i') : '',
                $this->fmtTime($r->check_out_time),
                $shift ? $this->shiftDefinedHours($shift) : '',
                $this->fmtHours((float) ($r->worked_hours ?? 0)),
                $this->fmtHours((float) ($r->overtime_hours ?? 0)),
                '',  // OT2 – extend if you track two tiers
                $this->lostHours($r),
                $r->exception_note ?? '',   // add this field or derive from status
                $r->interpretation ?? '',
            ];
        }
        // Totals row
        $dataStart = 4; $dataEnd = 3 + count($this->records);
        $out[] = [
            'TOTALS', '','','','','','','','','','','','',
            "=SUM(N{$dataStart}:N{$dataEnd})",
            "=SUM(O{$dataStart}:O{$dataEnd})",
            "=SUM(P{$dataStart}:P{$dataEnd})",
            "=SUM(Q{$dataStart}:Q{$dataEnd})",
            "=SUM(R{$dataStart}:R{$dataEnd})",
            '','',
        ];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastDataRow = 3 + count($this->records);
        $totalRow    = $lastDataRow + 1;

        // Row 1: title banner
        $sheet->mergeCells("A1:T1");
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1F3864']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBF3FB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '999999']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // Row 2: period label
        $sheet->mergeCells("A2:T2");
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        // Row 3: column headers with section color groups
        $sheet->getRowDimension(3)->setRowHeight(36);
        $groups = [
            'A3:E3' => '2E75B6',  // identity – blue
            'F3:M3' => '375623',  // shift/time – green
            'N3:R3' => '7F3F98',  // hours – purple
            'S3:S3' => '833C00',  // exceptions – brown
            'T3:T3' => 'C00000',  // interpretation – red
        ];
        foreach ($groups as $range => $color) {
            $sheet->getStyle($range)->applyFromArray($this->hdrStyle($color));
        }

        // Data rows
        for ($row = 4; $row <= $lastDataRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            // Zebra stripe
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:S{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("A{$row}:S{$row}")->applyFromArray($this->dataStyle('center'));
            // Left-align text cols
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("S{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Interpretation badge (col T)
            $interpVal = $sheet->getCell("T{$row}")->getValue();
            if ($interpVal) {
                [$bg, $fg] = $this->interpretationBadge($interpVal);
                $sheet->getStyle("T{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            // Red text for late minutes (col I) – highlight if actual later than defined
            // (PhpSpreadsheet cannot evaluate formulas here; colour set by PHP logic above if needed)
        }

        // Totals row
        $sheet->mergeCells("A{$totalRow}:M{$totalRow}");
        $sheet->getStyle("A{$totalRow}:T{$totalRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("N{$totalRow}:R{$totalRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
        $sheet->getStyle("N{$totalRow}:R{$totalRow}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($totalRow)->setRowHeight(18);

        // Freeze panes & autofilter
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:T3");

        return [];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function staffCategory($shift): string
    {
        if (!$shift) return '';
        // Adjust logic to your shift model fields
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
        if (!$shift || !empty($r->check_in_time)) {
            // If absent return defined hours; if present return 0
            if (in_array($r->status ?? '', ['absent', 'unchecked_in'])) {
                return $this->shiftDefinedHours($shift);
            }
            return '0.0';
        }
        return '0.0';
    }
}

// =============================================================================
// SHEET 2 – PRESENT
// =============================================================================
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
        return ['A'=>12,'B'=>14,'C'=>22,'D'=>16,'E'=>14,'F'=>18,'G'=>11,'H'=>11,'I'=>11,'J'=>11,'K'=>11,'L'=>13];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['PRESENT REPORT', ...array_fill(0, 11, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 11, '')];
        $out[] = [
            'Date','Employee Number','Name','Department','Section',
            "Staff Category\n(General / Admin Shift)","Shift\n(Day / Night)",
            "Defined\nTime In","Actual\nTime In","Defined\nTime Out","Actual\nTime Out","Total Hours\nWorked",
        ];
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_number ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->staffCategory($shift),
                $this->shiftDayNight($shift),
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '',
                $this->fmtTime($r->check_in_time),
                $shift ? Carbon::parse($shift->end_time)->format('H:i') : '',
                $this->fmtTime($r->check_out_time),
                $this->fmtHours((float) ($r->worked_hours ?? 0)),
            ];
        }
        $ds = 4; $de = 3 + count($this->records);
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
                $sheet->getStyle("A{$row}:L{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("A{$row}:L{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $sheet->mergeCells("A{$total}:K{$total}");
        $sheet->getStyle("A{$total}:L{$total}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
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

// =============================================================================
// SHEET 3 – LATE REPORT
// =============================================================================
class LateSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Late Report'; }

    public function columnWidths(): array
    {
        return ['A'=>12,'B'=>14,'C'=>22,'D'=>16,'E'=>14,'F'=>11,'G'=>11,'H'=>13,'I'=>13];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['LATENESS REPORT', ...array_fill(0, 8, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 8, '')];
        $out[] = [
            'Date','Employee Number','Name','Department','Section',
            "Actual\nTime In","Actual\nTime Out","Expected Working\nHours","Lateness\nTotal Time",
        ];
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_number ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->fmtTime($r->check_in_time),
                $this->fmtTime($r->check_out_time),
                $shift ? $this->definedHours($shift) : '',
                $r->minutes_late > 0 ? $this->minutesToHHMM($r->minutes_late) : '',
            ];
        }
        $ds = 4; $de = 3 + count($this->records);
        $out[] = ["TOTAL LATE ARRIVALS: " . count($this->records), ...array_fill(0, 7, ''), "=COUNTA(I{$ds}:I{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last  = 3 + count($this->records);
        $total = $last + 1;

        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '856404']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF8E1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle('A3:I3')->applyFromArray($this->hdrStyle('856404'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:I{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDE7');
            }
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("C{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            // Highlight lateness time in amber bold
            $sheet->getStyle("I{$row}")->getFont()->setBold(true)->getColor()->setRGB('856404');
        }

        $sheet->mergeCells("A{$total}:H{$total}");
        $sheet->getStyle("A{$total}:I{$total}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '856404']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("I{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
        $sheet->getStyle("I{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter('A3:I3');
        return [];
    }

    private function definedHours($shift): string
    {
        $mins = Carbon::parse($shift->start_time)->diffInMinutes(Carbon::parse($shift->end_time));
        return number_format($mins / 60, 1);
    }

    private function minutesToHHMM(int $minutes): string
    {
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }
}

// =============================================================================
// SHEET 4 – ABSENT REPORT
// =============================================================================
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
        return ['A'=>12,'B'=>14,'C'=>22,'D'=>16,'E'=>14,'F'=>30];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['ABSENT REPORT', ...array_fill(0, 5, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 5, '')];
        $out[] = ['Date','Employee Number','Name','Department','Section','Reason'];

        foreach ($this->records as $r) {
            $emp = $r->employee ?? null;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_number ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->absentReason($r),
            ];
        }
        $ds = 4; $de = 3 + count($this->records);
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

        // Reason badge colours
        $reasonBadges = [
            'absent'   => ['F8D7DA', '721C24'],
            'leave'    => ['CCE5FF', '004085'],
            'gate'     => ['E2D9F3', '432E75'],
        ];

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
            }
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Badge the Reason column
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
            'font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C00000']],
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
        // Map your status field to a human reason
        return match ($r->status ?? '') {
            'on_leave'   => 'Annual Leave – Approved',
            'sick_leave' => 'Sick Leave – Approved',
            'sick_off'   => 'Sick Day – Approved',
            'gate_pass'  => 'Gate Pass – ' . ($r->exception_note ?? 'Approved'),
            default      => 'Absent – No Reason',
        };
    }
}
