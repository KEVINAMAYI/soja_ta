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
use App\Models\Shift;

// =============================================================================
// SHARED HELPERS TRAIT
// =============================================================================
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

    private function employeeTypeBadge(string $type): array
    {
        return match ($type) {
            'COSMOS'     => ['E0F2FE', '0369A1'],
            'Outsourced' => ['FDE8E3', 'C0341B'],
            default      => ['F1F5F9', '475569'],
        };
    }

    private function interpretationBadge(string $interp): array
    {
        // Missed punch — amber for day, purple for night
        if (str_contains($interp, 'Missed Clock-In') && str_contains($interp, 'Night')) {
            return ['EDE7F6', '4527A0'];
        }
        if (str_contains($interp, 'Missed Clock-Out') && str_contains($interp, 'Night')) {
            return ['EDE7F6', '4527A0'];
        }
        if (str_contains($interp, 'Missed Clock-In')) {
            return ['FFF3CD', '856404'];
        }
        if (str_contains($interp, 'Missed Clock-Out')) {
            return ['FFE0B2', 'BF360C'];
        }

        return match ($interp) {
            'Attendance OK'                  => ['D4EDDA', '155724'],
            'Still In'                       => ['DCFCE7', '166534'],
            'Late In'                        => ['FFF3CD', '856404'],
            'Late In & Late Out'             => ['F8D7DA', '721C24'],
            'Early Out'                      => ['FFF3CD', '856404'],
            'Extended Lunch'                 => ['D1ECF1', '0C5460'],
            'Absent'                         => ['F8D7DA', '721C24'],
            'Absent with Approved Leave'     => ['CCE5FF', '004085'],
            'Absent with Approved Gate Pass' => ['E2D9F3', '432E75'],
            'Overtime 1'                     => ['FFE0B2', 'BF360C'],
            'Overtime 2'                     => ['EDE7F6', '4527A0'],
            'Weekend — No OT'                => ['F1F5F9', '475569'],
            default                          => ['F8D7DA', '721C24'],
        };
    }

    private function fmtTime($t): string
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

    protected function staffCategory($shift): string
    {
        if (!$shift) return '';
        return match ($shift->department_type ?? '') {
            'admin'       => 'Admin',
            'general'     => 'General',
            'engineering' => 'Engineering',
            default       => ucfirst($shift->department_type ?? '') ?: 'General',
        };
    }

    protected function shiftDayNight($shift): string
    {
        if (!$shift) return '';
        return match ($shift->shift_type ?? '') {
            'night'                    => 'Night',
            'day', 'admin', 'extended' => 'Day',
            default => (int) Carbon::parse($shift->start_time)->format('H') >= 17 ? 'Night' : 'Day',
        };
    }

    /**
     * Resolve the correct Shift model for an attendance record.
     *
     * Priority:
     *  1. attendance.shift_id → shiftCache lookup
     *  2. If that shift is a DAY shift but the punch is in the night range,
     *     look for the employee's NIGHT shift instead — the shift_id may have
     *     been saved incorrectly during sync.
     *  3. Fall back to employee's primary/first shift.
     */
    protected function resolveShiftObject($r, array $shiftCache = []): ?object
    {
        $punchTime = $r->check_in_time ?? $r->check_out_time;
        $isPunchNight = false;
        if ($punchTime) {
            $hour = (int) Carbon::parse($punchTime)->format('H');
            $isPunchNight = ($hour < 6 || $hour >= 17); // ← was 16, now 17
        }

        $shiftId = $r->shift_id ?? null;

        // 1. Cache lookup
        if ($shiftId && isset($shiftCache[$shiftId])) {
            $shift = $shiftCache[$shiftId];
            $shiftIsNight = ($shift->shift_type ?? '') === 'night';
            if ($isPunchNight === $shiftIsNight) return $shift;
        }

        // 1b. Eager-loaded relation fallback for cache miss
        if ($shiftId && ($r->shift ?? null)) {
            $shiftIsNight = ($r->shift->shift_type ?? '') === 'night';
            if ($isPunchNight === $shiftIsNight) return $r->shift;
        }

        // 2. Punch type doesn't match shift_id — look at employee's assigned shifts
        $employee = $r->employee ?? null;
        if ($employee) {
            if ($isPunchNight) {
                $nightShift = $employee->shifts?->firstWhere('shift_type', 'night');
                if ($nightShift) return $nightShift;
            } else {
                $dayShift = $employee->shifts?->first(
                    fn($s) => in_array($s->shift_type ?? '', ['day', 'admin', 'extended'])
                );
                if ($dayShift) return $dayShift;
            }

            // 3. Final fallback — primary or first assigned shift
            return $employee->shifts?->firstWhere('pivot.is_primary', true)
                ?? $employee->shifts?->first()
                ?? $employee->shift
                ?? null;
        }

        return null;
    }

    /**
     * Resolve Day/Night label — uses resolveShiftObject internally.
     */
    protected function resolveShiftDisplay($r, array $shiftCache = []): string
    {
        $shift = $this->resolveShiftObject($r, $shiftCache);
        if (!$shift) {
            // Pure punch-time fallback
            $punchTime = $r->check_in_time ?? $r->check_out_time;
            if ($punchTime) {
                $hour = (int) Carbon::parse($punchTime)->format('H');
                return ($hour < 6 || $hour >= 16) ? 'Night' : 'Day';
            }
            return '';
        }
        return $this->shiftDayNight($shift);
    }

    private function lostHours($r): string
    {
        // Absent = full shift lost
        if (in_array($r->status ?? '', ['absent', 'unchecked_in'])) {
            return ($r->shift?->shift_type === 'night') ? '11.0' : '9.0';
        }

        $lostMinutes = (int) ($r->lost_minutes ?? 0);
        if ($lostMinutes <= 0) return '0.0';
        $h = (int) floor($lostMinutes / 60);
        $m = $lostMinutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    private function minutesToHHMM(int $minutes): string
    {
        $h = (int) floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    /**
     * Exception note for missed punches — shows shift and what is missing.
     */
    private function exceptionNote($r): string
    {
        $note     = $r->exception_note ?? '';
        $scenario = $r->scenario ?? '';

        // If already has a note, return it
        if ($note) return $note;

        // Build from scenario
        if (str_starts_with($scenario, 'missed_clockin')) {
            $time = $r->check_out_time
                ? 'Clock-OUT at ' . Carbon::parse($r->check_out_time)->format('H:i') . ' recorded'
                : '';
            return "Missing Clock-IN punch. {$time}. HR review required.";
        }
        if (str_starts_with($scenario, 'missed_clockout')) {
            $time = $r->check_in_time
                ? 'Clock-IN at ' . Carbon::parse($r->check_in_time)->format('H:i') . ' recorded'
                : '';
            return "Missing Clock-OUT punch. {$time}. HR review required.";
        }
        return '';
    }
}

// =============================================================================
// MASTER SHEET
// A=Date | B=Employee Type | C=Employee Number | D=Full Names | E=Department
// F=Section | G=Staff Category | H=Shift(Day/Night) | I=Defined Time In
// J=Actual Time In | K=Start of Lunch | L=End of Lunch | M=Defined Time Out
// N=Actual Time Out | O=Defined Hours | P=Total Hours Worked
// Q=OT 1 | R=OT 2 | S=Absent/Lost Hours | T=Exceptions | U=Interpretation
// =============================================================================
class MasterSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'U';
    private const TOTAL_COLS = 21;
    private array $shiftCache = [];

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {
        // Pre-load all shifts used in these records to avoid N+1
        $shiftIds = collect($records)->pluck('shift_id')->filter()->unique()->values()->toArray();
        if (!empty($shiftIds)) {
            $this->shiftCache = Shift::whereIn('id', $shiftIds)->get()->keyBy('id')->all();
        }
    }

    public function title(): string { return 'Master'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16, 'D' => 24,
            'E' => 18, 'F' => 14, 'G' => 18, 'H' => 10,
            'I' => 11, 'J' => 11, 'K' => 11, 'L' => 11,
            'M' => 11, 'N' => 11, 'O' => 10, 'P' => 11,
            'Q' => 9,  'R' => 9,  'S' => 11, 'T' => 32,
            'U' => 26,
        ];
    }

    public function array(): array
    {
        $out = [];
        $out[] = ['MASTER ATTENDANCE REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [
            'Date',                              // A
            'Employee Type',                     // B
            'Employee Number',                   // C
            'Full Names',                        // D
            'Department',                        // E
            'Section',                           // F
            'Staff Category',                    // G
            "Shift\n(Day/Night)",                // H
            "Defined\nTime In",                  // I
            "Actual\nTime In",                   // J
            "Start of\nLunch",                   // K
            "End of\nLunch",                     // L
            "Defined\nTime Out",                 // M
            "Actual\nTime Out",                  // N
            "Defined\nHours",                    // O
            "Total Hours\nWorked",               // P
            "OT 1",                              // Q
            "OT 2",                              // R
            "Absent /\nLost Hours",              // S
            "Exceptions / Missed Punch",         // T
            "Interpretation",                    // U
        ];

        foreach ($this->records as $r) {
            $emp = $r->employee ?? null;

            // Use punch-time-aware shift for ALL shift-dependent columns
            $shift = $this->resolveShiftObject($r, $this->shiftCache);

            $firstBreak = $r->relationLoaded('breakLogs')
                ? $r->breakLogs->where('type', 'break')->sortBy('break_start_time')->first()
                : null;

            $definedIn  = $shift ? Carbon::parse($shift->start_time)->format('H:i') : '';
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
                $this->resolveShiftDisplay($r, $this->shiftCache), // H ← punch-time aware
                $definedIn,                                         // I
                $this->fmtTime($r->check_in_time),                 // J
                $firstBreak ? $this->fmtTime($firstBreak->break_start_time) : '', // K
                $firstBreak ? $this->fmtTime($firstBreak->break_end_time)   : '', // L
                $definedOut,                                        // M
                $this->fmtTime($r->check_out_time),                // N
                $shift ? ($shift->shift_type === 'night' ? '11.0' : '9.0') : '9.0',  // O
                $this->fmtHours((float)($r->worked_hours ?? 0)),   // P
                $this->fmtHours((float)($r->ot1_hours ?? 0)),      // Q
                $this->fmtHours((float)($r->ot2_hours ?? 0)),      // R
                $this->lostHours($r),                              // S
                $this->exceptionNote($r),                          // T
                // U — if clocked_in with no clock-out today, show Still In not Missed Clock-Out
                ($r->status === 'clocked_in' && !$r->check_out_time && ($r->date ?? '') === now()->toDateString())
                    ? 'Still In'
                    : ($r->interpretation ?? ''),                  // U
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = [
            'TOTALS',
            '', '', '', '', '', '', '', '', '', '', '', '',
            "=SUM(O{$ds}:O{$de})",
            "=SUM(P{$ds}:P{$de})",
            "=SUM(Q{$ds}:Q{$de})",
            "=SUM(R{$ds}:R{$de})",
            '',
            '', '',
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
                     'A3:A3' => '2E75B6', 'B3:B3' => 'C0341B', 'C3:F3' => '2E75B6',
                     'G3:H3' => '375623', 'I3:N3' => '1F6B3A', 'O3:O3' => '7F3F98',
                     'P3:P3' => '4472C4', 'Q3:R3' => 'C55A11', 'S3:S3' => 'C00000',
                     'T3:T3' => '833C00', 'U3:U3' => '404040',
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
            $sheet->getStyle("T{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);

            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = $this->employeeTypeBadge($empType);
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            $interpVal = (string) $sheet->getCell("U{$row}")->getValue();

            // If status is clocked_in and it's today, the shift may still be running.
            // Don't mark clock-out as missing or show "Missed Clock-Out" interpretation.
            $recordDate  = $records[$row - 4]->date ?? null;
            $recordStatus = $records[$row - 4]->status ?? null;
            $isStillIn   = $recordStatus === 'clocked_in'
                && $recordDate === now()->toDateString()
                && !($records[$row - 4]->check_out_time ?? null);

            if ($interpVal && !$isStillIn) {
                [$bg, $fg] = $this->interpretationBadge($interpVal);
                $sheet->getStyle("U{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }

            // Missed punch rows — highlight the missing column red
            // Skip if still in (shift may not have ended)
            if (!$isStillIn) {
                if (str_contains($interpVal, 'Missed Clock-In')) {
                    $sheet->getStyle("J{$row}")->applyFromArray($this->badgeStyle('F8D7DA', 'C00000'));
                    $sheet->getCell("J{$row}")->setValue('⚠ MISSING');
                }
                if (str_contains($interpVal, 'Missed Clock-Out')) {
                    $sheet->getStyle("N{$row}")->applyFromArray($this->badgeStyle('F8D7DA', 'C00000'));
                    $sheet->getCell("N{$row}")->setValue('⚠ MISSING');
                }
            }

            $lostVal = (string) $sheet->getCell("S{$row}")->getValue();
            if ($lostVal && $lostVal !== '0.0') {
                $sheet->getStyle("S{$row}")->getFont()->setBold(true)->getColor()->setRGB('C00000');
            }
        }

        $sheet->mergeCells("A{$totalRow}:N{$totalRow}");
        $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['O', 'P', 'Q', 'R'] as $col) {
            $sheet->getStyle("{$col}{$totalRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
            $sheet->getStyle("{$col}{$totalRow}")->getFont()->setColor(new Color('000000'))->setBold(true);
        }
        $sheet->getRowDimension($totalRow)->setRowHeight(20);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");
        return [];
    }
}

// =============================================================================
// PRESENT SHEET
// =============================================================================
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
            $this->shiftCache = Shift::whereIn('id', $shiftIds)->get()->keyBy('id')->all();
        }
    }

    public function title(): string { return 'Present'; }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 16, 'C' => 16, 'D' => 24,
            'E' => 18, 'F' => 14, 'G' => 18, 'H' => 10,
            'I' => 11, 'J' => 11, 'K' => 11, 'L' => 11, 'M' => 13,
        ];
    }

    public function array(): array
    {
        $out = [];
        $out[] = ['PRESENT REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [
            'Date', 'Employee Type', 'Employee Number', 'Name', 'Department', 'Section',
            'Staff Category', "Shift\n(Day/Night)", "Defined\nTime In", "Actual\nTime In",
            "Defined\nTime Out", "Actual\nTime Out", "Total Hours\nWorked",
        ];

        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $this->resolveShiftObject($r, $this->shiftCache);
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_type ?? '',
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->staffCategory($shift),
                $this->resolveShiftDisplay($r, $this->shiftCache),
                $shift ? Carbon::parse($shift->start_time)->format('H:i') : '',
                $this->fmtTime($r->check_in_time),
                $shift ? $shift->getEffectiveEndTime($r->date ?? now()->toDateString())->format('H:i') : '',                $this->fmtTime($r->check_out_time),
                $this->fmtHours((float)($r->worked_hours ?? 0)),
            ];
        }

        $ds = 4;
        $de = 3 + count($this->records);
        $out[] = ['TOTALS', ...array_fill(0, self::TOTAL_COLS - 2, ''), "=SUM(M{$ds}:M{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = 3 + count($this->records);
        $total = $last + 1;
        $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '1B5E20']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray(['font' => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(2)->setRowHeight(15);
        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('1B5E20'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) { [$bg, $fg] = $this->employeeTypeBadge($empType); $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg)); }

            // Flag missing clock-in/out in present sheet too
            $inVal  = (string) $sheet->getCell("J{$row}")->getValue();
            $outVal = (string) $sheet->getCell("L{$row}")->getValue();
            if (!$inVal)  { $sheet->getStyle("J{$row}")->applyFromArray($this->badgeStyle('F8D7DA', 'C00000')); $sheet->getCell("J{$row}")->setValue('⚠ MISSING'); }
            if (!$outVal) { $sheet->getStyle("L{$row}")->applyFromArray($this->badgeStyle('FFF3CD', '856404')); $sheet->getCell("L{$row}")->setValue('⚠ MISSING'); }
        }

        $sheet->mergeCells("A{$total}:L{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getStyle("M{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
        $sheet->getStyle("M{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");
        return [];
    }
}

