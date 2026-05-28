<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Employee;

class ZKPunchClassifier
{
    /**
     * Punches within this many minutes of each other = noise. Keep FIRST only.
     */
    const NOISE_GAP_MINUTES = 1;

    /**
     * How far before shift end a punch may fall and still be the final checkout.
     */
    const CHECKOUT_EARLY_WINDOW = 60;

    /**
     * Tolerance either side of a break window start time (minutes).
     */
    const BREAK_TOLERANCE = 20;

    public function classify(array $rawPunches, Employee $employee, ?string $date = null): array
    {
        $result = [
            'check_in' => null,
            'check_out' => null,
            'check_out_synthetic' => false,
            'late_checkin' => false,
            'early_checkout' => false,
            'minutes_late' => 0,
            'minutes_early' => 0,
            'segments' => [],
            'worked_hours' => 0.0,
            'overtime_hours' => 0.0,
            // ── Lost hours (only real losses, not missed punches) ──────────────
            'lost_minutes' => 0,
            'late_checkin_lost_minutes' => 0,   // only beyond grace period end
            'break_lost_minutes' => 0,   // only beyond max_duration_minutes
            'enforced_break_minutes' => 0,   // always 0 (no auto-deduct)
            'break_enforced' => false,
            // ── Missed punch flags ────────────────────────────────────────────
            'missed_break_return' => false, // no return punch OR no break at all
            'lost_hours_breakdown' => [],
            // ── Meta ─────────────────────────────────────────────────────────
            'scenario' => 'no_punches',
            'incomplete' => true,
            'raw_count' => count($rawPunches),
            'filtered_count' => 0,
            'notes' => [],
            'punches' => $rawPunches,
        ];

        // ── No punches ────────────────────────────────────────────────────────
        if (empty($rawPunches)) {
            $result['notes'][] = 'No punches recorded for this day.';
            return $result;
        }

        // ── No shift assigned ─────────────────────────────────────────────────
        $shift = $employee->shift;
        if (!$shift) {
            $result['scenario'] = 'no_shift';
            $result['notes'][] = 'No shift assigned — best-guess classification only.';
            $times = $this->filterNoise(array_map(fn($t) => Carbon::parse($t), $rawPunches));
            $result['filtered_count'] = count($times);
            if (count($times) >= 1) $result['check_in'] = $times[0];
            if (count($times) >= 2) $result['check_out'] = end($times);
            return $result;
        }

        // ── Not scheduled today ───────────────────────────────────────────────
        if (!$this->isScheduledToday($shift)) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][] = "Not scheduled today ({$this->todayName()}). Punches ignored.";
            return $result;
        }

        // ── Off shift / sick / on leave ───────────────────────────────────────
        if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][] = "Employee is {$employee->shift_status}. Punches recorded but not processed.";
            $result['check_in'] = Carbon::parse($rawPunches[0]);
            return $result;
        }

        // ── Shift boundaries ──────────────────────────────────────────────────
        $today = $date ?? now()->toDateString();
        $shiftStart = Carbon::parse($today . ' ' . Carbon::parse($shift->start_time)->format('H:i:s'));
        $shiftEnd = Carbon::parse($today . ' ' . Carbon::parse($shift->end_time)->format('H:i:s'));
        if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay();

        $gracePeriod = $shift->grace_period_enabled ? ($shift->grace_period_minutes ?? 0) : 0;
        $maxOvertimeMinutes = ($shift->max_overtime_hours ?? 0) * 60;
        $onTimeDeadline = $shiftStart->copy()->addMinutes($gracePeriod);
        $earlyThreshold = $shift->early_checkout_threshold_minutes ?? 0;
        $checkOutStart = $shiftEnd->copy()->subMinutes(self::CHECKOUT_EARLY_WINDOW + $earlyThreshold);
        $checkOutEnd = $shiftEnd->copy()->addMinutes($maxOvertimeMinutes + 30);

        // ── Filter noise ──────────────────────────────────────────────────────
        $filtered = $this->filterNoise(
            array_map(fn($t) => Carbon::parse($t), $rawPunches)
        );
        $result['filtered_count'] = count($filtered);

        if (empty($filtered)) {
            $result['notes'][] = 'All punches filtered as noise (too close together).';
            return $result;
        }

        // ── Punch 1: clock-in ─────────────────────────────────────────────────
        $result['check_in'] = $filtered[0];

        if ($filtered[0]->gt($onTimeDeadline)) {
            // Full lateness from shift start (for display/reporting)
            $minutesLate = (int)$shiftStart->diffInMinutes($filtered[0]);

            // LOST HOURS = full minutes from shift start
            // Grace period is included in the loss — once exceeded, all lateness counts.
            $lostFromLate = $minutesLate;  // ← was: (int)$onTimeDeadline->diffInMinutes($filtered[0])

            $result['late_checkin'] = true;
            $result['minutes_late'] = $minutesLate;
            $result['late_checkin_lost_minutes'] = $lostFromLate;

            $result['notes'][] = "⚠ Late clock-in at {$filtered[0]->format('H:i')}"
                . " — {$minutesLate} min late from shift start {$shiftStart->format('H:i')}."
                . " Grace period {$gracePeriod} min exceeded — full lateness counted as lost hours.";
        } else {
            $result['notes'][] = "✓ Clock-in at {$filtered[0]->format('H:i')} — on time (within grace period).";
        }

        if (count($filtered) === 1) {
            $result['scenario'] = 'checkin_only';
            $result['incomplete'] = true;
            $result['notes'][] = 'Only one punch — employee has not clocked out yet.';
            return $result;
        }

        // ── Walk punches 2…N in strict out/in alternation ─────────────────────
        $shiftBreaks = $shift->breaks ?? collect();
        $segments = [];
        $awaitingIn = false;
        $currentOut = null;
        $breakLost = 0;

        for ($i = 1; $i < count($filtered); $i++) {
            $punch = $filtered[$i];
            $punchNum = $i + 1;
            $isLast = ($i === count($filtered) - 1);

            if (!$awaitingIn) {
                // ── OUT punch ─────────────────────────────────────────────────
                $currentOut = $punch;
                $awaitingIn = true;

                if ($isLast) {
                    // Final punch is an out — normal checkout
                    $type = $this->classifyOut($punch, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);
                    $segments[] = [
                        'out' => $punch,
                        'in' => null,
                        'type' => $type,
                        'duration_minutes' => null,
                        'paid' => $type === 'break'
                            ? $this->isBreakPaid($punch, $shiftBreaks, $today)
                            : null,
                        'missed_punch' => false,
                        'assumed' => false,
                    ];
                    $result['check_out'] = $punch;
                    $result['notes'][] = "Clock-out at {$punch->format('H:i')} [punch #{$punchNum}] — {$type}.";
                } else {
                    $result['notes'][] = "Left at {$punch->format('H:i')} [punch #{$punchNum}].";
                }

            } else {
                // ── IN punch ─────────────────────────────────────────────────
                $outType = $this->classifyOut($currentOut, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);

                // ── SCENARIO: Missed break return punch ───────────────────────
                // Employee went for break, next punch is in checkout window.
                // → Flag as missed punch ONLY. No lost hours applied.
                if ($isLast && $outType === 'break' && $punch->between($checkOutStart, $checkOutEnd)) {

                    $segments[] = [
                        'out' => $currentOut,
                        'in' => null,      // no return punch recorded
                        'type' => 'break',
                        'duration_minutes' => null,      // unknown
                        'paid' => $this->isBreakPaid($currentOut, $shiftBreaks, $today),
                        'missed_punch' => true,      // ← missed punch flag
                        'assumed' => false,
                    ];

                    $result['check_out'] = $punch;
                    $result['missed_break_return'] = true;
                    // NO lost hours — just a missed punch
                    $awaitingIn = false;
                    $currentOut = null;

                    $result['notes'][] = "⚠ Missed break return punch — went for break at"
                        . " {$currentOut->format('H:i')}, next punch is checkout at {$punch->format('H:i')}."
                        . " Flagged as missed punch. No lost hours applied.";
                    $result['notes'][] = "Final clock-out at {$punch->format('H:i')} [punch #{$punchNum}].";
                    break;
                }

                // ── Normal return ─────────────────────────────────────────────
                $awaitingIn = false;
                $durationMins = (int)$currentOut->diffInMinutes($punch);
                $isPaid = $outType === 'break'
                    ? $this->isBreakPaid($currentOut, $shiftBreaks, $today)
                    : null;

                // ── SCENARIO: Late return from break ─────────────────────────
                // Lost hours = only minutes BEYOND max_duration_minutes.
                // duration_minutes = standard allowed time (no penalty yet).
                // max_duration_minutes = hard limit — loss starts here.
                if ($outType === 'break') {
                    $maxDuration = $this->getMaxBreakDuration($currentOut, $shiftBreaks, $today);
                    if ($durationMins > $maxDuration) {
                        $excess = $durationMins - $maxDuration;
                        $breakLost += $excess;
                        $result['notes'][] = "⚠ Late return from break — took {$durationMins} min,"
                            . " max allowed {$maxDuration} min."
                            . " Lost: {$excess} min (beyond max duration).";
                    } else {
                        $result['notes'][] = "✓ Break return on time — took {$durationMins} min,"
                            . " max allowed {$maxDuration} min.";
                    }
                }

                $segments[] = [
                    'out' => $currentOut,
                    'in' => $punch,
                    'type' => $outType,
                    'duration_minutes' => $durationMins,
                    'paid' => $isPaid,
                    'missed_punch' => false,
                    'assumed' => false,
                ];

                $result['notes'][] = "Returned at {$punch->format('H:i')} [punch #{$punchNum}]"
                    . " after {$durationMins} min ({$outType}).";

                if ($isLast) {
                    $result['notes'][] = "Last punch is a return — no final clock-out recorded.";
                }

                $currentOut = null;
            }
        }

        $result['segments'] = $segments;

        // ── Synthetic checkout ────────────────────────────────────────────────
        if ($result['check_out'] === null) {
            if (now()->gte($shiftEnd)) {
                $result['check_out'] = $shiftEnd->copy();
                $result['check_out_synthetic'] = true;
                $result['notes'][] = "⚠ No final clock-out. Auto-inserted at shift end"
                    . " {$shiftEnd->format('H:i')}.";
            } else {
                $result['notes'][] = "No clock-out yet. Shift ends at {$shiftEnd->format('H:i')}"
                    . " — record left open.";
            }
        }

        // ── Early checkout ────────────────────────────────────────────────────
        if (!$result['check_out_synthetic'] && $result['check_out']
            && $result['check_out']->lt($shiftEnd)) {
            $minutesEarly = (int)$result['check_out']->diffInMinutes($shiftEnd);
            $result['early_checkout'] = true;
            $result['minutes_early'] = $minutesEarly;
            $result['notes'][] = "⚠ Early clock-out at {$result['check_out']->format('H:i')}"
                . " — {$minutesEarly} min before shift end {$shiftEnd->format('H:i')}.";
        }

        // ── Overtime ──────────────────────────────────────────────────────────
        if (!$result['check_out_synthetic'] && $result['check_out']
            && $result['check_out']->gt($shiftEnd) && $shift->overtime_enabled) {
            $result['overtime_hours'] = $this->calcOvertimeHours($result['check_out'], $shiftEnd, $shift);
            if ($result['overtime_hours'] > 0) {
                $result['notes'][] = "Overtime: {$result['overtime_hours']}h after shift end.";
            }
        }

        // ── Worked hours ──────────────────────────────────────────────────────
        if ($result['check_in']) {
            $workedMinutes = 0;
            $arrivedAt = $result['check_in'];

            foreach ($result['segments'] as $seg) {
                $workedMinutes += $arrivedAt->diffInMinutes($seg['out']);
                if ($seg['in'] !== null) {
                    $arrivedAt = $seg['in'];
                } else {
                    $arrivedAt = null;
                    break;
                }
            }

            if ($arrivedAt !== null && $result['check_out']) {
                $workedMinutes += $arrivedAt->diffInMinutes($result['check_out']);
            }

            // ── No break punches detected ─────────────────────────────────────
            // Rule: flag as missed punch ONLY — no deduction.
            // Lost hours do NOT apply just because break was not punched.
            $breakSegmentsCount = collect($segments)->where('type', 'break')->count();
            $totalShiftMinutes = $shiftStart->diffInMinutes($shiftEnd);
            $enforceThreshold = $totalShiftMinutes * 0.6;

            if ($breakSegmentsCount === 0 && $workedMinutes >= $enforceThreshold) {
                // Flag missed punch — do NOT deduct from worked hours
                $result['missed_break_return'] = true;
                $result['break_enforced'] = false;
                $result['notes'][] = "⚠ No break punches recorded for full day —"
                    . " flagged as missed punch. No hours deducted.";
            }

            $result['worked_hours'] = round(max(0, $workedMinutes) / 60, 2);
            $result['notes'][] = "Worked hours (time inside building): {$result['worked_hours']}h.";
        }

        // ── Lost hours summary ────────────────────────────────────────────────
        $result['break_lost_minutes'] = $breakLost;
        $totalLost = $result['late_checkin_lost_minutes'] + $breakLost;
        $result['lost_minutes'] = $totalLost;

        $breakdown = [];
        if ($result['late_checkin_lost_minutes'] > 0) {
            $breakdown[] = "Late check-in: {$result['late_checkin_lost_minutes']} min"
                . " (beyond grace period end {$onTimeDeadline->format('H:i')})";
        }
        if ($breakLost > 0) {
            $breakdown[] = "Break overstay: {$breakLost} min (beyond max allowed duration)";
        }
        if ($result['missed_break_return']) {
            $breakdown[] = "Missed punch flagged (no lost hours applied)";
        }
        $result['lost_hours_breakdown'] = $breakdown;

        if ($totalLost > 0) {
            $result['notes'][] = "Total lost hours: {$totalLost} min. "
                . implode(' | ', array_filter($breakdown, fn($b) => !str_contains($b, 'Missed punch')));
        }

        // ── Scenario + incomplete ─────────────────────────────────────────────
        $result['scenario'] = $this->determineScenario($result);
        $result['incomplete'] = $this->isIncomplete($result);

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function classifyOut(
        Carbon $outPunch,
               $shiftBreaks,
        Carbon $checkOutStart,
        Carbon $checkOutEnd,
        string $today
    ): string
    {
        if ($outPunch->between($checkOutStart, $checkOutEnd)) {
            return 'checkout';
        }
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse(
                $today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s')
            );
            if ($outPunch->between(
                $windowStart->copy()->subMinutes(self::BREAK_TOLERANCE),
                $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)
            )) {
                return 'break';
            }
        }
        return 'unscheduled_leave';
    }

    /**
     * Max allowed duration before lost hours kick in.
     * Uses max_duration_minutes — the hard limit.
     * Falls back to duration_minutes if max not set.
     */
    private function getMaxBreakDuration(Carbon $outPunch, $shiftBreaks, string $today): int
    {
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse(
                $today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s')
            );
            if ($outPunch->between(
                $windowStart->copy()->subMinutes(self::BREAK_TOLERANCE),
                $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)
            )) {
                return $break->max_duration_minutes
                    ?? $break->duration_minutes
                    ?? 60;
            }
        }
        return 60;
    }

    /**
     * Standard allowed break duration (for reference/display only).
     */
    private function getAllowedBreakDuration(Carbon $outPunch, $shiftBreaks, string $today): int
    {
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse(
                $today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s')
            );
            if ($outPunch->between(
                $windowStart->copy()->subMinutes(self::BREAK_TOLERANCE),
                $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)
            )) {
                return $break->duration_minutes ?? 60;
            }
        }
        return 60;
    }

    private function isBreakPaid(Carbon $outPunch, $shiftBreaks, string $today): bool
    {
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse(
                $today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s')
            );
            if ($outPunch->between(
                $windowStart->copy()->subMinutes(self::BREAK_TOLERANCE),
                $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)
            )) {
                return ($break->type ?? 'unpaid') === 'paid';
            }
        }
        return false;
    }

    private function filterNoise(array $times): array
    {
        if (empty($times)) return [];
        $filtered = [$times[0]];
        $last = $times[0]->copy();
        foreach (array_slice($times, 1) as $time) {
            if ($last->diffInSeconds($time) >= self::NOISE_GAP_MINUTES * 60) {
                $filtered[] = $time;
                $last = $time->copy();
            }
        }
        return $filtered;
    }

    private function isScheduledToday($shift): bool
    {
        $pattern = $shift->pattern_type ?? 'weekdays';
        $patternDays = $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        $today = now()->format('D');
        return match ($pattern) {
            'weekdays' => in_array($today, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
            'weekends' => in_array($today, ['Sat', 'Sun']),
            'daily' => true,
            default => in_array($today, $patternDays),
        };
    }

    private function todayName(): string
    {
        return now()->format('l');
    }

    private function calcOvertimeHours(Carbon $checkout, Carbon $shiftEnd, $shift): float
    {
        if (!$shift->overtime_enabled) return 0;
        $otMinutes = $shiftEnd->diffInMinutes($checkout);
        $maxOtMinutes = ($shift->max_overtime_hours ?? 0) * 60;
        return round(min($otMinutes, $maxOtMinutes) / 60, 2);
    }

    private function determineScenario(array $r): string
    {
        if (!$r['check_in']) return 'no_checkin';
        if (!$r['check_out']) return 'checkin_only';

        $synthetic = $r['check_out_synthetic'];
        $segments = collect($r['segments']);
        $hasBreak = $segments->where('type', 'break')->count() > 0;
        $hasUnscheduled = $segments->where('type', 'unscheduled_leave')->count() > 0;
        $missedPunch = $r['missed_break_return'];

        if ($synthetic) {
            if ($hasBreak && $hasUnscheduled) return 'synthetic_break_unscheduled';
            if ($hasBreak) return 'synthetic_break';
            if ($hasUnscheduled) return 'synthetic_unscheduled';
            return 'synthetic';
        }

        $tokens = [];
        if ($r['late_checkin']) $tokens[] = 'late';
        if ($hasBreak) $tokens[] = 'break';
        if ($missedPunch) $tokens[] = 'missed_punch';
        if ($hasUnscheduled) $tokens[] = 'unscheduled';
        if ($r['early_checkout']) $tokens[] = 'early';
        if ($r['overtime_hours'] > 0) $tokens[] = 'overtime';

        return empty($tokens) ? 'complete' : 'complete_' . implode('_', $tokens);
    }

    private function isIncomplete(array $r): bool
    {
        return in_array($r['scenario'], [
            'no_punches', 'no_checkin', 'checkin_only', 'no_shift',
            'not_scheduled', 'synthetic', 'synthetic_break',
            'synthetic_unscheduled', 'synthetic_break_unscheduled', 'unknown',
        ]);
    }
}
