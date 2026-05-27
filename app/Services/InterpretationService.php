<?php

namespace App\Services;

use App\Models\Attendance;

/**
 * Derives a human-readable interpretation for a single attendance record.
 *
 * Categories (in priority order):
 *   Absent with Approved Leave
 *   Absent with Approved Gate Pass
 *   Absent
 *   Late In & Late Out          (late clock-in AND early clock-out)
 *   Late In
 *   Early Out
 *   Extended Lunch              (excess_break_minutes > threshold)
 *   Attendance OK
 */
class InterpretationService
{
    /**
     * Number of excess break minutes that triggers "Extended Lunch".
     * Override via config('attendance.extended_lunch_threshold').
     */
    private int $extendedLunchThreshold;

    public function __construct()
    {
        $this->extendedLunchThreshold = config('attendance.extended_lunch_threshold', 15);
    }

    /**
     * Return the interpretation string for one attendance record (object or array).
     */
    public function interpret($attendance): string
    {
        // Accept both Eloquent models and plain objects / arrays
        $status           = $this->get($attendance, 'status');
        $checkIn          = $this->get($attendance, 'check_in_time');
        $checkOut         = $this->get($attendance, 'check_out_time');
        $isLateIn         = (bool) $this->get($attendance, 'is_late_checkin');
        $withinGrace      = (bool) $this->get($attendance, 'within_grace_period');
        $isEarlyOut       = (bool) $this->get($attendance, 'is_early_checkout');
        $isLateOut        = (bool) $this->get($attendance, 'is_late_checkout');
        $excessBreak      = (int)  $this->get($attendance, 'excess_break_minutes', 0);

        // ── Leave / gate-pass / absent ────────────────────────────────────
        if ($status === 'on_leave' || $status === 'sick_leave' || $status === 'sick_off') {
            return 'Absent with Approved Leave';
        }

        if ($status === 'gate_pass') {
            return 'Absent with Approved Gate Pass';
        }

        if (in_array($status, ['absent', 'unchecked_in', 'off_shift']) || !$checkIn) {
            return 'Absent';
        }

        // ── Tardy / departure anomalies ───────────────────────────────────
        // Late in counts only when NOT within grace period
        $actuallyLate = $isLateIn && !$withinGrace;

        if ($actuallyLate && $isEarlyOut) {
            return 'Late In & Late Out';   // "Late Out" used colloquially for "Left Early"
        }

        if ($actuallyLate) {
            return 'Late In';
        }

        if ($isEarlyOut) {
            return 'Early Out';
        }

        // ── Break overrun ─────────────────────────────────────────────────
        if ($excessBreak >= $this->extendedLunchThreshold) {
            return 'Extended Lunch';
        }

        // ── All good ──────────────────────────────────────────────────────
        return 'Attendance OK';
    }

    /**
     * Decorate a collection of attendance records with an `interpretation` property.
     * Works with Eloquent collections, plain object collections, and arrays.
     *
     * @param  iterable $records
     * @return array<object>   Same records with `interpretation` added
     */
    public function decorateCollection(iterable $records): array
    {
        $out = [];
        foreach ($records as $record) {
            // Clone so we don't mutate the original model
            $copy = is_object($record) ? clone $record : (object) $record;
            $copy->interpretation = $this->interpret($record);
            $out[] = $copy;
        }
        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get($attendance, string $key, $default = null)
    {
        if (is_array($attendance)) {
            return $attendance[$key] ?? $default;
        }
        return $attendance->{$key} ?? $default;
    }
}
