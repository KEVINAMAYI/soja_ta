<?php

namespace App\Exports\Sheets;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

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


    /**
     * Total break time for the record — sums breakLogs, falls back to DB column.
     * Returns "H:MM" (e.g. "1:05") or "0:45", empty if no break.
     */
    private function totalBreakTime($r): string
    {
        $minutes = 0;

        if (method_exists($r, 'relationLoaded') && $r->relationLoaded('breakLogs')) {
            foreach ($r->breakLogs as $b) {
                if (($b->break_start_time ?? null) && ($b->break_end_time ?? null)) {
                    $minutes += Carbon::parse($b->break_start_time)
                        ->diffInMinutes(Carbon::parse($b->break_end_time));
                }
            }
        }

        // Fallback to a stored column if logs weren't loaded / empty
        if ($minutes === 0) {
            $minutes = (int) ($r->total_break_minutes ?? $r->break_minutes ?? 0);
        }

        return $minutes > 0 ? $this->minutesToHHMM($minutes) : '';
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

    // ── Employee Type badge colours ────────────────────────────────────────────
    private function employeeTypeBadge(string $type): array
    {
        return match ($type) {
            'COSMOS'     => ['E0F2FE', '0369A1'],
            'Outsourced' => ['FDE8E3', 'C0341B'],
            default      => ['F1F5F9', '475569'],
        };
    }

    // ── Interpretation badge colours ──────────────────────────────────────────
    private function interpretationBadge(string $interp): array
    {
        return match ($interp) {
            'Attendance OK'                  => ['D4EDDA', '155724'],
            'Late In'                        => ['FFF3CD', '856404'],
            'Late In & Late Out'             => ['F8D7DA', '721C24'],
            'Early Out'                      => ['FFF3CD', '856404'],
            'Extended Lunch'                 => ['D1ECF1', '0C5460'],
            'Absent'                         => ['F8D7DA', '721C24'],
            'Absent with Approved Leave'     => ['CCE5FF', '004085'],
            'Absent with Approved Gate Pass' => ['E2D9F3', '432E75'],
            'Overtime 1'                     => ['FFE0B2', 'BF360C'],
            'Overtime 2'                     => ['EDE7F6', '4527A0'],
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

    // ── Uses Shift model department_type / shift_type if available ────────────
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


    // Always 9.0 per client requirement
    private function shiftDefinedHours($shift): string
    {
        return '9.0';
    }

    /**
     * Lost hours display for the report.
     *
     * Sources (in priority order):
     *   1. Absent / unchecked_in     → full 9h shift lost
     *   2. lost_minutes column in DB → actual minutes lost (late in + break overstay)
     *      e.g. 95 minutes → "1h 35m"
     *   3. No loss                   → "0.0"
     */
    private function lostHours($r): string
    {

        $dow     = \Carbon\Carbon::parse($r->date ?? now()->toDateString())->format('l');
        $isNight = ($r->shift?->shift_type ?? '') === 'night';

        // No lost hours on OT days
        if (in_array($dow, ['Saturday', 'Sunday'])) return '0.0';
        if ($dow === 'Friday' && $isNight) return '0.0';

        if (in_array($r->status ?? '', ['absent', 'unchecked_in'])) {
            return $dow === 'Friday' ? '8.0' : ($isNight ? '11.0' : '9.0');
        }

        // Read directly from the DB column synced by ZKPunchClassifier
        $lostMinutes = (int) ($r->lost_minutes ?? 0);

        if ($lostMinutes <= 0) {
            return '0.0';
        }

        // Convert to h:mm format so "95 minutes" shows as "1:35" not "1.58"
        $hours   = (int) floor($lostMinutes / 60);
        $minutes = $lostMinutes % 60;

        if ($hours === 0) {
            return "0:{$minutes}";       // e.g. "0:45"
        }

        return sprintf('%d:%02d', $hours, $minutes); // e.g. "1:35"
    }

    /**
     * Break down of what caused the lost hours — shown in Exceptions column
     * if no manual exception_note is set.
     */
    private function lostHoursBreakdown($r): string
    {
        if (!empty($r->exception_note)) {
            return $r->exception_note;
        }

        $parts = [];

        $lateMin = (int) ($r->late_checkin_lost_minutes ?? 0);
        if ($lateMin > 0) {
            $parts[] = "Late in: {$lateMin}m";
        }

        $breakMin = (int) ($r->break_lost_minutes ?? 0);
        if ($breakMin > 0) {
            $parts[] = "Break overstay: {$breakMin}m";
        }

        if ($r->missed_break_return ?? false) {
            $parts[] = "Missed punch (no deduction)";
        }

        return implode(' | ', $parts);
    }
}
