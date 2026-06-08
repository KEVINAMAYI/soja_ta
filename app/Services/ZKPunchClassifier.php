<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Employee;

class ZKPunchClassifier
{
    const NOISE_GAP_MINUTES = 1;
    const CHECKOUT_EARLY_WINDOW = 60;
    const BREAK_TOLERANCE = 20;
    const NIGHT_SHIFT_START_HOUR = 16;   // 16:00+ = night punch
    const DAY_SHIFT_START_HOUR = 6;    // before 06:00 = still night (early morning)
    const OVERNIGHT_BOUNDARY_HOUR = 6;   // punches before 06:00 belong to previous day

    public function classify(array $rawPunches, Employee $employee, ?string $date = null): array
    {
        $today = $date ?? now()->toDateString();
        $result = $this->emptyResult($rawPunches);

        if (empty($rawPunches)) {
            $result['notes'][] = 'No punches recorded.';
            return $result;
        }

        $filtered = $this->filterNoise(array_map(fn($t) => Carbon::parse($t), $rawPunches));
        $result['filtered_count'] = count($filtered);

        if (empty($filtered)) {
            $result['notes'][] = 'All punches filtered as noise.';
            return $result;
        }

        $dow = Carbon::parse($today)->dayOfWeek;
        $isSaturday = $dow === Carbon::SATURDAY;
        $isSunday = $dow === Carbon::SUNDAY;
        $isWeekend = $isSaturday || $isSunday;

        $shift = $this->resolveShift($employee, $filtered[0], $today);

        if (!$shift) {
            $result['scenario'] = 'no_shift';
            $result['notes'][] = 'No matching shift found.';
            $result['check_in'] = $filtered[0];
            if (count($filtered) >= 2) $result['check_out'] = end($filtered);
            return $result;
        }

        $employee->setRelation('shift', $shift);
        $result['notes'][] = "Resolved shift: {$shift->name}";

        if ($isWeekend) {
            return $this->classifyWeekendOt($filtered, $shift, $today, $isSaturday, $result);
        }

        if (!$shift->isScheduledOn($today)) {
            // A punch before OVERNIGHT_BOUNDARY_HOUR (06:00) on an unscheduled day
            // is an overnight clock-OUT from a night shift that started yesterday.
            // A punch >= NIGHT_SHIFT_START_HOUR (16:00) is a clock-IN on an unscheduled day
            // for a night shift that ends tomorrow — still record so overnight redirect finds it.
            $punchHour = (int)$filtered[0]->format('H');
            $isNightPunch = $punchHour >= self::NIGHT_SHIFT_START_HOUR
                || $punchHour < self::OVERNIGHT_BOUNDARY_HOUR;

            if ($isNightPunch && $shift->shift_type === 'night') {
                // Only exit early if it is a single punch (waiting for the overnight merge later)
                if (count($filtered) === 1) {
                    $result['check_in'] = $filtered[0];
                    $result['within_grace_period'] = true;
                    $result['incomplete'] = true;
                    $result['scenario'] = 'checkin_only';
                    $result['notes'][] = 'Night shift punch on unscheduled day — recording for overnight tracking.';
                    return $result;
                }

                // If they have multiple punches on an unscheduled day, let the code continue down
                // to process their actual check-out!
                $result['notes'][] = 'Night shift punch on unscheduled day with multiple punches — processing full shift.';
            }

            $result['scenario'] = 'not_scheduled';
            $result['notes'][] = 'Not scheduled today.';
            return $result;
        }

        if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][] = "Employee is {$employee->shift_status}.";
            $result['check_in'] = $filtered[0];
            return $result;
        }

        $shiftStart = $shift->getEffectiveStartTime($today);
        $shiftEnd = $shift->getEffectiveEndTime($today);
        $onTimeDeadline = $shift->getGraceDeadline($today);
        $isExtended = $shift->shift_type === 'extended';

        $maxOvertimeMinutes = ($shift->max_overtime_hours ?? 0) * 60;
        $earlyThreshold = $shift->early_checkout_threshold_minutes ?? 0;
        $checkOutStart = $shiftEnd->copy()->subMinutes(self::CHECKOUT_EARLY_WINDOW + $earlyThreshold);

