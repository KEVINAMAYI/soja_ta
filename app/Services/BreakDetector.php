<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceBreakLog;
use App\Models\ShiftBreak;
use Carbon\Carbon;

class BreakDetector
{
    /**
     * Is break tracking enabled for this employee's organization?
     */
    public static function isEnabled($employee): bool
    {
        return (bool) optional($employee->organization)->break_tracking_enabled;
    }

    /**
     * Check if a given checkout time falls inside any defined break window.
     * Returns the matching ShiftBreak or null (unscheduled break).
     */
    public static function matchBreakWindow(Carbon $checkOutTime, $shift): ?ShiftBreak
    {
        $activeBreaks = $shift->activeBreaks; // already ordered

        foreach ($activeBreaks as $shiftBreak) {
            if ($shiftBreak->isWithinWindow($checkOutTime)) {
                return $shiftBreak;
            }
        }

        return null; // checkout outside any window = final checkout (or spontaneous break)
    }

    /**
     * Determine whether a checkout is a BREAK checkout or a FINAL checkout.
     *
     * Rules:
     *  - Break tracking must be enabled
     *  - Checkout time must fall within a defined break window
     *    OR employee has an open break log (they forgot to come back)
     */
    public static function isBreakCheckout(Carbon $checkOutTime, $employee, $attendance): bool
    {
        if (!self::isEnabled($employee)) {
            return false;
        }

        $shift = $employee->shift;
        if (!$shift) {
            return false;
        }

        // If they're already on a break (open break log) — this must be final checkout
        // (we don't allow nested breaks)
        $openBreak = AttendanceBreakLog::where('attendance_id', $attendance->id)
            ->where('status', 'in_progress')
            ->exists();

        if ($openBreak) {
            return false; // they're returning from break → this is a check-in, not checkout
        }

        // Check if checkout time falls within any break window
        $matched = self::matchBreakWindow($checkOutTime, $shift);

        return $matched !== null;
    }

    /**
     * Open a new break log when employee checks out during a break window.
     * Returns the created AttendanceBreakLog.
     */
    public static function openBreakLog(
        Attendance $attendance,
        Carbon     $breakStartTime,
                   $shift
    ): AttendanceBreakLog {
        $matchedBreak = self::matchBreakWindow($breakStartTime, $shift);

        return AttendanceBreakLog::create([
            'attendance_id'  => $attendance->id,
            'shift_break_id' => $matchedBreak?->id, // null = spontaneous/unscheduled
            'break_start_time' => $breakStartTime,
            'status'         => 'in_progress',
            'is_taken'       => false,
            'is_compliant'   => true,
            'excess_minutes' => 0,
            'is_auto_detected' => true,
        ]);
    }

    /**
     * Close the open break log when employee checks back in.
     * Returns the closed AttendanceBreakLog.
     */
    public static function closeBreakLog(
        Attendance $attendance,
        Carbon     $returnTime
    ): ?AttendanceBreakLog {
        $log = AttendanceBreakLog::where('attendance_id', $attendance->id)
            ->where('status', 'in_progress')
            ->latest('break_start_time')
            ->first();

        if (!$log) {
            return null;
        }

        $breakStart     = Carbon::parse($log->break_start_time);
        $actualMinutes  = $breakStart->diffInMinutes($returnTime);

        // Calculate excess against the matched ShiftBreak config (if any)
        $shiftBreak  = $log->shiftBreak;
        $maxAllowed  = $shiftBreak
            ? ($shiftBreak->max_duration_minutes ?? $shiftBreak->duration_minutes)
            : null; // spontaneous break — no limit defined

        $excessMinutes = $maxAllowed !== null
            ? max(0, $actualMinutes - $maxAllowed)
            : 0;

        $isCompliant = $excessMinutes === 0;

        $log->update([
            'break_end_time'          => $returnTime,
            'actual_duration_minutes' => $actualMinutes,
            'excess_minutes'          => $excessMinutes,
            'is_compliant'            => $isCompliant,
            'is_taken'                => true,
            'status'                  => $isCompliant ? 'completed' : 'exceeded',
        ]);

        return $log->fresh();
    }

    /**
     * Calculate total break deduction for an attendance record.
     * Returns array with breakdown for the checkout calculation.
     */
    public static function calculateDeductions(Attendance $attendance): array
    {
        $logs = AttendanceBreakLog::where('attendance_id', $attendance->id)
            ->with('shiftBreak')
            ->get();

        $unpaidMinutes  = 0;
        $paidMinutes    = 0;
        $excessMinutes  = 0;

        foreach ($logs as $log) {
            if (!$log->is_taken || !in_array($log->status, ['completed', 'exceeded'])) {
                continue;
            }

            $actual     = (int) $log->actual_duration_minutes;
            $shiftBreak = $log->shiftBreak;

            // No matched config = treat as unpaid (spontaneous break)
            $isPaid = $shiftBreak && $shiftBreak->type === 'paid';

            if ($isPaid) {
                $paidMinutes += $actual;
            } else {
                $unpaidMinutes += $actual;
            }

            $excessMinutes += (int) $log->excess_minutes;
        }

        return [
            'unpaid_minutes'  => $unpaidMinutes,
            'paid_minutes'    => $paidMinutes,
            'excess_minutes'  => $excessMinutes,
            'break_count'     => $logs->where('is_taken', true)->count(),
            'logs'            => $logs,
        ];
    }
}
