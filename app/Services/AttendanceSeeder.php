<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AttendanceSeeder
{
    /**
     * Seed missing attendance records
     *
     * @param int|null $orgId Organization ID filter
     * @param Carbon|null $targetDate Date to process (defaults to today)
     */
    public function seedMissingAttendanceRecords(?int $orgId = null, ?Carbon $targetDate = null): void
    {
        // Use provided date or default to now
        $now = $targetDate ?? now();
        $today = $now->toDateString();

        $employees = Employee::with('shift')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        foreach ($employees as $employee) {

            // 🚫 Skip inactive employees
            if ($employee->active == 0) {
                continue;
            }

            // Check if the employee has an associated shift
            $shift = $employee->shift;
            if (!$shift) continue;


            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'date' => $today,
            ]);

            // ========================================
            // NEW: Check if employee has assignments
            // ========================================
            if ($employee->assignments()->count() === 0) {
                $this->markAsNotScheduled($attendance);
                continue;
            }

            // ========================================
            // NEW: Check if employee should work today based on shift pattern
            // ========================================
            if (!$this->isEmployeeScheduledToday($shift, $now)) {
                $this->markAsNotScheduled($attendance);
                continue;
            }

            // ==================================================
            // Build shift start & end (unchanged logic)
            // ==================================================
            $shiftStart = $this->parseShiftTime($shift->start_time, $today);
            $shiftEnd = $this->parseShiftTime($shift->end_time, $today);


            // Handle overnight shifts
            if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
                $shiftEnd->addDay();
            }

            // =========================
            // Check if employee is on approved leave today
            // =========================
            $onLeave = Leave::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->exists();


            // Skip processing if the employee is on leave
            if ($onLeave) {
                $attendance->status = 'on_leave';
                $attendance->check_in_time = null;
                $attendance->check_out_time = null;
                $attendance->worked_hours = 0;
                $attendance->overtime_hours = 0;
                $attendance->save();
                continue;
            }

            // ==================================================
            // OFF_SHIFT LOGIC
            // ==================================================
            if ($employee->shift_status === 'off_shift'
                && $employee->start_off_shift_date
                && $employee->end_off_shift_date
            ) {
                $start = Carbon::parse($employee->start_off_shift_date);
                $end = Carbon::parse($employee->end_off_shift_date);

                // If current date is AFTER the off_shift period → back to on_shift
                if ($now->isAfter($end)) {
                    $employee->update([
                        'shift_status' => 'on_shift',
                    ]);
                } // If we are inside the off-shift window → mark attendance off_shift
                elseif ($now->between($start, $end, true)) {
                    $attendance->status = 'off_shift';
                    $attendance->check_in_time = null;
                    $attendance->check_out_time = null;
                    $attendance->worked_hours = 0;
                    $attendance->overtime_hours = 0;
                    $attendance->save();
                    continue;
                }
            }

            // ==================================================
            // SICK_OFF LOGIC
            // ==================================================
            if ($employee->shift_status === 'sick_off'
                && $employee->start_off_shift_date
                && $employee->end_off_shift_date
            ) {
                $start = Carbon::parse($employee->start_off_shift_date);
                $end = Carbon::parse($employee->end_off_shift_date);

                // If sick-off period has ended → back to on_shift
                if ($now->isAfter($end)) {
                    $employee->update([
                        'shift_status' => 'on_shift',
                    ]);
                } // If today is inside the sick-off window → mark attendance sick_off
                elseif ($now->between($start, $end, true)) {
                    $attendance->status = 'sick_off';
                    $attendance->check_in_time = null;
                    $attendance->check_out_time = null;
                    $attendance->worked_hours = 0;
                    $attendance->overtime_hours = 0;
                    $attendance->save();
                    continue;
                }
            }

            // Skip processing for employees who are not actually on shift
            if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
                continue;
            }

            // ========================================
            // NEW: Handle Auto Clock-Out Logic for clocked-in employees
            // ========================================
            if ($attendance->check_in_time && $attendance->status === 'clocked_in') {
                $this->handleAutoClockOut($attendance, $shift, $shiftStart, $shiftEnd, $now, $employee);
                continue; // Skip to next employee after handling auto clock-out
            }

            // =========================
            // Handle employees who haven't checked in
            // =========================
            if (!$attendance->check_in_time) {
                if ($now->greaterThan($shiftEnd)) {
                    $attendance->status = 'absent';
                } elseif ($now->between($shiftStart, $shiftEnd)) {
                    $attendance->status = 'unchecked_in';
                }

                if (isset($attendance->status)) {
                    $attendance->check_in_time = null;
                    $attendance->check_out_time = null;
                    $attendance->worked_hours = 0;
                    $attendance->overtime_hours = 0;
                    $attendance->save();
                }
            }
        }
    }

    /**
     * Check if employee is scheduled to work today based on shift pattern
     */
    private function isEmployeeScheduledToday($shift, Carbon $date): bool
    {
        $patternType = $shift->pattern_type ?? 'weekdays';
        $patternDays = $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

        // Get current day abbreviation (Mon, Tue, etc.)
        $currentDay = $date->format('D'); // Returns 'Mon', 'Tue', etc.

        // Check based on pattern type
        switch ($patternType) {
            case 'weekdays':
                return in_array($currentDay, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']);

            case 'weekends':
                return in_array($currentDay, ['Sat', 'Sun']);

            case 'daily':
                return true; // All days

            case 'custom':
            case 'rotating':
                return in_array($currentDay, $patternDays);

            default:
                // Fallback: check if current day is in pattern_days
                return in_array($currentDay, $patternDays);
        }
    }


    private function parseShiftTime(string $time, string $today): Carbon
    {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $time)
            ? Carbon::parse($time)
            : Carbon::parse("{$today} {$time}");
    }


    /**
     * Mark employee as not scheduled for today
     */
    private function markAsNotScheduled(Attendance $attendance): void
    {
        if ($attendance->status !== 'not_scheduled') {
            $attendance->status = 'not_scheduled';
            $attendance->check_in_time = null;
            $attendance->check_out_time = null;
            $attendance->worked_hours = 0;
            $attendance->overtime_hours = 0;
            $attendance->save();
        }
    }

    /**
     * Handle automatic clock-out for employees based on shift configuration
     */
    private function handleAutoClockOut(
        Attendance $attendance,
                   $shift,
        Carbon     $shiftStart,
        Carbon     $shiftEnd,
        Carbon     $now,
        Employee   $employee
    ): void
    {
        // Skip if auto clock-out is not enabled for this shift
        if (!$shift->auto_clock_out) {
            return;
        }

        $checkIn = Carbon::parse($attendance->check_in_time);

        // Calculate when auto clock-out should happen
        if ($shift->overtime_enabled && $shift->max_overtime_hours > 0) {
            // ========================================
            // OVERTIME ENABLED: Clock out at shift_end + max_overtime_hours
            // ========================================
            $maxOvertimeHours = (float)$shift->max_overtime_hours;
            $warningMinutes = (int)($shift->warning_time_minutes ?? 30);

            $autoClockOutTime = $shiftEnd->copy()->addHours($maxOvertimeHours);
            $warningTime = $autoClockOutTime->copy()->subMinutes($warningMinutes);

            // ========================================
            // Send warning notification before auto clock-out
            // ========================================
            if ($now->greaterThanOrEqualTo($warningTime) && $now->lessThan($autoClockOutTime)) {
                // Check if warning hasn't been sent already (to avoid sending every minute)
                if (!$attendance->overtime_warning_sent) {
                    $this->sendOvertimeWarning($employee, $shift, $autoClockOutTime);
                    $attendance->overtime_warning_sent = true;
                    $attendance->save();
                }
            }

            // ========================================
            // Auto clock out if max overtime reached
            // ========================================
            if ($now->greaterThanOrEqualTo($autoClockOutTime)) {
                $totalWorked = $checkIn->diffInMinutes($autoClockOutTime) / 60;
                $regularHours = min($totalWorked, $shift->duration_hours);
                $overtimeHours = max(0, $totalWorked - $shift->duration_hours);

                $attendance->update([
                    'status' => 'clocked_out',
                    'check_out_time' => $autoClockOutTime,
                    'worked_hours' => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'auto_clocked_out' => true,
                    'auto_clocked_out_reason' => 'Maximum overtime hours reached',
                ]);

                // Send notifications
                $this->sendAutoClockOutNotification($employee, $shift, 'overtime_limit_reached');

                // Notify managers if configured
                if ($shift->notify_managers_overtime) {
                    $this->notifyManagersAboutOvertime($employee, $shift, $overtimeHours);
                }

                Log::info("Auto clocked out employee {$employee->id} at overtime limit", [
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                    'overtime_hours' => $overtimeHours,
                ]);
            }
        } else {
            // ========================================
            // NO OVERTIME: Clock out at exact shift end
            // ========================================
            if ($now->greaterThanOrEqualTo($shiftEnd)) {
                $totalWorked = $checkIn->diffInMinutes($shiftEnd) / 60;
                $regularHours = min($totalWorked, $shift->duration_hours);

                $attendance->update([
                    'status' => 'clocked_out',
                    'check_out_time' => $shiftEnd,
                    'worked_hours' => round($regularHours, 2),
                    'overtime_hours' => 0,
                    'auto_clocked_out' => true,
                    'auto_clocked_out_reason' => 'Overtime not enabled for this shift',
                ]);

                // Send notification
                $this->sendAutoClockOutNotification($employee, $shift, 'shift_end');

                Log::info("Auto clocked out employee {$employee->id} at shift end (no overtime)", [
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                ]);
            }
        }
    }



    /**
     * Send overtime warning notification to employee
     */
    private function sendOvertimeWarning(Employee $employee, $shift, Carbon $autoClockOutTime): void
    {
        try {
            // Only send if mobile notifications are enabled
            if ($shift->employee_mobile_notifications) {
                // TODO: Implement your notification logic here
                // Example using Laravel notifications:
                // $employee->user->notify(new OvertimeWarningNotification($autoClockOutTime));

                Log::info("Overtime warning sent to employee {$employee->id}", [
                    'auto_clock_out_at' => $autoClockOutTime->format('H:i'),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send overtime warning", [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send auto clock-out notification to employee
     */
    private function sendAutoClockOutNotification(Employee $employee, $shift, string $reason): void
    {
        try {
            // Only send if mobile notifications are enabled
            if ($shift->employee_mobile_notifications) {
                // TODO: Implement your notification logic here
                // Example:
                // $employee->user->notify(new AutoClockOutNotification($reason));

                Log::info("Auto clock-out notification sent to employee {$employee->id}", [
                    'reason' => $reason,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send auto clock-out notification", [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify managers about employee overtime
     */
    private function notifyManagersAboutOvertime(Employee $employee, $shift, float $overtimeHours): void
    {
        try {
            // TODO: Get managers for this employee's organization/department
            // Example:
            // $managers = $employee->organization->managers;
            // Notification::send($managers, new EmployeeOvertimeNotification($employee, $overtimeHours));

            Log::info("Manager overtime notification triggered", [
                'employee_id' => $employee->id,
                'overtime_hours' => $overtimeHours,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to notify managers about overtime", [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate worked and overtime hours
     */
    private function calculateHours(
        Carbon $checkIn,
        Carbon $checkOut,
        float  $shiftDurationHours,
        float  $maxOvertimeHours = 0
    ): array
    {
        $totalWorked = $checkIn->diffInMinutes($checkOut) / 60;
        $regularHours = min($totalWorked, $shiftDurationHours);
        $overtimeHours = max(0, min($totalWorked - $shiftDurationHours, $maxOvertimeHours));

        return [
            'worked_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'total_hours' => round($totalWorked, 2),
        ];
    }
}
