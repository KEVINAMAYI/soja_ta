<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceSeeder
{
    /**
     * Seed missing attendance records for a given organisation and date.
     *
     * School organisations  → carry-forward logic (no shifts, no absent defaults).
     * Standard organisations → full shift / leave / auto-clock-out logic.
     */
    public function seedMissingAttendanceRecords(?int $orgId = null, ?Carbon $targetDate = null): void
    {
        $now = $targetDate ?? now();
        $today = $now->toDateString();

        $employees = Employee::with(['shift', 'organization'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('active', 1)
            ->get();

        foreach ($employees as $employee) {
            $isSchool = (bool)($employee->organization?->is_student_record ?? false);

            if (!$isSchool) {
                $this->seedStaffRecord($employee, $now, $today);
            } else {
                $this->seedStudentRecord($employee, $today);
            }
        }
    }

    /* ──────────────────────────────────────────────────────────────
     |  SCHOOL / STUDENT BRANCH
     |
     |  Rules:
     |    • No shifts, no leave checks, no off-shift windows.
     |    • If a record for today already exists → leave it alone.
     |    • Otherwise copy the status of the most-recent past record.
     |    • If there is no past record at all → default to 'not_checked_in'
     |      (never 'absent' — that is set only when a pembroke is explicitly
     |       marked absent by a teacher, not by the seeder).
     ────────────────────────────────────────────────────────────── */
    private function seedStudentRecord(Employee $student, string $today): void
    {
        // If a record already exists for today, leave it alone.
        $exists = Attendance::where('employee_id', $student->id)
            ->whereDate('date', $today)
            ->exists();

        if ($exists) return;

        // Find the most recent record from a previous day.
        $lastRecord = Attendance::where('employee_id', $student->id)
            ->whereDate('date', '<', $today)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        // Only carry forward statuses that mean the student is still actively engaged.
        // absent / clocked_out / no record → do nothing (student simply has no record today).
        if (!$lastRecord || !in_array($lastRecord->status, ['clocked_in', 'on_leave', 'off_shift', 'sick_off'])) {
            return;
        }

        Attendance::create([
            'employee_id' => $student->id,
            'date' => $today,
            'status' => $lastRecord->status,
            'check_in_time' => $lastRecord->status === 'clocked_in' ? $lastRecord->check_in_time : null,
            'check_out_time' => null,
            'worked_hours' => 0,
            'overtime_hours' => 0,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────
     |  STANDARD STAFF BRANCH  (unchanged business logic, cleaned up)
     ────────────────────────────────────────────────────────────── */
    private function seedStaffRecord(Employee $employee, Carbon $now, string $today): void
    {
        $shift = $employee->shift;

        if (!$shift) return;

        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date' => $today,
        ]);

        // ── No assignments ────────────────────────────────────────────────
        if ($employee->assignments()->count() === 0) {
            $this->markAsNotScheduled($attendance);
            return;
        }

        // ── Not a working day ─────────────────────────────────────────────
        if (!$this->isEmployeeScheduledToday($shift, $now)) {
            $this->markAsNotScheduled($attendance);
            return;
        }

        // ── Build shift window ────────────────────────────────────────────
        $shiftStart = $this->parseShiftTime($shift->start_time, $today);
        $shiftEnd = $this->parseShiftTime($shift->end_time, $today);
        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
            $shiftEnd->addDay();
        }

        // ── Approved leave ────────────────────────────────────────────────
        $onLeave = Leave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();

        if ($onLeave) {
            $attendance->fill([
                'status' => 'on_leave',
                'check_in_time' => null,
                'check_out_time' => null,
                'worked_hours' => 0,
                'overtime_hours' => 0,
            ])->save();
            return;
        }

        // ── Off-shift / sick-off ──────────────────────────────────────────
        // FIX: Check shift_status BEFORE anything else that could mark absent
        // Also handle case where dates are not set (treat as indefinitely off)
        if (in_array($employee->shift_status, ['off_shift', 'sick_off'])) {
            $handled = $this->handleShiftStatusWindow($employee, $attendance, $now);

            if (!$handled) {
                // Dates not set or period not yet started — still off shift
                // Save the correct status instead of falling through to absent
                $attendance->fill([
                    'status' => $employee->shift_status,
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'worked_hours' => 0,
                    'overtime_hours' => 0,
                ])->save();
            }

            return; // Always return — never fall through to absent logic
        }

        // ── Already clocked in — evaluate auto clock-out ──────────────────
        if ($attendance->check_in_time && $attendance->status === 'clocked_in') {
            $this->handleAutoClockOut($attendance, $shift, $shiftStart, $shiftEnd, $now, $employee);
            return;
        }

        // ── Not yet checked in ────────────────────────────────────────────
        if (!$attendance->check_in_time) {
            if ($now->greaterThan($shiftEnd)) {
                $status = 'absent';
            } elseif ($now->between($shiftStart, $shiftEnd)) {
                $status = 'unchecked_in';
            } else {
                return; // Shift hasn't started yet
            }

            $attendance->fill([
                'status' => $status,
                'check_in_time' => null,
                'check_out_time' => null,
                'worked_hours' => 0,
                'overtime_hours' => 0,
            ])->save();
        }
    }

    /* ──────────────────────────────────────────────────────────────
     |  HELPERS
     ────────────────────────────────────────────────────────────── */

    /**
     * Handle off_shift / sick_off date windows for staff employees.
     * Returns true if the record was handled (caller should return early).
     */
    private function handleShiftStatusWindow(Employee $employee, Attendance $attendance, Carbon $now): bool
    {
        $status = $employee->shift_status;

        if (!in_array($status, ['off_shift', 'sick_off'])) {
            return false;
        }

        $start = $employee->start_off_shift_date ? Carbon::parse($employee->start_off_shift_date) : null;
        $end = $employee->end_off_shift_date ? Carbon::parse($employee->end_off_shift_date) : null;

        if (!$start || !$end) {
            return false;
        }

        if ($now->isAfter($end)) {
            // Period has expired → restore to on_shift and let normal logic continue.
            $employee->update(['shift_status' => 'on_shift']);
            return false;
        }

        if ($now->between($start, $end, true)) {
            $attendance->fill([
                'status' => $status, // 'off_shift' or 'sick_off'
                'check_in_time' => null,
                'check_out_time' => null,
                'worked_hours' => 0,
                'overtime_hours' => 0,
            ])->save();
            return true;
        }

        return false;
    }

    /**
     * Check if employee is scheduled to work today based on shift pattern.
     */
    private function isEmployeeScheduledToday($shift, Carbon $date): bool
    {
        $patternType = $shift->pattern_type ?? 'weekdays';
        $patternDays = $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        $currentDay = $date->format('D'); // 'Mon', 'Tue', …

        return match ($patternType) {
            'weekdays' => in_array($currentDay, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
            'weekends' => in_array($currentDay, ['Sat', 'Sun']),
            'daily' => true,
            default => in_array($currentDay, $patternDays), // 'custom', 'rotating', …
        };
    }

    private function parseShiftTime(string $time, string $today): Carbon
    {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $time)
            ? Carbon::parse($time)
            : Carbon::parse("{$today} {$time}");
    }

    /**
     * Mark an attendance record as not scheduled (idempotent).
     */
    private function markAsNotScheduled(Attendance $attendance): void
    {
        if ($attendance->status === 'not_scheduled') {
            return;
        }

        $attendance->fill([
            'status' => 'not_scheduled',
            'check_in_time' => null,
            'check_out_time' => null,
            'worked_hours' => 0,
            'overtime_hours' => 0,
        ])->save();
    }

    /**
     * Evaluate whether a clocked-in employee should be automatically clocked out.
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
        if (!$shift->auto_clock_out) {
            return;
        }

        $checkIn = Carbon::parse($attendance->check_in_time);

        if ($shift->overtime_enabled && $shift->max_overtime_hours > 0) {
            $maxOvertimeHours = (float)$shift->max_overtime_hours;
            $warningMinutes = (int)($shift->warning_time_minutes ?? 30);
            $autoClockOutTime = $shiftEnd->copy()->addHours($maxOvertimeHours);
            $warningTime = $autoClockOutTime->copy()->subMinutes($warningMinutes);

            // Send pre-clock-out warning once.
            if ($now->between($warningTime, $autoClockOutTime) && !$attendance->overtime_warning_sent) {
                $this->sendOvertimeWarning($employee, $shift, $autoClockOutTime);
                $attendance->overtime_warning_sent = true;
                $attendance->save();
            }

            // Auto clock-out at max overtime.
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

                $this->sendAutoClockOutNotification($employee, $shift, 'overtime_limit_reached');

                if ($shift->notify_managers_overtime) {
                    $this->notifyManagersAboutOvertime($employee, $shift, $overtimeHours);
                }

                Log::info("Auto clocked out [{$employee->id}] at overtime limit", [
                    'overtime_hours' => $overtimeHours,
                ]);
            }
        } else {
            // No overtime – clock out at shift end.
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

                $this->sendAutoClockOutNotification($employee, $shift, 'shift_end');

                Log::info("Auto clocked out [{$employee->id}] at shift end (no overtime)");
            }
        }
    }

    /* ── Notification stubs ──────────────────────────────────────── */

    private function sendOvertimeWarning(Employee $employee, $shift, Carbon $autoClockOutTime): void
    {
        if (!$shift->employee_mobile_notifications) return;

        try {
            // $employee->user->notify(new OvertimeWarningNotification($autoClockOutTime));
            Log::info("Overtime warning → employee [{$employee->id}]", [
                'clock_out_at' => $autoClockOutTime->format('H:i'),
            ]);
        } catch (\Exception $e) {
            Log::error("Overtime warning failed [{$employee->id}]: {$e->getMessage()}");
        }
    }

    private function sendAutoClockOutNotification(Employee $employee, $shift, string $reason): void
    {
        if (!$shift->employee_mobile_notifications) return;

        try {
            // $employee->user->notify(new AutoClockOutNotification($reason));
            Log::info("Auto clock-out notification → employee [{$employee->id}]", ['reason' => $reason]);
        } catch (\Exception $e) {
            Log::error("Auto clock-out notification failed [{$employee->id}]: {$e->getMessage()}");
        }
    }

    private function notifyManagersAboutOvertime(Employee $employee, $shift, float $overtimeHours): void
    {
        try {
            // Notification::send($employee->organization->managers, new EmployeeOvertimeNotification(...));
            Log::info("Manager overtime notification → employee [{$employee->id}]", [
                'overtime_hours' => $overtimeHours,
            ]);
        } catch (\Exception $e) {
            Log::error("Manager overtime notification failed [{$employee->id}]: {$e->getMessage()}");
        }
    }
}