// =============================================================================
// LATE SHEET
// =============================================================================
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
        return ['A' => 12, 'B' => 16, 'C' => 16, 'D' => 24, 'E' => 18, 'F' => 14, 'G' => 11, 'H' => 11, 'I' => 16, 'J' => 13];
    }

    public function array(): array
    {
        $out = [];
        $out[] = ['LATENESS REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = ['Date', 'Employee Type', 'Employee Number', 'Name', 'Department', 'Section', "Actual\nTime In", "Actual\nTime Out", "Expected Hours\n(Defined: 9h)", "Lateness\n(HH:MM)"];

        foreach ($this->records as $r) {
            $emp = $r->employee ?? null;
            $out[] = [
                $this->fmtDate($r->date), $emp?->employee_type ?? '', $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '', $emp?->department?->name ?? '', $emp?->section ?? '',
                $this->fmtTime($r->check_in_time), $this->fmtTime($r->check_out_time), '9:00',
                $r->minutes_late > 0 ? $this->minutesToHHMM((int) $r->minutes_late) : '',
            ];
        }

        $ds = 4; $de = 3 + count($this->records);
        $out[] = ['TOTAL LATE: ' . count($this->records), ...array_fill(0, self::TOTAL_COLS - 2, ''), "=COUNTA(J{$ds}:J{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = 3 + count($this->records); $total = $last + 1; $lastCol = self::LAST_COL;
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '856404']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF8E1']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray(['font' => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(2)->setRowHeight(15);
        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('856404'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDE7');
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) { [$bg, $fg] = $this->employeeTypeBadge($empType); $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg)); }
            $sheet->getStyle("J{$row}")->getFont()->setBold(true)->getColor()->setRGB('856404');
        }

        $sheet->mergeCells("A{$total}:I{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '856404']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getStyle("J{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
        $sheet->getStyle("J{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");
        return [];
    }
}

