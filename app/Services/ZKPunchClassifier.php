<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Employee;
use App\Models\Shift;

/**
 * ZKPunchClassifier — Multi-shift version
 *
 * AUTO-DETECTION:
 *   Punch 05:00–15:59 → Day shift
 *   Punch 16:00–04:59 → Night shift
 *
 * SHIFT RESOLUTION:
 *   1. Load ALL shifts assigned to employee via employee_shifts pivot
 *   2. Match punch time to correct shift (day/night)
 *   3. If employee only has day shift → always day
 *   4. If employee has both → auto-detect by punch time
 *   5. Night shift overrides day shift for that attendance record
 *
 * WEEKEND:
 *   Saturday → OT1 (all hours)
 *   Sunday   → OT2 (all hours)
 *   No late/absent/early logic on weekends
 */
class ZKPunchClassifier
{
    const NOISE_GAP_MINUTES      = 1;
    const CHECKOUT_EARLY_WINDOW  = 60;
    const BREAK_TOLERANCE        = 20;
    const NIGHT_SHIFT_START_HOUR = 16;
    const DAY_SHIFT_START_HOUR   = 6;  // 05:xx = still night shift end

    public function classify(array $rawPunches, Employee $employee, ?string $date = null): array
    {
        $today  = $date ?? now()->toDateString();
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

        $dow        = Carbon::parse($today)->dayOfWeek;
        $isSaturday = $dow === Carbon::SATURDAY;
        $isSunday   = $dow === Carbon::SUNDAY;
        $isWeekend  = $isSaturday || $isSunday;

        // Resolve correct shift from punch time
        $shift = $this->resolveShift($employee, $filtered[0], $today);

        if (!$shift) {
            $result['scenario'] = 'no_shift';
            $result['notes'][]  = 'No matching shift found.';
            if (count($filtered) >= 1) $result['check_in']  = $filtered[0];
            if (count($filtered) >= 2) $result['check_out'] = end($filtered);
            return $result;
        }

        $employee->setRelation('shift', $shift);
        $result['notes'][] = "Resolved shift: {$shift->name}";

        // Weekend = pure OT
        if ($isWeekend) {
            return $this->classifyWeekendOt($filtered, $shift, $today, $isSaturday, $result);
        }

        // Not scheduled today (weekday)
        if (!$shift->isScheduledOn($today)) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][]  = 'Not scheduled today.';
            return $result;
        }

        // Employee status
        if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
            $result['scenario'] = 'not_scheduled';
            $result['notes'][]  = "Employee is {$employee->shift_status}.";
            $result['check_in'] = $filtered[0];
            return $result;
        }

        $shiftStart     = $shift->getEffectiveStartTime($today);
        $shiftEnd       = $shift->getEffectiveEndTime($today);
        $onTimeDeadline = $shift->getGraceDeadline($today);
        $isExtended     = $shift->shift_type === 'extended';

        $maxOvertimeMinutes = ($shift->max_overtime_hours ?? 0) * 60;
        $earlyThreshold     = $shift->early_checkout_threshold_minutes ?? 0;
        $checkOutStart      = $shiftEnd->copy()->subMinutes(self::CHECKOUT_EARLY_WINDOW + $earlyThreshold);
        $checkOutEnd        = $shiftEnd->copy()->addMinutes($maxOvertimeMinutes + 30);

        // Clock-in
        $result['check_in'] = $filtered[0];

        if ($isExtended && $filtered[0]->lt($shiftStart)) {
            $result['notes'][] = "Extended shift: early clock-in at {$filtered[0]->format('H:i')}. No penalty.";
        } elseif ($filtered[0]->gt($onTimeDeadline)) {
            $minutesLate                         = (int) $shiftStart->diffInMinutes($filtered[0]);
            $result['late_checkin']              = true;
            $result['minutes_late']              = $minutesLate;
            $result['late_checkin_lost_minutes'] = $minutesLate;
            $result['notes'][] = "Late clock-in at {$filtered[0]->format('H:i')} — {$minutesLate} min late from shift start {$shiftStart->format('H:i')}. Full lateness counted as lost hours.";
        } else {
            $result['within_grace_period'] = true;
            $result['notes'][] = "Clock-in at {$filtered[0]->format('H:i')} — on time.";
        }

        if (count($filtered) === 1) {
            $result['scenario']   = 'checkin_only';
            $result['incomplete'] = true;
            $result['notes'][]    = 'Only one punch — not clocked out yet.';
            return $result;
        }

        $shiftBreaks = $shift->breaks ?? collect();
        $segments    = [];
        $awaitingIn  = false;
        $currentOut  = null;
        $breakLost   = 0;

        for ($i = 1; $i < count($filtered); $i++) {
            $punch  = $filtered[$i];
            $isLast = ($i === count($filtered) - 1);

            if (!$awaitingIn) {
                $currentOut = $punch;
                $awaitingIn = true;

                if ($isLast) {
                    $type = $this->classifyOut($punch, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);
                    $segments[] = [
                        'out'              => $punch,
                        'in'               => null,
                        'type'             => $type,
                        'duration_minutes' => null,
                        'paid'             => $type === 'break' ? $this->isBreakPaid($punch, $shiftBreaks, $today) : null,
                        'missed_punch'     => false,
                    ];
                    $result['check_out'] = $punch;
                    $result['notes'][]   = "Clock-out at {$punch->format('H:i')} [{$type}].";
                } else {
                    $result['notes'][] = "Left at {$punch->format('H:i')}.";
                }
            } else {
                $outType      = $this->classifyOut($currentOut, $shiftBreaks, $checkOutStart, $checkOutEnd, $today);
                $awaitingIn   = false;
                $durationMins = (int) $currentOut->diffInMinutes($punch);
                $isPaid       = $outType === 'break' ? $this->isBreakPaid($currentOut, $shiftBreaks, $today) : null;

                if ($isLast && $outType === 'break' && $punch->between($checkOutStart, $checkOutEnd)) {
                    $segments[] = [
                        'out'              => $currentOut,
                        'in'               => null,
                        'type'             => 'break',
                        'duration_minutes' => null,
                        'paid'             => $isPaid,
                        'missed_punch'     => true,
                    ];
                    $result['check_out']           = $punch;
                    $result['missed_break_return'] = true;
                    $result['notes'][] = "Missed punch — break at {$currentOut->format('H:i')}, out at {$punch->format('H:i')}. NOT overridden.";
                    break;
                }

                if ($outType === 'break') {
                    $maxDur = $this->getMaxBreakDuration($currentOut, $shiftBreaks, $today);
                    if ($durationMins > $maxDur) {
                        $excess     = $durationMins - $maxDur;
                        $breakLost += $excess;
                        $result['notes'][] = "Late break return — {$durationMins} min, max {$maxDur}. Lost: {$excess} min.";
                    } else {
                        $result['notes'][] = "Break return on time — {$durationMins} min.";
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

                $result['notes'][] = "Returned at {$punch->format('H:i')} after {$durationMins} min ({$outType}).";
                if ($isLast) $result['notes'][] = 'Last punch is return — no final clock-out.';
                $currentOut = null;
            }
        }

        $result['segments'] = $segments;

        if ($result['check_out'] === null && !$result['missed_break_return']) {
            if (now()->gte($shiftEnd)) {
                $result['check_out']           = $shiftEnd->copy();
                $result['check_out_synthetic'] = true;
                $result['notes'][] = "No clock-out. Auto-inserted at {$shiftEnd->format('H:i')}.";
            } else {
                $result['notes'][] = "Record left open — shift ends at {$shiftEnd->format('H:i')}.";
            }
        }

        if (!$isExtended && !$result['check_out_synthetic']
            && $result['check_out'] && $result['check_out']->lt($shiftEnd)
        ) {
            $minutesEarly             = (int) $result['check_out']->diffInMinutes($shiftEnd);
            $result['early_checkout'] = true;
            $result['minutes_early']  = $minutesEarly;
            $result['notes'][] = "Early clock-out — {$minutesEarly} min before {$shiftEnd->format('H:i')}.";
        }

        if (!$result['check_out_synthetic'] && $result['check_out']
            && $result['check_out']->gt($shiftEnd)
            && ($shift->overtime_enabled ?? false)
        ) {
            $otMinutes  = (int) $shiftEnd->diffInMinutes($result['check_out']);
            $maxOtMins  = ($shift->max_overtime_hours ?? 0) * 60;
            $cappedMins = $maxOtMins > 0 ? min($otMinutes, $maxOtMins) : $otMinutes;
            $result['overtime_hours'] = round($cappedMins / 60, 2);
            $result['notes'][] = "Weekday OT: {$result['overtime_hours']}h.";
        }

        $workedMinutes = 0;
        $arrivedAt     = $result['check_in'];
        foreach ($segments as $seg) {
            $workedMinutes += $arrivedAt->diffInMinutes($seg['out']);
            if ($seg['in'] !== null) { $arrivedAt = $seg['in']; } else { $arrivedAt = null; break; }
        }
        if ($arrivedAt !== null && $result['check_out']) {
            $workedMinutes += $arrivedAt->diffInMinutes($result['check_out']);
        }

        $breakCount    = collect($segments)->where('type', 'break')->count();
        $totalShiftMin = $shiftStart->diffInMinutes($shiftEnd);
        if ($breakCount === 0 && $workedMinutes >= ($totalShiftMin * 0.6)) {
            $result['missed_break_return'] = true;
            $result['break_enforced']      = false;
            $result['notes'][] = "No break punches for full shift — flagged as missed punch. No deduction.";
        }

        $result['worked_hours'] = round(max(0, $workedMinutes) / 60, 2);
        $result['notes'][]      = "Worked hours: {$result['worked_hours']}h.";

        $result['break_lost_minutes'] = $breakLost;
        $result['lost_minutes']       = $result['late_checkin_lost_minutes'] + $breakLost;

        $breakdown = [];
        if ($result['late_checkin_lost_minutes'] > 0) $breakdown[] = "Late check-in: {$result['late_checkin_lost_minutes']} min";
        if ($breakLost > 0)                           $breakdown[] = "Break overstay: {$breakLost} min";
        if ($result['missed_break_return'])            $breakdown[] = "Missed punch flagged (no deduction)";
        $result['lost_hours_breakdown'] = $breakdown;

        if ($result['lost_minutes'] > 0) {
            $result['notes'][] = "Total lost: {$result['lost_minutes']} min.";
        }

        $result['scenario']   = $this->determineScenario($result);
        $result['incomplete'] = $this->isIncomplete($result);

        return $result;
    }

    // =========================================================================
    // Weekend OT
    // =========================================================================

    private function classifyWeekendOt(array $filtered, object $shift, string $today, bool $isSaturday, array $result): array
    {
        $result['check_in']            = $filtered[0];
        $result['check_out']           = end($filtered);
        $result['within_grace_period'] = true;

        $result['notes'][] = $isSaturday ? "Saturday — all hours are OT1." : "Sunday — all hours are OT2.";

        if (count($filtered) < 2) {
            $result['scenario']   = 'checkin_only';
            $result['incomplete'] = true;
            $result['notes'][]    = 'Only one punch — no clock-out yet.';
            return $result;
        }

        $workedMinutes = (int) $filtered[0]->diffInMinutes(end($filtered));

        if (count($filtered) > 2) {
            $middle = array_slice($filtered, 1, -1);
            for ($i = 0; $i + 1 < count($middle); $i += 2) {
                $breakMins = (int) $middle[$i]->diffInMinutes($middle[$i + 1]);
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
        $result['notes'][]      = "Total OT hours: {$otHours}h.";
        $result['incomplete']   = false;
        $result['scenario']     = $isSaturday ? 'complete_ot1' : 'complete_ot2';

        return $result;
    }

    // =========================================================================
    // SHIFT RESOLVER — Multi-shift aware
    //
    // Priority:
    //   1. Load ALL employee shifts from pivot table
    //   2. Night punch (16:00–04:59) → find night shift for this employee
    //   3. Day punch (05:00–15:59)   → find day shift for this employee
    //   4. If employee has only one shift → use it regardless of punch time
    //   5. Fallback to employee.shift_id (legacy)
    // =========================================================================

    public function resolveShift(Employee $employee, Carbon $firstPunch, string $date): ?object
    {
        $punchHour    = (int) $firstPunch->format('H');
        $isNightPunch = $punchHour >= self::NIGHT_SHIFT_START_HOUR
            || $punchHour <  self::DAY_SHIFT_START_HOUR;

        // Load all assigned shifts from pivot table
        $assignedShifts = $employee->shifts()->with('breaks')->get();

        if ($assignedShifts->isNotEmpty()) {

            // If only one shift — use it always
            if ($assignedShifts->count() === 1) {
                return $assignedShifts->first();
            }

            // Multiple shifts — auto-detect by punch time
            $targetType = $isNightPunch ? 'night' : 'day';

            // Admin group = always day
            $shift = $assignedShifts->firstWhere('shift_type', $targetType);

            if ($shift) return $shift;

            // Fallback: return primary shift
            return $assignedShifts->firstWhere('pivot.is_primary', true)
                ?? $assignedShifts->first();
        }

        // Legacy fallback: use employee.shift_id
        return $employee->shift?->load('breaks');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function emptyResult(array $rawPunches): array
    {
        return [
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
            'overtime_hours'            => 0.0,
            'ot1_hours'                 => 0.0,
            'ot2_hours'                 => 0.0,
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
        $last     = $times[0]->copy();
        foreach (array_slice($times, 1) as $time) {
            if ($last->diffInSeconds($time) >= self::NOISE_GAP_MINUTES * 60) {
                $filtered[] = $time;
                $last       = $time->copy();
            }
        }
        return $filtered;
    }

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
            if ($hasBreak)       return 'synthetic_break';
            if ($hasUnscheduled) return 'synthetic_unscheduled';
            return 'synthetic';
        }
        $tokens = [];
        if ($r['late_checkin'])       $tokens[] = 'late';
        if ($hasBreak)                $tokens[] = 'break';
        if ($missedPunch)             $tokens[] = 'missed_punch';
        if ($hasUnscheduled)          $tokens[] = 'unscheduled';
        if ($r['early_checkout'])     $tokens[] = 'early';
        if ($r['overtime_hours'] > 0) $tokens[] = 'overtime';
        if ($r['ot1_hours'] > 0)      $tokens[] = 'ot1';
        if ($r['ot2_hours'] > 0)      $tokens[] = 'ot2';
        return empty($tokens) ? 'complete' : 'complete_' . implode('_', $tokens);
    }

    private function isIncomplete(array $r): bool
    {
        return in_array($r['scenario'], [
            'no_punches','no_checkin','checkin_only','no_shift','not_scheduled',
            'synthetic','synthetic_break','synthetic_unscheduled','synthetic_break_unscheduled','unknown',
        ]);
    }
}
