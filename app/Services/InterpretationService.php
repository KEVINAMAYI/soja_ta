<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * InterpretationService
 *
 * Priority order:
 *  1. Approved Leave / Gate Pass
 *  2. Missed Clock-In (Day/Night)   ← reads scenario + punch time
 *  3. Missed Clock-Out (Day/Night)  ← reads scenario + punch time
 *  4. Weekend OT1 / OT2             ← checked BEFORE absent/late
 *  5. Weekend — No OT
 *  6. Absent (weekday)
 *  7. Late In & Late Out / Late In / Early Out / Extended Lunch
 *  8. Attendance OK
 */
class InterpretationService
{
    private int $extendedLunchThreshold;

    public function __construct()
    {
        $this->extendedLunchThreshold = config('attendance.extended_lunch_threshold', 15);
    }

    public function interpret($attendance): string
    {
        $status = $this->get($attendance, 'status');
        $checkIn = $this->get($attendance, 'check_in_time');
        $checkOut = $this->get($attendance, 'check_out_time');
        $scenario = $this->get($attendance, 'scenario', '');
        $isLateIn = (bool)$this->get($attendance, 'is_late_checkin');
        $withinGrace = (bool)$this->get($attendance, 'within_grace_period');
        $isEarlyOut = (bool)$this->get($attendance, 'is_early_checkout');
        $excessBreak = (int)$this->get($attendance, 'excess_break_minutes', 0);

        $date = $this->get($attendance, 'date');
        $isSaturday = false;
        $isSunday = false;
        if ($date) {
            $dow = Carbon::parse($date)->dayOfWeek;
            $isSaturday = $dow === Carbon::SATURDAY;
            $isSunday = $dow === Carbon::SUNDAY;
        }

        // ── 1. Leave / gate-pass ─────────────────────────────────────────
        if (in_array($status, ['on_leave', 'sick_leave', 'sick_off'])) {
            return 'Absent with Approved Leave';
        }
        if ($status === 'gate_pass') {
            return 'Absent with Approved Gate Pass';
        }

        // ── 2. Missed clock-in — only clock-OUT is recorded ─────────────
        if (str_starts_with($scenario, 'missed_clockin')) {
            $shiftLabel = $this->shiftLabel($checkOut);
            return "Missed Clock-In ({$shiftLabel})";
        }

        // ── 3. Missed clock-out — only clock-IN is recorded ─────────────
        if (str_starts_with($scenario, 'missed_clockout')) {
            $shiftLabel = $this->shiftLabel($checkIn);
            return "Missed Clock-Out ({$shiftLabel})";
        }

        // ── 4. Weekend OT — checked BEFORE absent/late ───────────────────
        if ($isSaturday) {
            return $checkIn ? 'Overtime 1' : 'Weekend — No OT';
        }
        if ($isSunday) {
            return $checkIn ? 'Overtime 2' : 'Weekend — No OT';
        }

        // Friday night shift = OT1
        if ($date) {
            $isFriday = Carbon::parse($date)->format('l') === 'Friday';
            if ($isFriday && $checkIn) {
                $hour = (int)Carbon::parse($checkIn)->format('H');
                $isFridayNight = ($hour >= 16 || $hour < 6);
                if ($isFridayNight) {
                    return 'Overtime 1';
                }
            }
        }

        // ── 5. Absent (weekday) ──────────────────────────────────────────
        if (in_array($status, ['absent', 'unchecked_in', 'off_shift']) || !$checkIn) {
            return 'Absent';
        }

        // ── 6. Late / Early (weekday only) ───────────────────────────────
        $actuallyLate = $isLateIn && !$withinGrace;
        if ($actuallyLate && $isEarlyOut) return 'Late In & Late Out';
        if ($actuallyLate) return 'Late In';
        if ($isEarlyOut) return 'Early Out';

        // ── 7. Break overrun ─────────────────────────────────────────────
        if ($excessBreak >= $this->extendedLunchThreshold) return 'Extended Lunch';

        return 'Attendance OK';
    }

    public function decorateCollection(iterable $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $copy = is_object($record) ? clone $record : (object)$record;
            $copy->interpretation = $this->interpret($record);
            $out[] = $copy;
        }
        return $out;
    }

    /**
     * Determine "Day Shift" or "Night Shift" from a punch timestamp.
     * Night = 16:00–05:59, Day = 06:00–15:59
     */
    private function shiftLabel($punchTime): string
    {
        if (!$punchTime) return 'Day Shift';
        try {
            $hour = (int)Carbon::parse($punchTime)->format('H');
            return ($hour >= 16 || $hour < 6) ? 'Night Shift' : 'Day Shift';
        } catch (\Throwable) {
            return 'Day Shift';
        }
    }

    private function get($attendance, string $key, $default = null)
    {
        if (is_array($attendance)) return $attendance[$key] ?? $default;
        return $attendance->{$key} ?? $default;
    }
}