// Force the checkout window to stay open all the way until 6:00 AM for night shifts
        if ($shift->shift_type === 'night') {
            $checkOutEnd = Carbon::parse($today)->addDay()->startOfDay()->addHours(self::OVERNIGHT_BOUNDARY_HOUR);
        } else {
            $checkOutEnd = $shiftEnd->copy()->addMinutes($maxOvertimeMinutes + 30);
        }
        // ── SINGLE PUNCH ──────────────────────────────────────────────────────
        if (count($filtered) === 1) {
            return $this->classifySinglePunch(
                $filtered[0], $shiftStart, $shiftEnd,
                $onTimeDeadline, $checkOutStart, $checkOutEnd, $isExtended, $result
            );
        }

        // ── MULTIPLE PUNCHES ──────────────────────────────────────────────────
        $result['check_in'] = $filtered[0];

        if ($isExtended && $filtered[0]->lt($shiftStart)) {
            $result['notes'][] = "Extended shift: early clock-in at {$filtered[0]->format('H:i')}. No penalty.";
        } elseif ($filtered[0]->gt($onTimeDeadline)) {
            $minutesLate = (int)$shiftStart->diffInMinutes($filtered[0]);
            $result['late_checkin'] = true;
            $result['minutes_late'] = $minutesLate;
            $result['late_checkin_lost_minutes'] = $minutesLate;
            $result['notes'][] = "Late clock-in at {$filtered[0]->format('H:i')} — {$minutesLate} min late from {$shiftStart->format('H:i')}. Full lateness = lost hours.";
        } else {
            $result['within_grace_period'] = true;
            $result['notes'][] = "Clock-in at {$filtered[0]->format('H:i')} — on time.";
        }

        $shiftBreaks = $shift->breaks ?? collect();
        $segments = [];
        $awaitingIn = false;
        $currentOut = null;
        $breakLost = 0;

        for ($i = 1; $i < count($filtered); $i++) {
            $punch = $filtered[$i];
            $isLast = ($i === count($filtered) - 1);

            if (!$awaitingIn) {
                $currentOut = $punch;
                $awaitingIn = true;
                if ($isLast) {
                    $type = $this->classifyOut($punch, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);
                    $segments[] = [
                        'out' => $punch,
                        'in' => null,
                        'type' => $type,
                        'duration_minutes' => null,
                        'paid' => $type === 'break' ? $this->isBreakPaid($punch, $shiftBreaks, $today) : null,
                        'missed_punch' => false
                    ];

                    // Fix: Explicitly ensure a valid checkout updates the main result object status
                    if ($type === 'checkout') {
                        $result['check_out'] = $punch;
                        $awaitingIn = false; // Close the state safely
                    }

                    $result['notes'][] = "Clock-out at {$punch->format('H:i')} [{$type}].";
                } else {
                    $result['notes'][] = "Left at {$punch->format('H:i')}.";
                }
            } else {
                $outType = $this->classifyOut($currentOut, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);
                $awaitingIn = false;
                $durationMins = (int)$currentOut->diffInMinutes($punch);
                $isPaid = $outType === 'break' ? $this->isBreakPaid($currentOut, $shiftBreaks, $today) : null;

                if ($isLast && $outType === 'break' && $punch->between($checkOutStart, $checkOutEnd)) {
                    $segments[] = ['out' => $currentOut, 'in' => null, 'type' => 'break',
                        'duration_minutes' => null, 'paid' => $isPaid, 'missed_punch' => true];
                    $result['check_out'] = $punch;
                    $result['missed_break_return'] = true;
                    $result['notes'][] = "Missed punch — break at {$currentOut->format('H:i')}, out at {$punch->format('H:i')}.";
                    break;
                }

                if ($outType === 'break') {
                    $maxDur = $this->getMaxBreakDuration($currentOut, $shiftBreaks, $today);
                    if ($durationMins > $maxDur) {
                        $excess = $durationMins - $maxDur;
                        $breakLost += $excess;
                        $result['notes'][] = "Late break return — {$durationMins} min, max {$maxDur}. Lost: {$excess} min.";
                    }
                }

                $segments[] = ['out' => $currentOut, 'in' => $punch, 'type' => $outType,
                    'duration_minutes' => $durationMins, 'paid' => $isPaid, 'missed_punch' => false];
                $result['notes'][] = "Returned at {$punch->format('H:i')} after {$durationMins} min ({$outType}).";
                if ($isLast) $result['notes'][] = 'Last punch is return — no final clock-out.';
                $currentOut = null;
            }
        }

        $result['segments'] = $segments;

        // No clock-out — flag only, NO synthetic fill
        if ($result['check_out'] === null && !$result['missed_break_return']) {
            if (now()->gte($shiftEnd)) {
                $result['missed_checkout_punch'] = true;
                $result['incomplete'] = true;
                $result['notes'][] = "⚠ Clock-OUT missing — flagged for HR review.";
            } else {
                $result['notes'][] = "Record open — shift ends at {$shiftEnd->format('H:i')}.";
            }
        }

        if (!$isExtended && $result['check_out'] && $result['check_out']->lt($shiftEnd)) {
            $minutesEarly = (int)$result['check_out']->diffInMinutes($shiftEnd);
            $result['early_checkout'] = true;
            $result['minutes_early'] = $minutesEarly;
            $result['notes'][] = "Early clock-out — {$minutesEarly} min before {$shiftEnd->format('H:i')}.";
        }

        if ($result['check_out'] && $result['check_out']->gt($shiftEnd) && ($shift->overtime_enabled ?? false)) {
            $otMinutes = (int)$shiftEnd->diffInMinutes($result['check_out']);
            $maxOtMins = ($shift->max_overtime_hours ?? 0) * 60;
            $cappedMins = $maxOtMins > 0 ? min($otMinutes, $maxOtMins) : $otMinutes;
            $result['overtime_hours'] = round($cappedMins / 60, 2);
            $result['notes'][] = "Weekday OT: {$result['overtime_hours']}h.";
        }

        $workedMinutes = 0;
        $arrivedAt = $result['check_in'];
        foreach ($segments as $seg) {
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

        $breakCount = collect($segments)->where('type', 'break')->count();
        $totalShiftMin = $shiftStart->diffInMinutes($shiftEnd);
        if ($breakCount === 0 && $workedMinutes >= ($totalShiftMin * 0.6)) {
            $result['missed_break_return'] = true;
            $result['break_enforced'] = false;
            $result['notes'][] = "No break punches for full shift — flagged. No deduction.";
        }

        $result['worked_hours'] = round(max(0, $workedMinutes) / 60, 2);
        $result['break_lost_minutes'] = $breakLost;
        $result['lost_minutes'] = $result['late_checkin_lost_minutes'] + $breakLost;
        $result['notes'][] = "Worked hours: {$result['worked_hours']}h.";

        $breakdown = [];
        if ($result['late_checkin_lost_minutes'] > 0) $breakdown[] = "Late check-in: {$result['late_checkin_lost_minutes']} min";
        if ($breakLost > 0) $breakdown[] = "Break overstay: {$breakLost} min";
        if ($result['missed_break_return']) $breakdown[] = "Missed punch flagged (no deduction)";
        $result['lost_hours_breakdown'] = $breakdown;

        if ($result['lost_minutes'] > 0) $result['notes'][] = "Total lost: {$result['lost_minutes']} min.";

        $result['scenario'] = $this->determineScenario($result);
        $result['incomplete'] = $this->isIncomplete($result);

        return $result;
    }

    // =========================================================================
    // SINGLE PUNCH — clock-IN or clock-OUT?
    // =========================================================================
    private function classifySinglePunch(
        Carbon $punch, Carbon $shiftStart, Carbon $shiftEnd,
        Carbon $onTimeDeadline, Carbon $checkOutStart, Carbon $checkOutEnd,
        bool   $isExtended, array $result
    ): array
    {
        // Punch in checkout window → clock-OUT, clock-IN missing
        if ($punch->between($checkOutStart, $checkOutEnd) || $punch->gte($shiftEnd)) {
            $result['check_in'] = null;
            $result['check_out'] = $punch;
            $result['missed_checkin_punch'] = true;
            $result['incomplete'] = true;
            $result['scenario'] = 'missed_clockin';
            $result['notes'][] = "Single punch at {$punch->format('H:i')} — clock-OUT recorded. Clock-IN missing. Flagged for HR review.";
            return $result;
        }

        // Otherwise → clock-IN, clock-OUT missing
        $result['check_in'] = $punch;
        $result['check_out'] = null;
        $result['missed_checkout_punch'] = true;
        $result['incomplete'] = true;
        $result['scenario'] = 'missed_clockout';

        if (!$isExtended && $punch->gt($onTimeDeadline)) {
            $minutesLate = (int)$shiftStart->diffInMinutes($punch);
            $result['late_checkin'] = true;
            $result['minutes_late'] = $minutesLate;
            $result['late_checkin_lost_minutes'] = $minutesLate;
            $result['notes'][] = "Late clock-in at {$punch->format('H:i')} — {$minutesLate} min late.";
        } else {
            $result['within_grace_period'] = true;
        }

        $result['notes'][] = "Single punch at {$punch->format('H:i')} — clock-IN recorded. Clock-OUT missing. Flagged for HR review.";
        return $result;
    }

    // =========================================================================
    // Weekend OT
    // =========================================================================
    private function classifyWeekendOt(array $filtered, object $shift, string $today, bool $isSaturday, array $result): array
    {
        $result['check_in'] = $filtered[0];
        $result['check_out'] = count($filtered) > 1 ? end($filtered) : null;
        $result['within_grace_period'] = true;
        $result['notes'][] = $isSaturday ? "Saturday — all hours are OT1." : "Sunday — all hours are OT2.";

        if (count($filtered) < 2) {
            $result['missed_checkout_punch'] = true;
            $result['incomplete'] = true;
            $result['scenario'] = $isSaturday ? 'missed_clockout_ot1' : 'missed_clockout_ot2';
            $result['notes'][] = "Single punch — clock-OUT missing. Flagged for HR review.";
            return $result;
        }

        $workedMinutes = (int)$filtered[0]->diffInMinutes(end($filtered));
        if (count($filtered) > 2) {
            $middle = array_slice($filtered, 1, -1);
            for ($i = 0; $i + 1 < count($middle); $i += 2) {
                $breakMins = (int)$middle[$i]->diffInMinutes($middle[$i + 1]);
                if ($breakMins < 120) {
                    $workedMinutes -= $breakMins;
                    $result['notes'][] = "Break deducted: {$breakMins} min.";
                }
            }
        }

        $otHours = round(max(0, $workedMinutes) / 60, 2);
        if ($isSaturday) {
            $result['ot1_hours'] = $otHours;
        } else {
            $result['ot2_hours'] = $otHours;
        }
        $result['worked_hours'] = $otHours;
        $result['notes'][] = "Total OT hours: {$otHours}h.";
        $result['incomplete'] = false;
        $result['scenario'] = $isSaturday ? 'complete_ot1' : 'complete_ot2';
        return $result;
    }

    // =========================================================================
    // Shift resolver
    // =========================================================================
    public function resolveShift(Employee $employee, Carbon $firstPunch, string $date): ?object
    {
        $punchHour = (int)$firstPunch->format('H');
        // A punch is a "night punch" if it's in the evening (>= 16:00)
        // OR in the early morning before 06:00 (overnight clock-out)
        $isNightPunch = $punchHour >= self::NIGHT_SHIFT_START_HOUR
            || $punchHour < self::DAY_SHIFT_START_HOUR;

        $assignedShifts = $employee->shifts()->with('breaks')->get();

        if ($assignedShifts->isNotEmpty()) {
            if ($assignedShifts->count() === 1) return $assignedShifts->first();
            $targetType = $isNightPunch ? 'night' : 'day';
            $shift = $assignedShifts->firstWhere('shift_type', $targetType);
            if ($shift) return $shift;
            return $assignedShifts->firstWhere('pivot.is_primary', true) ?? $assignedShifts->first();
        }

        return $employee->shift?->load('breaks');
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function emptyResult(array $rawPunches): array
    {
        return [
            'check_in' => null,
            'check_out' => null,
            'check_out_synthetic' => false,
            'late_checkin' => false,
            'early_checkout' => false,
            'within_grace_period' => false,
            'minutes_late' => 0,
            'minutes_early' => 0,
            'segments' => [],
            'worked_hours' => 0.0,
            'overtime_hours' => 0.0,
            'ot1_hours' => 0.0,
            'ot2_hours' => 0.0,
            'lost_minutes' => 0,
            'late_checkin_lost_minutes' => 0,
            'break_lost_minutes' => 0,
            'enforced_break_minutes' => 0,
            'break_enforced' => false,
            'missed_break_return' => false,
            'missed_checkin_punch' => false,
            'missed_checkout_punch' => false,
            'lost_hours_breakdown' => [],
            'scenario' => 'no_punches',
            'incomplete' => true,
            'raw_count' => count($rawPunches),
            'filtered_count' => 0,
            'notes' => [],
            'punches' => $rawPunches,
        ];
    }

    private function classifyOut(Carbon $outPunch, $shiftBreaks, Carbon $checkOutStart, Carbon $checkOutEnd, string $today): string
    {
        if ($outPunch->between($checkOutStart, $checkOutEnd)) return 'checkout';
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse($today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s'));
            if ($outPunch->between($windowStart->copy()->subMinutes(self::BREAK_TOLERANCE), $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)))
                return 'break';
        }
        return 'unscheduled_leave';
    }

    private function getMaxBreakDuration(Carbon $outPunch, $shiftBreaks, string $today): int
    {
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse($today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s'));
            if ($outPunch->between($windowStart->copy()->subMinutes(self::BREAK_TOLERANCE), $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)))
                return $break->max_duration_minutes ?? $break->duration_minutes ?? 60;
        }
        return 60;
    }

    private function isBreakPaid(Carbon $outPunch, $shiftBreaks, string $today): bool
    {
        foreach ($shiftBreaks as $break) {
            if (!$break->is_active || !$break->window_start_time) continue;
            $windowStart = Carbon::parse($today . ' ' . Carbon::parse($break->window_start_time)->format('H:i:s'));
            if ($outPunch->between($windowStart->copy()->subMinutes(self::BREAK_TOLERANCE), $windowStart->copy()->addMinutes(self::BREAK_TOLERANCE)))
                return ($break->type ?? 'unpaid') === 'paid';
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

    private function determineScenario(array $r): string
    {
        if ($r['missed_checkin_punch']) return 'missed_clockin';
        if ($r['missed_checkout_punch']) return 'missed_clockout';
        if (!$r['check_in']) return 'no_checkin';
        if (!$r['check_out']) return 'checkin_only';
        $segments = collect($r['segments']);
        $hasBreak = $segments->where('type', 'break')->count() > 0;
        $hasUnscheduled = $segments->where('type', 'unscheduled_leave')->count() > 0;
        $tokens = [];
        if ($r['late_checkin']) $tokens[] = 'late';
        if ($hasBreak) $tokens[] = 'break';
        if ($r['missed_break_return']) $tokens[] = 'missed_punch';
        if ($hasUnscheduled) $tokens[] = 'unscheduled';
        if ($r['early_checkout']) $tokens[] = 'early';
        if ($r['overtime_hours'] > 0) $tokens[] = 'overtime';
        if ($r['ot1_hours'] > 0) $tokens[] = 'ot1';
        if ($r['ot2_hours'] > 0) $tokens[] = 'ot2';
        return empty($tokens) ? 'complete' : 'complete_' . implode('_', $tokens);
    }

    private function isIncomplete(array $r): bool
    {
        return in_array($r['scenario'], [
            'no_punches', 'no_checkin', 'checkin_only', 'no_shift', 'not_scheduled',
            'missed_clockin', 'missed_clockout', 'missed_clockout_ot1', 'missed_clockout_ot2', 'unknown',
        ]);
    }
}