// =============================================================================
// ABSENT SHEET
// =============================================================================
class AbsentSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL   = 'H';  // extended to H to include shift type
    private const TOTAL_COLS = 8;
    private array $shiftCache = [];

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {
        $shiftIds = collect($records)->pluck('shift_id')->filter()->unique()->values()->toArray();
        if (!empty($shiftIds)) {
            $this->shiftCache = Shift::whereIn('id', $shiftIds)->get()->keyBy('id')->all();
        }
    }

    public function title(): string { return 'Absent Report'; }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 16, 'C' => 16, 'D' => 24, 'E' => 18, 'F' => 14, 'G' => 12, 'H' => 36];
    }

    public function array(): array
    {
        $out = [];
        $out[] = ['ABSENT REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = ['Date', 'Employee Type', 'Employee Number', 'Name', 'Department', 'Section', 'Shift', 'Reason / Exception'];

        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $this->shiftCache[$r->shift_id ?? 0] ?? $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_type ?? '',
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->shiftDayNight($shift),   // G ← Day or Night
                $this->absentReason($r, $shift), // H ← reason with shift context
            ];
        }

        $out[] = ['TOTAL ABSENCES: ' . count($this->records), '', '', '', '', '', '', ''];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = 3 + count($this->records); $total = $last + 1; $lastCol = self::LAST_COL;

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => 'C00000']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDEDED']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->applyFromArray(['font' => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension(2)->setRowHeight(15);
        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray($this->hdrStyle('C00000'));
        $sheet->getStyle('B3')->applyFromArray($this->hdrStyle('C0341B'));
        $sheet->getStyle('G3')->applyFromArray($this->hdrStyle('375623')); // Shift column — green

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $empType = (string) $sheet->getCell("B{$row}")->getValue();
            if ($empType) { [$bg, $fg] = $this->employeeTypeBadge($empType); $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg)); }

            // Shift badge — G
            $shiftType = (string) $sheet->getCell("G{$row}")->getValue();
            if ($shiftType === 'Night') {
                $sheet->getStyle("G{$row}")->applyFromArray($this->badgeStyle('1E1B4B', 'FFFFFF'));
            } elseif ($shiftType === 'Day') {
                $sheet->getStyle("G{$row}")->applyFromArray($this->badgeStyle('E8F5E9', '1B5E20'));
            }

            // Reason badge — H
            $reason = strtolower((string) $sheet->getCell("H{$row}")->getValue());
            if (str_contains($reason, 'leave') || str_contains($reason, 'sick')) {
                $sheet->getStyle("H{$row}")->applyFromArray($this->badgeStyle('CCE5FF', '004085'));
            } elseif (str_contains($reason, 'gate') || str_contains($reason, 'pass')) {
                $sheet->getStyle("H{$row}")->applyFromArray($this->badgeStyle('E2D9F3', '432E75'));
            } elseif (str_contains($reason, 'missed clock-in') || str_contains($reason, 'missed clock-out')) {
                $sheet->getStyle("H{$row}")->applyFromArray($this->badgeStyle('FFF3CD', '856404'));
            } else {
                $sheet->getStyle("H{$row}")->applyFromArray($this->badgeStyle('F8D7DA', '721C24'));
            }
        }

        $sheet->mergeCells("A{$total}:{$lastCol}{$total}");
        $sheet->getStyle("A{$total}:{$lastCol}{$total}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C00000']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getRowDimension($total)->setRowHeight(18);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:{$lastCol}3");
        return [];
    }

    private function absentReason($r, $shift = null): string
    {
        $scenario  = $r->scenario ?? '';
        $shiftType = $shift ? $this->shiftDayNight($shift) : '';
        $shiftLabel = $shiftType ? " ({$shiftType} Shift)" : '';

        // Missed punch cases — most important to show clearly
        if (str_starts_with($scenario, 'missed_clockin')) {
            $out = $r->check_out_time ? 'Clock-OUT at ' . Carbon::parse($r->check_out_time)->format('H:i') . ' recorded.' : '';
            return "Missed Clock-IN{$shiftLabel}. {$out} HR review required.";
        }
        if (str_starts_with($scenario, 'missed_clockout')) {
            $in = $r->check_in_time ? 'Clock-IN at ' . Carbon::parse($r->check_in_time)->format('H:i') . ' recorded.' : '';
            return "Missed Clock-OUT{$shiftLabel}. {$in} HR review required.";
        }

        return match ($r->status ?? '') {
            'on_leave'   => 'Annual Leave – Approved',
            'sick_leave' => 'Sick Leave – Approved',
            'sick_off'   => 'Sick Day – Approved',
            'gate_pass'  => 'Gate Pass – ' . ($r->exception_note ?? 'Approved'),
            default      => "Absent{$shiftLabel} – No Reason",
        };
    }
}
