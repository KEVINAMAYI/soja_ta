<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;

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
        $isLateIn = (bool)$this->get($attendance, 'is_late_checkin');
        $withinGrace = (bool)$this->get($attendance, 'within_grace_period');
        $isEarlyOut = (bool)$this->get($attendance, 'is_early_checkout');
        $excessBreak = (int)$this->get($attendance, 'excess_break_minutes', 0);

        // Day of week from attendance date
        $date = $this->get($attendance, 'date');
        $isSaturday = false;
        $isSunday = false;
        if ($date) {
            $dow = Carbon::parse($date)->dayOfWeek;
            $isSaturday = $dow === Carbon::SATURDAY;
            $isSunday = $dow === Carbon::SUNDAY;
        }

        // Leave / gate-pass
        if (in_array($status, ['on_leave', 'sick_leave', 'sick_off'])) {
            return 'Absent with Approved Leave';
        }
        if ($status === 'gate_pass') {
            return 'Absent with Approved Gate Pass';
        }

        // Weekend OT — checked BEFORE any absent/late logic
        // Weekend OT — checked BEFORE any absent/late logic
        if ($isSaturday) {
            if (!$checkIn) return 'Weekend — No OT';
            // Could be clocked_in (not yet clocked out) or clocked_out
            $ot1 = $this->get($attendance, 'ot1_hours', 0);
            return $ot1 > 0 ? "Overtime 1 ({$ot1}h)" : 'Overtime 1 (In Progress)';
        }
        if ($isSunday) {
            if (!$checkIn) return 'Weekend — No OT';
            $ot2 = $this->get($attendance, 'ot2_hours', 0);
            return $ot2 > 0 ? "Overtime 2 ({$ot2}h)" : 'Overtime 2 (In Progress)';
        }

        // Absent (weekday)
        if (in_array($status, ['absent', 'unchecked_in', 'off_shift']) || !$checkIn) {
            return 'Absent';
        }

        // Late / Early (weekday only)
        $actuallyLate = $isLateIn && !$withinGrace;
        if ($actuallyLate && $isEarlyOut) return 'Late In & Late Out';
        if ($actuallyLate) return 'Late In';
        if ($isEarlyOut) return 'Early Out';

        // Break overrun
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

    private function get($attendance, string $key, $default = null)
    {
        if (is_array($attendance)) return $attendance[$key] ?? $default;
        return $attendance->{$key} ?? $default;
    }
}
