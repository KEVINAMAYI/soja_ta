<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Employee;

/**
 * STEP 4 — ZKPunchClassifier
 *
 * Copy to: app/Services/ZKPunchClassifier.php  (full replacement)
 *
 * Changes from original:
 *  1. Uses $shift->getEffectiveStartTime($date)  — handles date anchoring
 *  2. Uses $shift->getEffectiveEndTime($date)    — handles Friday variant + overnight
 *  3. Uses $shift->getGraceDeadline($date)       — clean grace period deadline
 *  4. Uses $shift->getOvertimeTier($date)        — returns OT1 / OT2 / Weekday OT
 *  5. Uses $shift->isScheduledOn($date)          — pattern-aware schedule check
 *  6. Uses $shift->isOvernightShift()            — overnight flag check
 *  7. Extended shift: early clock-in is NOT penalised
 *  8. Missed punch: flag ONLY — break_end_time stays null, never overridden
 *  9. Lost hours: full minutes from shift START (not from grace end)
 * 10. OT split: ot1_hours (Sat) / ot2_hours (Sun) / overtime_hours (weekday)
 */
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

    // =========================================================================
    // Main entry point
    // =========================================================================

    public function classify(array $rawPunches, Employee $employee, ?string $date = null): array
    {
        $result = [
            'check_in'                  => null,
            'check_out'                 => null,
            'check_out_synthetic'       => false,
            'late_checkin'              => false,
            'early_checkout'            => false,
            'within_grace_period'       => false,
            'minutes_late'              => 0,
            'minutes_early'             => 0,
            'segments'                  => [],
            'worked_hours'              => 0.0,
            'overtime_hours'            => 0.0,   // weekday OT (e.g. Friday past shift end)
            'ot1_hours'                 => 0.0,   // Saturday OT1
            'ot2_hours'                 => 0.0,   // Sunday OT2
            'lost_minutes'              => 0,
            'late_checkin_lost_minutes' => 0,
            'break_lost_minutes'        => 0,
            'enforced_break_minutes'    => 0,
            'break_enforced'            => false,
            'missed_break_return'       => false,
            'lost_hours_breakdown'      => [],
            'scenario'                  => 'no_punches',
            'incomplete'                => true,
            'raw_count'                 => count($rawPunches),
            'filtered_count'            => 0,
            'notes'                     => [],
            'punches'                   => $rawPunches,
        ];

        $today = $date ?? now()->toDateString();

        // ── No punches ────────────────────────────────────────────────────────
        if (empty($rawPunches)) {
            $result['notes'][] = 'No punches recorded for this day.';
            return $result;
        }

        // ── No shift assigned ─────────────────────────────────────────────────
        $shift = $employee->shift;
        if (!$shift) {
            $result['scenario'] = 'no_shift';
            $result['notes'][]  = 'No shift assigned — best-guess classification only.';
            $times = $this->filterNoise(
                array_map(fn($t) => Carbon::parse($t), $rawPunches)
            );
            $result['filtered_count'] = count($times);
            if (count($times) >= 1) $result['check_in']  = $times[0];
            if (count($times) >= 2) $result['check_out'] = end($times);
            return $result;
        }

        // ── Not scheduled today ───────────────────────────────────────────────
        // Weekend punches are still processed — they are OT days
        $dow        = Carbon::parse($today)->dayOfWeek;
        $isSaturday = $dow === Carbon::SATURDAY;
        $isSunday   = $dow === Carbon::SUNDAY;
        $isWeekend  = $isSaturday || $isSunday;

        if (!$shift->isScheduledOn($today) && !$isWeekend) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][]  = 'Not scheduled today (' . Carbon::parse($today)->format('l') . '). Punches ignored.';
            return $result;
        }

        // ── Employee off shift / sick / on leave ──────────────────────────────
        if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][]  = "Employee is {$employee->shift_status}. Punches recorded but not processed.";
            $result['check_in'] = Carbon::parse($rawPunches[0]);
            return $result;
        }

        // ── Shift boundaries via Shift model helpers ──────────────────────────
        // getEffectiveStartTime — date-anchored start
        // getEffectiveEndTime   — handles Friday variant + overnight addDay()
        // getGraceDeadline      — start + grace_period_minutes
        $shiftStart     = $shift->getEffectiveStartTime($today);
        $shiftEnd       = $shift->getEffectiveEndTime($today);
        $onTimeDeadline = $shift->getGraceDeadline($today);
        $isExtended     = $shift->shift_type === 'extended';

        $maxOvertimeMinutes = ($shift->max_overtime_hours ?? 0) * 60;
        $earlyThreshold     = $shift->early_checkout_threshold_minutes ?? 0;
        $checkOutStart      = $shiftEnd->copy()->subMinutes(self::CHECKOUT_EARLY_WINDOW + $earlyThreshold);
        $checkOutEnd        = $shiftEnd->copy()->addMinutes($maxOvertimeMinutes + 30);

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

        if ($isExtended && $filtered[0]->lt($shiftStart)) {
            // Extended shift: early clock-in is expected — not a late/lost issue
            $minsEarly = (int) $filtered[0]->diffInMinutes($shiftStart);
            $result['notes'][] = "Extended shift: clocked in {$minsEarly} min early at "
                . "{$filtered[0]->format('H:i')} (anchor: {$shiftStart->format('H:i')}). No penalty.";

        } elseif ($filtered[0]->gt($onTimeDeadline)) {
            // Late clock-in — lost hours = full minutes from shift START
            $minutesLate = (int) $shiftStart->diffInMinutes($filtered[0]);
            $result['late_checkin']              = true;
            $result['minutes_late']              = $minutesLate;
            $result['late_checkin_lost_minutes'] = $minutesLate;
            $result['notes'][] = "Late clock-in at {$filtered[0]->format('H:i')} "
                . "— {$minutesLate} min late from shift start {$shiftStart->format('H:i')}. "
                . "Full lateness counted as lost hours.";

        } else {
            $result['within_grace_period'] = true;
            $result['notes'][] = "Clock-in at {$filtered[0]->format('H:i')} — on time"
                . ($filtered[0]->gt($shiftStart) ? ' (within grace period).' : '.');
        }

        if (count($filtered) === 1) {
            $result['scenario']  = 'checkin_only';
            $result['incomplete']= true;
            $result['notes'][]   = 'Only one punch — employee has not clocked out yet.';
            return $result;
        }

        // ── Walk punches 2–N in strict out/in alternation ─────────────────────
        $shiftBreaks = $shift->breaks ?? collect();
        $segments    = [];
        $awaitingIn  = false;
        $currentOut  = null;
        $breakLost   = 0;

        for ($i = 1; $i < count($filtered); $i++) {
            $punch  = $filtered[$i];
            $isLast = ($i === count($filtered) - 1);

            if (!$awaitingIn) {

                // ── OUT punch ─────────────────────────────────────────────────
                $currentOut = $punch;
                $awaitingIn = true;

                if ($isLast) {
                    // Final punch is an out — classify it
                    $type = $this->classifyOut(
                        $punch, $shiftBreaks, $checkOutStart, $checkOutEnd, $today
                    );
                    $segments[] = [
                        'out'              => $punch,
                        'in'               => null,
                        'type'             => $type,
                        'duration_minutes' => null,
                        'paid'             => $type === 'break'
                            ? $this->isBreakPaid($punch, $shiftBreaks, $today)
                            : null,
                        'missed_punch'     => false,
                    ];
                    $result['check_out'] = $punch;
                    $result['notes'][]   = "Clock-out at {$punch->format('H:i')} [{$type}].";
                } else {
                    $result['notes'][] = "Left at {$punch->format('H:i')}.";
                }

            } else {

                // ── IN punch ──────────────────────────────────────────────────
                $outType      = $this->classifyOut(
                    $currentOut, $shiftBreaks, $checkOutStart, $checkOutEnd, $today
                );
                $awaitingIn   = false;
                $durationMins = (int) $currentOut->diffInMinutes($punch);
                $isPaid       = $outType === 'break'
                    ? $this->isBreakPaid($currentOut, $shiftBreaks, $today)
                    : null;

                // ── MISSED PUNCH SCENARIO ─────────────────────────────────────
                // Employee went on break; next punch is in the checkout window.
                // FLAG ONLY — break_end_time stays null, NEVER overridden.
                if ($isLast
                    && $outType === 'break'
                    && $punch->between($checkOutStart, $checkOutEnd)
                ) {
                    $segments[] = [
                        'out'              => $currentOut,
                        'in'               => null,   // NOT overridden
                        'type'             => 'break',
                        'duration_minutes' => null,   // unknown — not assumed
                        'paid'             => $isPaid,
                        'missed_punch'     => true,   // FLAG ONLY
                    ];
                    $result['check_out']          = $punch;
                    $result['missed_break_return']= true;
                    $result['notes'][] = "⚠ Missed break return punch — went for break at "
                        . "{$currentOut->format('H:i')}, clocked out at {$punch->format('H:i')}. "
                        . "NOT overridden. Flagged only.";
                    break;
                }

                // ── Late return from break → lost hours beyond max_duration ───
                if ($outType === 'break') {
                    $maxDuration = $this->getMaxBreakDuration(
                        $currentOut, $shiftBreaks, $today
                    );
                    if ($durationMins > $maxDuration) {
                        $excess     = $durationMins - $maxDuration;
                        $breakLost += $excess;
                        $result['notes'][] = "Late break return — took {$durationMins} min, "
                            . "max {$maxDuration} min. Lost: {$excess} min.";
                    } else {
                        $result['notes'][] = "Break return on time — {$durationMins} min "
                            . "(max {$maxDuration} min).";
                    }
                }

                $segments[] = [
                    'out'              => $currentOut,
                    'in'               => $punch,
                    'type'             => $outType,
                    'duration_minutes' => $durationMins,
                    'paid'             => $isPaid,
                    'missed_punch'     => false,
                ];

                $result['notes'][] = "Returned at {$punch->format('H:i')} "
                    . "after {$durationMins} min ({$outType}).";

                if ($isLast) {
                    $result['notes'][] = 'Last punch is a return — no final clock-out recorded.';
                }

                $currentOut = null;
            }
        }

        $result['segments'] = $segments;

        // ── Synthetic checkout ────────────────────────────────────────────────
        // Only insert when shift has fully ended AND there is genuinely no punch.
        // NEVER synthesise for missed punch scenarios.
        if ($result['check_out'] === null && !$result['missed_break_return']) {
            if (now()->gte($shiftEnd)) {
                $result['check_out']           = $shiftEnd->copy();
                $result['check_out_synthetic'] = true;
                $result['notes'][] = "No final clock-out. Auto-inserted at shift end "
                    . "{$shiftEnd->format('H:i')}.";
            } else {
                $result['notes'][] = "No clock-out yet — record left open until shift ends "
                    . "at {$shiftEnd->format('H:i')}.";
            }
        }

        // ── Early checkout ────────────────────────────────────────────────────
        // Extended shift: late clock-out is expected, so skip this check.
        if (!$isExtended
            && !$result['check_out_synthetic']
            && $result['check_out']
            && $result['check_out']->lt($shiftEnd)
        ) {
            $minutesEarly            = (int) $result['check_out']->diffInMinutes($shiftEnd);
            $result['early_checkout']= true;
            $result['minutes_early'] = $minutesEarly;
            $result['notes'][] = "Early clock-out — {$minutesEarly} min before "
                . "shift end {$shiftEnd->format('H:i')}.";
        }

        // ── Overtime — split into tiers via Shift model helper ────────────────
        if (!$result['check_out_synthetic']
            && $result['check_out']
            && $result['check_out']->gt($shiftEnd)
        ) {
            $otMinutes  = (int) $shiftEnd->diffInMinutes($result['check_out']);
            $maxOtMins  = ($shift->max_overtime_hours ?? 0) * 60;
            $cappedMins = $maxOtMins > 0 ? min($otMinutes, $maxOtMins) : $otMinutes;
            $otHours    = round($cappedMins / 60, 2);
            $tier       = $shift->getOvertimeTier($today);

            if ($tier === 'OT2') {
                // Sunday — all hours worked are OT2
                $result['ot2_hours'] = $otHours;
                $result['notes'][]   = "Sunday work — OT2: {$otHours}h.";

            } elseif ($tier === 'OT1') {
                // Saturday — hours until 16:30 are OT1
                $result['ot1_hours'] = $otHours;
                $result['notes'][]   = "Saturday work — OT1: {$otHours}h.";

            } elseif ($tier === 'Weekday OT' && ($shift->overtime_enabled ?? false)) {
                // Weekday OT — e.g. Friday past shift end
                $result['overtime_hours'] = $otHours;
                $result['notes'][]        = "Weekday OT: {$otHours}h after "
                    . "shift end {$shiftEnd->format('H:i')}.";
            }
        }

        // ── Worked hours ──────────────────────────────────────────────────────
        if ($result['check_in']) {
            $workedMinutes = 0;
            $arrivedAt     = $result['check_in'];

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

            // No break punches detected for a full shift →
            // flag as missed punch ONLY — no deduction applied
            $breakCount     = collect($segments)->where('type', 'break')->count();
            $totalShiftMins = $shiftStart->diffInMinutes($shiftEnd);
            if ($breakCount === 0 && $workedMinutes >= ($totalShiftMins * 0.6)) {
                $result['missed_break_return'] = true;
                $result['break_enforced']      = false;
                $result['notes'][] = "No break punches for full shift — flagged as missed punch. "
                    . "No hours deducted.";
            }

            $result['worked_hours'] = round(max(0, $workedMinutes) / 60, 2);
            $result['notes'][]      = "Worked hours: {$result['worked_hours']}h.";
        }

        // ── Lost hours summary ────────────────────────────────────────────────
        $result['break_lost_minutes'] = $breakLost;
        $totalLost                    = $result['late_checkin_lost_minutes'] + $breakLost;
        $result['lost_minutes']       = $totalLost;

        $breakdown = [];
        if ($result['late_checkin_lost_minutes'] > 0) {
            $breakdown[] = "Late check-in: {$result['late_checkin_lost_minutes']} min "
                . "from shift start {$shiftStart->format('H:i')}";
        }
        if ($breakLost > 0) {
            $breakdown[] = "Break overstay: {$breakLost} min beyond max duration";
        }
        if ($result['missed_break_return']) {
            $breakdown[] = "Missed punch flagged (no deduction applied)";
        }
        $result['lost_hours_breakdown'] = $breakdown;

        if ($totalLost > 0) {
            $result['notes'][] = "Total lost: {$totalLost} min. "
                . implode(' | ', array_filter(
                    $breakdown,
                    fn($b) => !str_contains($b, 'Missed punch')
                ));
        }

        // ── Scenario + incomplete ─────────────────────────────────────────────
        $result['scenario']   = $this->determineScenario($result);
        $result['incomplete'] = $this->isIncomplete($result);

        return $result;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Classify an outgoing punch as: checkout | break | unscheduled_leave
     */
    private function classifyOut(
        Carbon $outPunch,
               $shiftBreaks,
        Carbon $checkOutStart,
        Carbon $checkOutEnd,
        string $today
    ): string {
        // In the checkout window → it's a clock-out
        if ($outPunch->between($checkOutStart, $checkOutEnd)) {
            return 'checkout';
        }

        // Within a break window → it's a break
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

        // Neither → unscheduled leave
        return 'unscheduled_leave';
    }

    /**
     * Get the max allowed break duration before lost hours kick in.
     * Uses max_duration_minutes → falls back to duration_minutes → default 60.
     */
    private function getMaxBreakDuration(
        Carbon $outPunch,
               $shiftBreaks,
        string $today
    ): int {
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
     * Is the break at this time a paid break?
     */
    private function isBreakPaid(
        Carbon $outPunch,
               $shiftBreaks,
        string $today
    ): bool {
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

    /**
     * Remove duplicate/noise punches.
     * Keeps the FIRST punch in any cluster within NOISE_GAP_MINUTES.
     */
    private function filterNoise(array $times): array
    {
        if (empty($times)) return [];
        $filtered = [$times[0]];
        $last     = $times[0]->copy();
        foreach (array_slice($times, 1) as $time) {
            if ($last->diffInSeconds($time) >= self::NOISE_GAP_MINUTES * 60) {
                $filtered[] = $time;
                $last       = $time->copy();
            }
        }
        return $filtered;
    }

    /**
     * Determine the scenario string from the classified result array.
     */
    private function determineScenario(array $r): string
    {
        if (!$r['check_in'])  return 'no_checkin';
        if (!$r['check_out']) return 'checkin_only';

        $synthetic      = $r['check_out_synthetic'];
        $segments       = collect($r['segments']);
        $hasBreak       = $segments->where('type', 'break')->count() > 0;
        $hasUnscheduled = $segments->where('type', 'unscheduled_leave')->count() > 0;
        $missedPunch    = $r['missed_break_return'];

        if ($synthetic) {
            if ($hasBreak && $hasUnscheduled) return 'synthetic_break_unscheduled';
            if ($hasBreak)                    return 'synthetic_break';
            if ($hasUnscheduled)              return 'synthetic_unscheduled';
            return 'synthetic';
        }

        $tokens = [];
        if ($r['late_checkin'])        $tokens[] = 'late';
        if ($hasBreak)                 $tokens[] = 'break';
        if ($missedPunch)              $tokens[] = 'missed_punch';
        if ($hasUnscheduled)           $tokens[] = 'unscheduled';
        if ($r['early_checkout'])      $tokens[] = 'early';
        if ($r['overtime_hours'] > 0)  $tokens[] = 'overtime';
        if ($r['ot1_hours'] > 0)       $tokens[] = 'ot1';
        if ($r['ot2_hours'] > 0)       $tokens[] = 'ot2';

        return empty($tokens) ? 'complete' : 'complete_' . implode('_', $tokens);
    }

    /**
     * Is this attendance record considered incomplete?
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
