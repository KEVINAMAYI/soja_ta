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
    private function staffCategory($shift): string
    {
        if (!$shift) return '';
        if ($shift->department_type) return ucfirst($shift->department_type) . ' Shift';
        return str_contains(strtolower($shift->name ?? ''), 'admin') ? 'Admin Shift' : 'General Shift';
    }

    private function shiftDayNight($shift): string
    {
        if (!$shift) return '';
        if ($shift->shift_type) return ucfirst($shift->shift_type);
        $start = (int) Carbon::parse($shift->start_time)->format('H');
        return ($start >= 6 && $start < 18) ? 'Day' : 'Night';
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
        // Absent = full shift lost
        if (in_array($r->status ?? '', ['absent', 'unchecked_in'])) {
            return '9.0';
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
