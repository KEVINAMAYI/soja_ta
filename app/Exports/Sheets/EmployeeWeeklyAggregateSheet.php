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
 * Employee Weekly Aggregated Report
 *
 * Columns:
 * A  Week Starting
 * B  Employee Number
 * C  Full Names
 * D  Department
 * E  Days Present
 * F  Total Hours Worked
 * G  OT 1 Hours
 * H  OT 2 Hours
 * I  Late Arrivals
 * J  Absent/Leave Days
 * K  Exceptions
 */
class EmployeeWeeklyAggregateSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    private const LAST_COL = 'K';
    private const TOTAL_COLS = 11;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string
    {
        return 'Weekly Summary';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, 'B' => 16, 'C' => 24, 'D' => 18,
            'E' => 13, 'F' => 15, 'G' => 12, 'H' => 12,
            'I' => 14, 'J' => 16, 'K' => 16,
        ];
    }

    public function array(): array
    {
        $out = [];

        // Title rows
        $out[] = ['EMPLOYEE WEEKLY AGGREGATED REPORT', ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, self::TOTAL_COLS - 1, '')];
        $out[] = []; // Blank row

        // Header row
        $out[] = [
            'Week Starting',      // A
            'Employee Number',    // B
            'Full Names',         // C
            'Department',         // D
            'Days Present',       // E
            'Total Hours Worked', // F
            'OT 1 Hours',        // G
            'OT 2 Hours',        // H
            'Late Arrivals',      // I
            'Absent/Leave Days',  // J
            'Exceptions',         // K
        ];

        // Group records by employee & ISO week
        $grouped = $this->aggregateByWeek($this->records);

        foreach ($grouped as $weekData) {
            $out[] = [
                $weekData['week_starting'],       // A
                $weekData['employee_number'],     // B
                $weekData['employee_name'],       // C
                $weekData['department'],          // D
                $weekData['days_present'],        // E
                number_format($weekData['total_hours'], 1),      // F
                number_format($weekData['ot1_hours'], 1),        // G
                number_format($weekData['ot2_hours'], 1),        // H
                $weekData['late_count'],          // I
                $weekData['absent_leave_days'],   // J
                $weekData['exceptions_count'],    // K
            ];
        }

        return $out;
    }

    /**
     * Aggregate attendance records by employee + ISO week
     */
    private function aggregateByWeek(array $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            $employee = $record->employee ?? null;
            if (!$employee) continue;

            $date = Carbon::parse($record->date ?? now());
            $weekStart = $date->startOfWeek()->toDateString();
            $weekKey = "{$employee->id}_{$weekStart}";

            if (!isset($grouped[$weekKey])) {
                $grouped[$weekKey] = [
                    'week_starting'    => Carbon::parse($weekStart)->format('d M Y'),
                    'employee_id'      => $employee->id,
                    'employee_number'  => $employee->ad_employee_id ?? $employee->id,
                    'employee_name'    => $employee->name ?? '',
                    'department'       => $employee->department?->name ?? '',
                    'days_present'     => 0,
                    'total_hours'      => 0.0,
                    'ot1_hours'        => 0.0,
                    'ot2_hours'        => 0.0,
                    'late_count'       => 0,
                    'absent_leave_days' => 0,
                    'exceptions_count' => 0,
                ];
            }

            // Aggregate logic
            $status = $record->status ?? '';
            $interpretation = $record->interpretation ?? '';

            // Count days present
            if (in_array($status, ['clocked_in', 'clocked_out'])) {
                $grouped[$weekKey]['days_present']++;
            }

            // Accumulate hours
            $grouped[$weekKey]['total_hours'] += (float)($record->total_hours ?? 0);
            $grouped[$weekKey]['ot1_hours'] += (float)($record->ot1_hours ?? 0);
            $grouped[$weekKey]['ot2_hours'] += (float)($record->ot2_hours ?? 0);

            // Count late arrivals
            if ($record->is_late_checkin && !($record->within_grace_period ?? false)) {
                $grouped[$weekKey]['late_count']++;
            }

            // Count absent/leave
            if (in_array($status, ['absent', 'unchecked_in', 'on_leave', 'sick_leave', 'sick_off'])) {
                $grouped[$weekKey]['absent_leave_days']++;
            }

            // Count exceptions
            if (!empty($record->exceptions)) {
                $grouped[$weekKey]['exceptions_count']++;
            }
        }

        // Sort by week and employee name
        usort($grouped, function($a, $b) {
            $cmp = strcmp($a['week_starting'], $b['week_starting']);
            return $cmp !== 0 ? $cmp : strcmp($a['employee_name'], $b['employee_name']);
        });

        return array_values($grouped);
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [];

        // Title row (Row 1)
        $styles['A1:K1'] = $this->hdrStyle('1F2937');

        // Period row (Row 2)
        $styles['A2:K2'] = $this->dataStyle('left', false);

        // Header row (Row 4)
        $styles['A4:K4'] = $this->hdrStyle('3B82F6');

        // Data rows (starting from Row 5)
        $rows = count($sheet->toArray());
        if ($rows > 4) {
            for ($row = 5; $row <= $rows; $row++) {
                // Alternate row colors
                $bgColor = ($row % 2 === 0) ? 'F3F4F6' : 'FFFFFF';

                // Employee number & name (left-aligned)
                $styles["A{$row}"] = $this->dataStyle('center');
                $styles["B{$row}"] = $this->dataStyle('center');
                $styles["C{$row}"] = $this->dataStyle('left');
                $styles["D{$row}"] = $this->dataStyle('left');

                // Numbers (center-aligned)
                for ($col = 'E'; $col <= 'K'; $col++) {
                    $cell = "{$col}{$row}";
                    $styles[$cell] = array_merge(
                        $this->dataStyle('center'),
                        ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]]]
                    );
                }
            }
        }

        return $styles;
    }
}
