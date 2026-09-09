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

/**
 * "By Employee" tab — one block per employee: banner row, header row,
 * raw daily attendance rows, TOTAL row, spacer.
 *
 * Field mapping confirmed against real Attendance/Employee/Department/Shift/
 * AttendanceBreakLog attributes (dumped via tinker on 2026-09-09). Meal
 * Time Out/In use AttendanceBreakLog.break_start_time / break_end_time.
 */
class EmployeeWeeklyAggregateSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    private const LAST_COL   = 'T';
    private const TOTAL_COLS = 20;

    /** @var array{title:int,period:int,banners:int[],headers:int[],totals:int[],blanks:int[]} */
    private array $meta = [
        'title' => 1, 'period' => 2, 'banners' => [], 'headers' => [], 'totals' => [], 'blanks' => [],
    ];

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string
    {
        return 'By Employee';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 14, 'C' => 16, 'D' => 20, 'E' => 18,
            'F' => 14, 'G' => 14, 'H' => 12, 'I' => 12, 'J' => 12,
            'K' => 12, 'L' => 12, 'M' => 11, 'N' => 12, 'O' => 12,
            'P' => 12, 'Q' => 14, 'R' => 9,  'S' => 9,  'T' => 15,
        ];
    }

    /**
     * Column definitions in display order. Each 'value' closure receives the
     * decorated Attendance record (with employee/department/shift/breakLogs
     * eager loaded) and returns the display string for that cell.
     * 'numeric' => true columns are summed into the per-employee TOTAL row
     * (the closure must return a raw numeric string/float for those, no
     * formatting/units baked in — formatting for numeric cols happens in
     * formatNumeric() below).
     */
    private function columns(): array
    {
        return [
            [
                'key' => 'A', 'header' => 'Date',
                'value' => fn($r) => $r->date ? Carbon::parse($r->date)->format('d/m/Y') : '',
            ],
            [
                'key' => 'B', 'header' => 'Employee Type',
                'value' => fn($r) => $r->employee?->employee_type ?? '',
            ],
            [
                'key' => 'C', 'header' => 'Employee Number',
                'value' => fn($r) => $this->employeeNumber($r->employee),
            ],
            [
                'key' => 'D', 'header' => 'Full Names',
                'value' => fn($r) => $r->employee?->name ?? '',
            ],
            [
                'key' => 'E', 'header' => 'Department',
                'value' => fn($r) => $r->employee?->department?->name ?? '',
            ],
            [
                'key' => 'F', 'header' => 'Section',
                'value' => fn($r) => $r->employee?->sectionRecord?->name ?? $r->employee?->section ?? '',
            ],
            [
                'key' => 'G', 'header' => 'Staff Category',
                'value' => fn($r) => $r->employee?->employee_category ?? '',
            ],
            [
                'key' => 'H', 'header' => 'Shift (Day/Night)',
                'value' => fn($r) => $this->shiftLabel($r),
            ],
            [
                'key' => 'I', 'header' => 'Defined Time In',
                'value' => fn($r) => $this->formatTime($r->expected_check_in_time),
            ],
            [
                'key' => 'J', 'header' => 'Actual Time In',
                'value' => fn($r) => $this->formatTime($r->check_in_time),
            ],
            [
                'key' => 'K', 'header' => 'Meal Time Out',
                'value' => fn($r) => $this->formatTime($r->breakLogs?->first()?->break_start_time),
            ],
            [
                'key' => 'L', 'header' => 'Meal Time In',
                'value' => fn($r) => $this->formatTime($r->breakLogs?->first()?->break_end_time),
            ],
            [
                'key' => 'M', 'header' => 'Total Break',
                'value' => fn($r) => $this->minutesToHm((int) ($r->total_break_minutes ?? 0)),
            ],
            [
                'key' => 'N', 'header' => 'Defined Time Out',
                'value' => fn($r) => $this->formatTime($r->expected_check_out_time),
            ],
            [
                'key' => 'O', 'header' => 'Actual Time Out',
                'value' => fn($r) => $this->formatTime($r->check_out_time),
            ],
            [
                'key' => 'P', 'header' => 'Defined Hours', 'numeric' => true,
                'value' => fn($r) => (float) ($r->defined_hours ?? 0),
            ],
            [
                'key' => 'Q', 'header' => 'Total Hours Worked', 'numeric' => true,
                'value' => fn($r) => (float) ($r->worked_hours ?? 0),
            ],
            [
                'key' => 'R', 'header' => 'OT 1', 'numeric' => true,
                'value' => fn($r) => (float) ($r->ot1_hours ?? 0),
            ],
            [
                'key' => 'S', 'header' => 'OT 2', 'numeric' => true,
                'value' => fn($r) => (float) ($r->ot2_hours ?? 0),
            ],
            [
                'key' => 'T', 'header' => 'Absent / Lost Hours', 'numeric' => true,
                'value' => fn($r) => round(((int) ($r->lost_minutes ?? 0)) / 60, 2),
            ],
        ];
    }

    public function array(): array
    {
        $out = [];
        $blankPad = array_fill(0, self::TOTAL_COLS - 1, '');

        $out[] = ['T&A REPORT — GROUPED BY EMPLOYEE', ...$blankPad];
        $out[] = [$this->periodLabel(), ...$blankPad];
        $out[] = array_fill(0, self::TOTAL_COLS, '');

        $rowIndex = 3;
        $columns  = $this->columns();
        $headers  = array_column($columns, 'header');

        foreach ($this->groupByEmployee($this->records) as $group) {
            $rowIndex++;
            $out[] = ["Employee {$group['employee_number']}  ({$group['count']} records)", ...$blankPad];
            $this->meta['banners'][] = $rowIndex;

            $rowIndex++;
            $out[] = $headers;
            $this->meta['headers'][] = $rowIndex;

            foreach ($group['rows'] as $row) {
                $rowIndex++;
                $out[] = $row;
            }

            $rowIndex++;
            $out[] = $group['totalRow'];
            $this->meta['totals'][] = $rowIndex;

            $rowIndex++;
            $out[] = array_fill(0, self::TOTAL_COLS, '');
            $this->meta['blanks'][] = $rowIndex;
        }

        return $out;
    }

    private function groupByEmployee(array $records): array
    {
        $byEmployee = [];

        foreach ($records as $record) {
            $employee = $record->employee ?? null;
            if (!$employee) continue;

            $byEmployee[$employee->id]['employee_number'] ??= $this->employeeNumber($employee);
            $byEmployee[$employee->id]['records'][] = $record;
        }

        ksort($byEmployee);

        $columns = $this->columns();
        $groups  = [];

        foreach ($byEmployee as $data) {
            $records = $data['records'];
            usort($records, fn($a, $b) => strcmp((string) $a->date, (string) $b->date));

            $rows = [];
            $sums = [];
            foreach ($columns as $col) {
                if (!empty($col['numeric'])) $sums[$col['key']] = 0.0;
            }

            foreach ($records as $record) {
                $rowData = [];
                foreach ($columns as $col) {
                    $value = ($col['value'])($record);
                    if (!empty($col['numeric'])) {
                        $sums[$col['key']] += (float) $value;
                        $rowData[] = number_format((float) $value, 2);
                    } else {
                        $rowData[] = $value;
                    }
                }
                $rows[] = $rowData;
            }

            $totalRow = [];
            foreach ($columns as $i => $col) {
                if ($i === 0) {
                    $totalRow[] = 'TOTAL';
                } elseif (!empty($col['numeric'])) {
                    $totalRow[] = number_format($sums[$col['key']], 1);
                } else {
                    $totalRow[] = '';
                }
            }

            $groups[] = [
                'employee_number' => $data['employee_number'],
                'count'           => count($records),
                'rows'            => $rows,
                'totalRow'        => $totalRow,
            ];
        }

        return $groups;
    }

    /** Real employee-number field, falling back sensibly if unset. */
    private function employeeNumber($employee): string
    {
        if (!$employee) return '';
        return (string) ($employee->id_number ?? $employee->ad_employee_id ?? $employee->id);
    }

    /** Day/Night label — prefers shift_type/name, falls back to start_time. */
    private function shiftLabel($record): string
    {
        $shift = $record->shift ?? $record->employee?->shift ?? null;
        if (!$shift) return '';

        if (!empty($shift->shift_type)) return ucfirst($shift->shift_type);
        if (stripos($shift->name ?? '', 'night') !== false) return 'Night';
        if (stripos($shift->name ?? '', 'day') !== false)   return 'Day';

        if (!empty($shift->start_time)) {
            $hour = (int) Carbon::parse($shift->start_time)->format('H');
            return ($hour >= 18 || $hour < 6) ? 'Night' : 'Day';
        }

        return '';
    }

    private function formatTime($value): string
    {
        if (empty($value)) return '';
        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function minutesToHm(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    private function periodLabel(): string
    {
        $start = $this->startDate ? Carbon::parse($this->startDate)->format('d M Y') : '—';
        $end   = $this->endDate ? Carbon::parse($this->endDate)->format('d M Y') : '—';
        $generated = Carbon::now()->format('d M Y H:i');

        return "Period: {$start} – {$end}   |   Generated: {$generated}";
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = self::LAST_COL;

        $titleRow = $this->meta['title'];
        $sheet->mergeCells("A{$titleRow}:{$lastCol}{$titleRow}");
        $sheet->getStyle("A{$titleRow}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($titleRow)->setRowHeight(24);

        $periodRow = $this->meta['period'];
        $sheet->mergeCells("A{$periodRow}:{$lastCol}{$periodRow}");
        $sheet->getStyle("A{$periodRow}")->applyFromArray([
            'font'      => ['italic' => true, 'color' => ['rgb' => '6B7280'], 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        foreach ($this->meta['banners'] as $row) {
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => '1E3A5F'], 'size' => 11],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCEEFB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => [
                    'top'    => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '22C55E']],
                    'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '22C55E']],
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
        }

        foreach ($this->meta['headers'] as $row) {
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(30);
        }

        foreach ($this->meta['totals'] as $row) {
            $sheet->mergeCells("A{$row}:O{$row}");
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => '7C2D12'], 'size' => 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCE0C8']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("P{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $highestRow = $sheet->getHighestRow();
        $skip = array_merge(
            [$this->meta['title'], $this->meta['period']],
            $this->meta['banners'], $this->meta['headers'], $this->meta['totals'], $this->meta['blanks']
        );

        for ($row = 4; $row <= $highestRow; $row++) {
            if (in_array($row, $skip, true)) continue;

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font'    => ['size' => 10, 'color' => ['rgb' => '111827']],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);
            $sheet->getStyle("A{$row}:C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("H{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        return [];
    }
}
