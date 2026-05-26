<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Employee;

class ZKPunchClassifier
{
    /**
     * Punches within this many minutes of each other = noise (double scans).
     * Keep only the FIRST of each cluster.
     */
    const NOISE_GAP_MINUTES = 5;

    /**
     * How far before shift end a clock-out may fall and still be considered
     * the final checkout (employee left early).
     */
    const CHECKOUT_EARLY_WINDOW = 60;

    /**
     * Tolerance either side of a configured break window start time (minutes).
     */
    const BREAK_TOLERANCE = 20;

    /**
     * Classify all punches for one employee on one day.
     *
     * ── Punch model ──────────────────────────────────────────────────────────
     *
     *   Punch 1  → clock-in            (always, no rejection)
     *   Punch 2  → clock-out           (first departure)
     *   Punch 3  → clock-in            (returned)
     *   Punch 4  → clock-out           (second departure)
     *   …
     *
     * Every "out" punch is classified against:
     *   • configured break windows  → type = 'break'
     *   • the shift checkout window → type = 'checkout'
     *   • neither                   → type = 'unscheduled_leave'
     *
     * ── Nothing is left hanging ──────────────────────────────────────────────
     *
     *   Case A — last punch is an OUT:
     *     That punch IS the final clock-out. The segment's 'in' is null and
     *     'duration_minutes' is null. check_out = that punch time. No phantom
     *     "open segment" — the employee simply left and didn't return.
     *
     *   Case B — last punch is an IN (no final clock-out):
     *     A synthetic clock-out is inserted at shift end so tomorrow's data
     *     is not contaminated.
     *
     * ── Extra flags ──────────────────────────────────────────────────────────
     *
     *   late_checkin   — clock-in arrived after shift start + grace period
     *   early_checkout — real (non-synthetic) clock-out before shift end
     *                    minus the configured early-checkout threshold
     *
     * ── Return shape ─────────────────────────────────────────────────────────
     * [
     *   check_in             => Carbon|null
     *   check_out            => Carbon|null
     *   check_out_synthetic  => bool
     *   late_checkin         => bool
     *   early_checkout       => bool
     *   minutes_late         => int          // 0 when on time
     *   minutes_early        => int          // 0 when not early
     *   segments             => [
     *     [
     *       'out'              => Carbon,
     *       'in'               => Carbon|null,   // null = never returned
     *       'type'             => 'break'|'checkout'|'unscheduled_leave',
     *       'duration_minutes' => int|null,       // null when 'in' is null
     *       'paid'             => bool|null,      // only set for type='break'
     *     ], …
     *   ]
     *   worked_hours         => float
     *   overtime_hours       => float
     *   scenario             => string
     *   incomplete           => bool
     *   raw_count            => int
     *   filtered_count       => int
     *   notes                => string[]
     *   punches              => string[]     // original input unchanged
     * ]
     */
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

