<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Models\OrganizationShiftSetting;

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

        $employees = Employee::with(['currentShift', 'activeShifts'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        foreach ($employees as $employee) {

            // 🚫 Skip inactive employees
            if ($employee->active == 0) {
                continue;
            }

            // ========================================
            // CHECK IF EMPLOYEE HAS ASSIGNMENTS
            // ========================================
            if ($employee->assignments()->count() === 0) {
                continue; // Skip employees without work location assignments
            }

            // ========================================
            // CHECK EMPLOYEE STATUS (LEAVE, OFF_SHIFT, SICK_OFF)
            // ========================================
            if ($this->handleEmployeeStatus($employee, $now, $today)) {
                continue; // Status handled, skip to next employee
            }

            // ========================================
            // GET ALL ACTIVE SHIFTS FOR TODAY
            // ========================================
            $activeShiftsForToday = $employee->activeShifts()
                ->get()
                ->filter(function ($shift) use ($now) {
                    return $this->isEmployeeScheduledToday($shift, $now);
                });

            // If no shifts scheduled for today, mark as not_scheduled
            if ($activeShiftsForToday->isEmpty()) {
                $this->markAllShiftsAsNotScheduled($employee, $today);
                continue;
            }

            // ========================================
            // DETERMINE WHICH SHIFT TO PROCESS
            // ========================================
            $shiftToProcess = $this->determineActiveShift($employee, $activeShiftsForToday, $now);

            if (!$shiftToProcess) {
                // No shift could be determined, mark all as not_scheduled
                $this->markAllShiftsAsNotScheduled($employee, $today);
                continue;
            }

            // ========================================
            // PROCESS THE DETERMINED SHIFT
            // ========================================
            $this->processShiftAttendance($employee, $shiftToProcess, $now, $today);
        }
    }

    /**
     * Handle employee status (leave, off_shift, sick_off)
     * Returns true if status was handled, false otherwise
     */
    private function handleEmployeeStatus(Employee $employee, Carbon $now, string $today): bool
    {
        // =========================
        // Check if employee is on approved leave today
        // =========================
        $onLeave = Leave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();

        if ($onLeave) {
            $this->markAllShiftsWithStatus($employee, $today, 'on_leave');
            return true;
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
                $employee->update(['shift_status' => 'on_shift']);
                return false; // Continue processing normally
            }

            // If we are inside the off-shift window → mark attendance off_shift
            if ($now->between($start, $end, true)) {
                $this->markAllShiftsWithStatus($employee, $today, 'off_shift');
                return true;
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
                $employee->update(['shift_status' => 'on_shift']);
                return false; // Continue processing normally
            }

            // If today is inside the sick-off window → mark attendance sick_off
            if ($now->between($start, $end, true)) {
                $this->markAllShiftsWithStatus($employee, $today, 'sick_off');
                return true;
            }
        }

        // Skip processing if in any of these statuses (safety check)
        if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
            return true;
        }

        return false;
    }

    /**
     * Determine which shift should be processed based on priority and timing
     */
    /**
     * Determine which shift should be processed based on priority and timing
     */
    private function determineActiveShift(Employee $employee, $activeShiftsForToday, Carbon $now)
    {
        $orgSettings = OrganizationShiftSetting::getForOrganization($employee->organization_id);

        // ========================================
        // ALWAYS USE AUTO-DETECTION IF ENABLED
        // ========================================
        if ($orgSettings && $orgSettings->allow_auto_shift_detection) {
            $detectionResult = $employee->detectShiftForTime($now);

            if ($detectionResult['shift']) {
                $detectedShift = $detectionResult['shift'];

                // Check if this is a different shift than current
                $shiftChanged = $employee->current_shift_id && $employee->current_shift_id != $detectedShift->id;

                // Update employee's shift IDs
                if (!$employee->current_shift_id || $shiftChanged) {
                    $oldShiftId = $employee->current_shift_id;

                    $employee->current_shift_id = $detectedShift->id;
                    $employee->shift_id = $detectedShift->id; // Update legacy field
                    $employee->last_shift_change_at = $now;
                    $employee->save();

                    Log::info("Auto-detected and switched employee shift", [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'old_shift_id' => $oldShiftId,
                        'new_shift_id' => $detectedShift->id,
                        'shift_name' => $detectedShift->name,
                        'detection_method' => $detectionResult['method'],
                        'detection_score' => $detectionResult['score'] ?? null,
                        'time' => $now->format('Y-m-d H:i:s'),
                    ]);
                }

                return $detectedShift;
            }
        }

        // ========================================
        // FALLBACK 1: Use current_shift_id if set and valid
        // ========================================
        if ($employee->current_shift_id) {
            $currentShift = $activeShiftsForToday->firstWhere('id', $employee->current_shift_id);

            if ($currentShift) {
                Log::info("Using employee's current shift (no auto-detection)", [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'shift_id' => $currentShift->id,
                    'shift_name' => $currentShift->name,
                ]);
                return $currentShift;
            }
        }

        // ========================================
        // FALLBACK 2: Use highest priority shift
        // ========================================
        $fallbackShift = $activeShiftsForToday->sortByDesc(function ($shift) {
            return $shift->pivot->priority;
        })->first();

        if ($fallbackShift) {
            $employee->current_shift_id = $fallbackShift->id;
            $employee->shift_id = $fallbackShift->id;
            $employee->last_shift_change_at = $now;
            $employee->save();

            Log::info("Fallback shift assigned (highest priority)", [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'shift_id' => $fallbackShift->id,
                'shift_name' => $fallbackShift->name,
                'priority' => $fallbackShift->pivot->priority,
            ]);
        }

        return $fallbackShift;
    }

    /**
     * Process attendance for a specific shift
     */
    private function processShiftAttendance(Employee $employee, $shift, Carbon $now, string $today): void
    {
        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date' => $today,
            'shift_id' => $shift->id
        ]);

        // ==================================================
        // Build shift start & end
        // ==================================================
        $shiftStart = $this->parseShiftTime($shift->start_time, $today);
        $shiftEnd = $this->parseShiftTime($shift->end_time, $today);

        // Handle overnight shifts
        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
            $shiftEnd->addDay();
        }

        // ========================================
        // Handle Auto Clock-Out Logic for clocked-in employees
        // ========================================
        if ($attendance->check_in_time && $attendance->status === 'clocked_in') {
            $this->handleAutoClockOut($attendance, $shift, $shiftStart, $shiftEnd, $now, $employee);
            return; // Skip to next employee after handling auto clock-out
        }

        // =========================
        // Handle employees who haven't checked in
        // =========================
        if (!$attendance->check_in_time) {
            if ($now->greaterThan($shiftEnd)) {
                // Shift has ended, mark as absent
                $attendance->status = 'absent';
                $attendance->auto_shift_detected = true;
                $attendance->shift_detection_method = 'auto_seeder';
                $attendance->shift_detection_log = json_encode([
                    'seeded_at' => $now->toDateTimeString(),
                    'reason' => 'No check-in by shift end time',
                    'shift_end' => $shiftEnd->toDateTimeString(),
                ]);
            } elseif ($now->between($shiftStart, $shiftEnd)) {
                // Currently within shift hours, mark as unchecked_in
                $attendance->status = 'unchecked_in';
                $attendance->auto_shift_detected = true;
                $attendance->shift_detection_method = 'auto_seeder';
                $attendance->shift_detection_log = json_encode([
                    'seeded_at' => $now->toDateTimeString(),
                    'reason' => 'Shift in progress, not checked in',
                    'shift_start' => $shiftStart->toDateTimeString(),
                    'shift_end' => $shiftEnd->toDateTimeString(),
                ]);
            } else {
                // Before shift start, don't create record yet
                return;
            }

            // Save the attendance record
            $attendance->check_in_time = null;
            $attendance->check_out_time = null;
            $attendance->worked_hours = 0;
            $attendance->overtime_hours = 0;
            $attendance->expected_check_in_time = $shiftStart->format('H:i:s');
            $attendance->expected_check_out_time = $shiftEnd->format('H:i:s');

            if ($shift->grace_period_enabled) {
                $attendance->grace_period_end_time = $shift->getGracePeriodEndTime()->format('H:i:s');
            }

            $attendance->save();

            Log::info("Attendance seeded for employee {$employee->id}", [
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'status' => $attendance->status,
                'date' => $today,
            ]);
        }
    }

    /**
     * Mark all shifts for an employee with a specific status
     */
    private function markAllShiftsWithStatus(Employee $employee, string $today, string $status): void
    {
        $shifts = $employee->activeShifts()->get();

        foreach ($shifts as $shift) {
            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'date' => $today,
                'shift_id' => $shift->id
            ]);

            $attendance->status = $status;
            $attendance->check_in_time = null;
            $attendance->check_out_time = null;
            $attendance->worked_hours = 0;
            $attendance->overtime_hours = 0;
            $attendance->save();
        }
    }

    /**
     * Mark all shifts as not_scheduled
     */
    private function markAllShiftsAsNotScheduled(Employee $employee, string $today): void
    {
        $this->markAllShiftsWithStatus($employee, $today, 'not_scheduled');
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
                    'shift_id' => $shift->id,
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
}