        // ── On leave / off shift ──────────────────────────────────────────────
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

        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay(); // overnight shift
        }

        $gracePeriod = $shift->grace_period_enabled ? ($shift->grace_period_minutes ?? 0) : 0;
        $maxOvertimeMinutes = ($shift->max_overtime_hours ?? 0) * 60;

        // Latest clock-in time considered on-time
        $onTimeDeadline = $shiftStart->copy()->addMinutes($gracePeriod);

        // Earliest clock-out accepted as the final checkout (employee left early)
        $earlyCheckoutThreshold = $shift->early_checkout_threshold_minutes ?? 0;
        $checkOutStart = $shiftEnd->copy()->subMinutes(self::CHECKOUT_EARLY_WINDOW + $earlyCheckoutThreshold);
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

        // ── Punch 1: always the clock-in ──────────────────────────────────────
        // The ZKBio device already scopes punches to this employee on this date,
        // so whatever arrives first is their clock-in — no window rejection.
        $result['check_in'] = $filtered[0];

        if ($filtered[0]->gt($onTimeDeadline)) {
            $minutesLate = (int)$onTimeDeadline->diffInMinutes($filtered[0]);
            $result['late_checkin'] = true;
            $result['minutes_late'] = $minutesLate;
            $result['notes'][] = "⚠ Late clock-in at {$filtered[0]->format('H:i')} [punch #1] "
                . "— {$minutesLate} min late (shift started {$shiftStart->format('H:i')}, "
                . "grace until {$onTimeDeadline->format('H:i')}).";
        } else {
            $result['notes'][] = "✓ Clock-in at {$filtered[0]->format('H:i')} [punch #1] — on time.";
        }

        // Only one punch: checked in, no departure yet.
        if (count($filtered) === 1) {
            $result['scenario'] = 'checkin_only';
            $result['incomplete'] = true;
            $result['notes'][] = 'Only one punch — employee has not clocked out yet.';
            return $result;
        }

        // ── Walk punches 2…N in strict out/in alternation ─────────────────────
        //
        // $awaitingIn  = true  → employee is currently outside (we saw an out punch,
        //                         waiting for the matching in punch)
        // $awaitingIn  = false → employee is currently inside (waiting for next out)
        //
        // When we hit the LAST punch:
        //   • If it's an OUT → final departure. The segment's 'in' is null.
        //     check_out = this punch. Nothing is left hanging.
        //   • If it's an IN  → employee returned but never clocked out.
        //     A synthetic checkout will be added below.
        //
        $shiftBreaks = $shift->breaks ?? collect();
        $segments = [];
        $awaitingIn = false;
        $currentOut = null;

        for ($i = 1; $i < count($filtered); $i++) {
            $punch = $filtered[$i];
            $punchNum = $i + 1;
            $isLast = ($i === count($filtered) - 1);

            if (!$awaitingIn) {
                // ── OUT punch ─────────────────────────────────────────────────
                $currentOut = $punch;
                $awaitingIn = true;

                if ($isLast) {
                    // Final punch is an out → employee left and didn't return.
                    // This IS the checkout. Segment has no 'in'.
                    $type = $this->classifyOut($punch, $shiftBreaks, $checkOutStart, $checkOutEnd);

                    $segments[] = [
                        'out' => $punch,
                        'in' => null,
                        'type' => $type,
                        'duration_minutes' => null,
                        'paid' => $type === 'break' ? $this->isBreakPaid($punch, $shiftBreaks) : null,
                    ];

                    $result['check_out'] = $punch;
                    $result['notes'][] = "Clock-out at {$punch->format('H:i')} [punch #{$punchNum}] → {$type}.";
                } else {
                    $result['notes'][] = "Left at {$punch->format('H:i')} [punch #{$punchNum}].";
                }

            } else {
                // ── IN punch (return) ─────────────────────────────────────────
                $awaitingIn = false;
                $type = $this->classifyOut($currentOut, $shiftBreaks, $checkOutStart, $checkOutEnd);
                $durationMins = (int)$currentOut->diffInMinutes($punch);
                $isPaid = $type === 'break' ? $this->isBreakPaid($currentOut, $shiftBreaks) : null;

                $segments[] = [
                    'out' => $currentOut,
                    'in' => $punch,
                    'type' => $type,
                    'duration_minutes' => $durationMins,
                    'paid' => $isPaid,
                ];

                $result['notes'][] = "Returned at {$punch->format('H:i')} [punch #{$punchNum}] "
                    . "after {$durationMins} min ({$type}).";

                if ($isLast) {
                    // Last punch is an in → still inside, no final clock-out yet.
                    // Synthetic checkout added below.
                    $result['notes'][] = "Last punch is a return — no final clock-out recorded.";
                }

                $currentOut = null;
            }
        }

        $result['segments'] = $segments;

        // ── Synthetic checkout (last punch was an IN) ─────────────────────────
        if ($result['check_out'] === null) {
            if (now()->gte($shiftEnd)) {
                $synthetic = $shiftEnd->copy();
                $result['check_out'] = $synthetic;
                $result['check_out_synthetic'] = true;
                $result['notes'][] = "⚠ No final clock-out. Auto-inserted synthetic checkout "
                    . "at shift end ({$synthetic->format('H:i')}).";
            } else {
                $result['notes'][] = "No clock-out yet. Shift ends at {$shiftEnd->format('H:i')} — record left open.";
            }
        }

        // ── Early checkout flag ───────────────────────────────────────────────
        // Only applies to real (non-synthetic) checkouts.
        if (!$result['check_out_synthetic'] && $result['check_out']->lt($shiftEnd)) {
            $minutesEarly = (int)$result['check_out']->diffInMinutes($shiftEnd);
            $result['early_checkout'] = true;
            $result['minutes_early'] = $minutesEarly;
            $result['notes'][] = "⚠ Early clock-out at {$result['check_out']->format('H:i')} "
                . "— {$minutesEarly} min before shift end ({$shiftEnd->format('H:i')}).";
        }

        // ── Overtime ──────────────────────────────────────────────────────────
        if (
            !$result['check_out_synthetic']
            && $result['check_out']->gt($shiftEnd)
            && $shift->overtime_enabled
        ) {
            $result['overtime_hours'] = $this->calcOvertimeHours($result['check_out'], $shiftEnd, $shift);
            if ($result['overtime_hours'] > 0) {
                $result['notes'][] = "Overtime: {$result['overtime_hours']}h after shift end ({$shiftEnd->format('H:i')}).";
            }
        }

        // ── Worked hours ──────────────────────────────────────────────────────
        //
        // Sum all time the employee was physically INSIDE the building:
        //   worked = Σ (arrivedAt → seg['out'])  for each segment,
        //            plus (last arrivedAt → check_out) if still inside at end.
        //
        // This naturally excludes all out-of-building time (breaks, unscheduled
        // leave, etc.) without any separate deduction logic.
        //
        if ($result['check_in']) {
            $workedMinutes = 0;
            $arrivedAt = $result['check_in'];

            foreach ($result['segments'] as $seg) {
                // Time inside from last arrival until this departure
                $workedMinutes += $arrivedAt->diffInMinutes($seg['out']);

                if ($seg['in'] !== null) {
                    $arrivedAt = $seg['in']; // returned; next inside period starts here
                } else {
                    $arrivedAt = null; // left and never came back
                    break;
                }
            }

            // Still inside at end of day (no segments, or last punch was a return)
            if ($arrivedAt !== null && $result['check_out']) {
                $workedMinutes += $arrivedAt->diffInMinutes($result['check_out']);
            }

            $result['worked_hours'] = round(max(0, $workedMinutes) / 60, 2);
            $result['notes'][] = "Worked hours (time inside building): {$result['worked_hours']}h.";
        }

        // ── Scenario + incomplete ─────────────────────────────────────────────
        $result['scenario'] = $this->determineScenario($result);
        $result['incomplete'] = $this->isIncomplete($result);

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Classify an out-punch as 'break', 'checkout', or 'unscheduled_leave'.
     *
     * Priority:
     *   1. Falls within the shift checkout window          → 'checkout'
     *   2. Falls within a configured break window (±tolerance) → 'break'
     *   3. Neither                                         → 'unscheduled_leave'
     */
    private function classifyOut(
        Carbon $outPunch,
               $shiftBreaks,
        Carbon $checkOutStart,
        Carbon $checkOutEnd
    ): string
    {
        // Checkout window takes precedence — an out punch near end-of-shift
        // that also happens to overlap a break window is still a checkout.
        if ($outPunch->between($checkOutStart, $checkOutEnd)) {
            return 'checkout';
        }

        $today = $date ?? now()->toDateString();

        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) {
                continue;
            }

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
     * Return whether the break window the out-punch matched is paid.
     */
    private function isBreakPaid(Carbon $outPunch, $shiftBreaks): bool
    {
        $today = now()->toDateString();

        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) {
                continue;
            }

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

    /**
     * Remove punches that are too close together; keep the FIRST of each cluster.
     */
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
            'custom', 'rotating' => in_array($today, $patternDays),
            default => in_array($today, $patternDays),
        };
    }

    private function todayName(): string
    {
        return now()->format('l');
    }

    private function calcOvertimeHours(Carbon $checkout, Carbon $shiftEnd, $shift): float
    {
        if (!$shift->overtime_enabled) {
            return 0;
        }

        $otMinutes = $shiftEnd->diffInMinutes($checkout);
        $maxOtMinutes = ($shift->max_overtime_hours ?? 0) * 60;

        return round(min($otMinutes, $maxOtMinutes) / 60, 2);
    }

    /**
     * Derive a human-readable scenario label from the classified result.
     *
     * Scenarios are mutually exclusive and listed from most specific to
     * least specific. Every possible punch combination maps to exactly one.
     *
     * Suffixes:
     *   _late        — clock-in after grace period
     *   _early       — clock-out before shift end
     *   _overtime    — clock-out after shift end (overtime counted)
     *   _synthetic   — no real final clock-out; auto-closed at shift end
     *   _break       — at least one break segment present
     *   _unscheduled — at least one unscheduled-leave segment present
     */
    private function determineScenario(array $r): string
    {
        $in = $r['check_in'];
        $out = $r['check_out'];
        $synthetic = $r['check_out_synthetic'];
        $late = $r['late_checkin'];
        $early = $r['early_checkout'];
        $ot = $r['overtime_hours'] > 0;
        $segments = collect($r['segments']);

        $hasBreak = $segments->where('type', 'break')->count() > 0;
        $hasUnscheduled = $segments->where('type', 'unscheduled_leave')->count() > 0;

        // ── No check-in at all ────────────────────────────────────────────────
        if (!$in) return 'no_checkin';

        // ── Checked in, no check-out data yet ────────────────────────────────
        if (!$out) return 'checkin_only';

        // ── Auto-closed (last punch was an IN) ───────────────────────────────
        if ($synthetic) {
            if ($hasBreak && $hasUnscheduled) return 'synthetic_break_unscheduled';
            if ($hasBreak) return 'synthetic_break';
            if ($hasUnscheduled) return 'synthetic_unscheduled';
            return 'synthetic';     // simplest case: in → synthetic out
        }

        // ── Real checkout ─────────────────────────────────────────────────────
        // Build suffix tokens then combine.
        $tokens = [];
        if ($late) $tokens[] = 'late';
        if ($hasBreak) $tokens[] = 'break';
        if ($hasUnscheduled) $tokens[] = 'unscheduled';
        if ($early) $tokens[] = 'early';
        if ($ot) $tokens[] = 'overtime';

        // Base
        $base = 'complete';

        return empty($tokens) ? $base : $base . '_' . implode('_', $tokens);
        // Examples:
        //   complete
        //   complete_late
        //   complete_break
        //   complete_late_break_early
        //   complete_break_overtime
        //   complete_late_unscheduled_overtime
    }

    /**
     * A record is incomplete when we cannot calculate reliable worked hours
     * because we don't have a definitive clock-out or the day has not yet ended.
     */
    private function isIncomplete(array $r): bool
    {
        return in_array($r['scenario'], [
            'no_punches',
            'no_checkin',
            'checkin_only',
            'no_shift',
            'not_scheduled',
            'synthetic',
            'synthetic_break',
            'synthetic_unscheduled',
            'synthetic_break_unscheduled',
            'unknown',
        ]);
    }
}
