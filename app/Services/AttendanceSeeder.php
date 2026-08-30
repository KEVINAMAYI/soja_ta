<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceSeeder
{
    public function seedMissingAttendanceRecords(?int $orgId = null, ?Carbon $targetDate = null): void
    {
        $now   = $targetDate ?? now();
        $today = $now->toDateString();

        // ── NEVER seed absent on weekends ─────────────────────────────────────
        // Saturday and Sunday are voluntary OT days.
        // Employees who don't come in simply have no attendance record.
        $dow = $now->dayOfWeek;
        if ($dow === Carbon::SATURDAY || $dow === Carbon::SUNDAY) {
            return;
        }

        $employees = Employee::with(['shift', 'shifts', 'organization'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('active', 1)
            ->get();

        foreach ($employees as $employee) {
            $isSchool = (bool) ($employee->organization?->is_student_record ?? false);
            if (!$isSchool) {
                $this->seedStaffRecord($employee, $now, $today);
            } else {
                $this->seedStudentRecord($employee, $today);
            }
        }
    }

    // ── SCHOOL / STUDENT ─────────────────────────────────────────────────────

    private function seedStudentRecord(Employee $student, string $today): void
    {
        $exists = Attendance::where('employee_id', $student->id)
            ->whereDate('date', $today)
            ->exists();

        if ($exists) return;

        $lastRecord = Attendance::where('employee_id', $student->id)
            ->whereDate('date', '<', $today)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (!$lastRecord || !in_array($lastRecord->status, ['clocked_in', 'on_leave', 'off_shift', 'sick_off'])) {
            return;
        }

        Attendance::create([
            'employee_id'    => $student->id,
            'date'           => $today,
            'status'         => $lastRecord->status,
            'check_in_time'  => $lastRecord->status === 'clocked_in' ? $lastRecord->check_in_time : null,
            'check_out_time' => null,
            'worked_hours'   => 0,
            'overtime_hours' => 0,
        ]);
    }

    // ── STANDARD STAFF ───────────────────────────────────────────────────────

    private function seedStaffRecord(Employee $employee, Carbon $now, string $today): void
    {
        $shift = $employee->shift;
        if (!$shift) {
            // Try primary shift from pivot
            $shift = $employee->shifts?->firstWhere('pivot.is_primary', true)
                ?? $employee->shifts?->first();
        }
        if (!$shift) return;

        // ── NIGHT SHIFT GUARD ──────────────────────────────────────────────────
        // Never seed absent for an employee who:
        //   a) Is still clocked-in from a night shift that started yesterday, OR
        //   b) Has a night shift that hasn't started yet today (shift starts 16:xx-23:xx)
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $openNightRecord = Attendance::where('employee_id', $employee->id)
            ->where('date', $yesterday)
            ->where('status', 'clocked_in')
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->first();

        if ($openNightRecord) {
            $checkInHour = (int) Carbon::parse($openNightRecord->check_in_time)->format('H');
            if ($checkInHour >= 16) {
                // Still on last night's shift — don't touch today's record
                return;
            }
        }

        // If employee has a night shift assigned and current time is before night shift starts,
        // don't mark them absent — they may be coming in later tonight.
        $nightShift = $employee->shifts?->firstWhere('shift_type', 'night');
        if ($nightShift && $nightShift->isScheduledOn($today)) {
            $nightStart = Carbon::parse($today . ' ' . Carbon::parse($nightShift->start_time)->format('H:i:s'));
            if ($now->lt($nightStart)) {
                return; // Night shift hasn't started yet — don't mark absent
            }
        }

        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date'        => $today,
        ]);

        if ($employee->assignments()->count() === 0) {
            $this->markAsNotScheduled($attendance);
            return;
        }

        if (!$this->isEmployeeScheduledToday($shift, $now)) {
            $this->markAsNotScheduled($attendance);
            return;
        }

        $shiftStart = $this->parseShiftTime($shift->start_time, $today);
        $shiftEnd   = $this->parseShiftTime($shift->end_time, $today);
        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) $shiftEnd->addDay();

        // Approved leave
        $onLeave = Leave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();

        if ($onLeave) {
            $attendance->fill(['status' => 'on_leave', 'check_in_time' => null, 'check_out_time' => null, 'worked_hours' => 0, 'overtime_hours' => 0])->save();
            return;
        }

        // Off-shift / sick-off
        if (in_array($employee->shift_status, ['off_shift', 'sick_off'])) {
            $handled = $this->handleShiftStatusWindow($employee, $attendance, $now);
            if (!$handled) {
                $attendance->fill(['status' => $employee->shift_status, 'check_in_time' => null, 'check_out_time' => null, 'worked_hours' => 0, 'overtime_hours' => 0])->save();
            }
            return;
        }

        // Already clocked in — evaluate auto clock-out
        if ($attendance->check_in_time && $attendance->status === 'clocked_in') {
            $this->handleAutoClockOut($attendance, $shift, $shiftStart, $shiftEnd, $now, $employee);
            return;
        }

        // Not yet checked in
        if (!$attendance->check_in_time) {
            // Multi-shift employees (e.g. Day + Night): judge absence against
            // whichever assigned shift's window is actually relevant right now,
            // not just the default shift — otherwise someone rostered for Night
            // gets marked absent the moment Day's end time passes.
            $window = $this->resolveActiveOrNextShiftWindow($employee, $shift, $today, $now);
            if (!$window) {
                return;
            }
            [$windowStart, $windowEnd] = $window;

            if ($now->greaterThan($windowEnd)) {
                $status = 'absent';
            } elseif ($now->between($windowStart, $windowEnd)) {
                $status = 'unchecked_in';
            } else {
                return;
            }

            $attendance->fill(['status' => $status, 'check_in_time' => null, 'check_out_time' => null, 'worked_hours' => 0, 'overtime_hours' => 0])->save();
        }
    }

    /**
     * For a not-yet-checked-in employee, pick which assigned shift's window
     * to judge attendance against today. Prefers whichever window $now is
     * currently inside; if $now is past every scheduled window's end, uses
     * the latest-ending one (so absence waits for the last possible shift);
     * returns null if $now is before every scheduled window's start (too
     * early to judge either way — matches the existing "not yet" behavior
     * for single-shift employees).
     */
    private function resolveActiveOrNextShiftWindow(Employee $employee, $defaultShift, string $today, Carbon $now): ?array
    {
        $shifts = ($employee->shifts && $employee->shifts->isNotEmpty())
            ? $employee->shifts
            : collect([$defaultShift]);

        $windows = [];
        foreach ($shifts as $s) {
            if (!$this->isEmployeeScheduledToday($s, $now)) continue;

            $start = $this->parseShiftTime($s->start_time, $today);
            $end   = $this->parseShiftTime($s->end_time, $today);
            if ($end->lessThanOrEqualTo($start)) $end->addDay();

            $windows[] = [$start, $end];
        }

        if (empty($windows)) return null;

        foreach ($windows as $window) {
            if ($now->between($window[0], $window[1])) return $window;
        }

        $allEnded = collect($windows)->every(fn ($w) => $now->greaterThan($w[1]));
        if ($allEnded) {
            return collect($windows)->sortByDesc(fn ($w) => $w[1])->first();
        }

        return null;
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function handleShiftStatusWindow(Employee $employee, Attendance $attendance, Carbon $now): bool
    {
        $status = $employee->shift_status;
        if (!in_array($status, ['off_shift', 'sick_off'])) return false;

        $start = $employee->start_off_shift_date ? Carbon::parse($employee->start_off_shift_date) : null;
        $end   = $employee->end_off_shift_date   ? Carbon::parse($employee->end_off_shift_date)   : null;

        if (!$start || !$end) return false;

        if ($now->isAfter($end)) {
            $employee->update(['shift_status' => 'on_shift']);
            return false;
        }

        if ($now->between($start, $end, true)) {
            $attendance->fill(['status' => $status, 'check_in_time' => null, 'check_out_time' => null, 'worked_hours' => 0, 'overtime_hours' => 0])->save();
            return true;
        }

        return false;
    }

    private function isEmployeeScheduledToday($shift, Carbon $date): bool
    {
        $patternType = $shift->pattern_type ?? 'weekdays';
        $patternDays = $shift->pattern_days ?? ['Mon','Tue','Wed','Thu','Fri'];
        $currentDay  = $date->format('D');

        return match ($patternType) {
            'weekdays' => in_array($currentDay, ['Mon','Tue','Wed','Thu','Fri']),
            'weekends' => in_array($currentDay, ['Sat','Sun']),
            'daily'    => true,
            default    => in_array($currentDay, $patternDays),
        };
    }

    private function parseShiftTime(string $time, string $today): Carbon
    {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $time)
            ? Carbon::parse($time)
            : Carbon::parse("{$today} {$time}");
    }

    private function markAsNotScheduled(Attendance $attendance): void
    {
        if ($attendance->status === 'not_scheduled') return;
        $attendance->fill(['status' => 'not_scheduled', 'check_in_time' => null, 'check_out_time' => null, 'worked_hours' => 0, 'overtime_hours' => 0])->save();
    }

    private function handleAutoClockOut(Attendance $attendance, $shift, Carbon $shiftStart, Carbon $shiftEnd, Carbon $now, Employee $employee): void
    {
        if (!$shift->auto_clock_out) return;

        $checkIn = Carbon::parse($attendance->check_in_time);

        if ($shift->overtime_enabled && $shift->max_overtime_hours > 0) {
            $autoClockOutTime = $shiftEnd->copy()->addHours((float) $shift->max_overtime_hours);
            $warningTime      = $autoClockOutTime->copy()->subMinutes((int) ($shift->warning_time_minutes ?? 30));

            if ($now->between($warningTime, $autoClockOutTime) && !$attendance->overtime_warning_sent) {
                $attendance->overtime_warning_sent = true;
                $attendance->save();
            }

            if ($now->gte($autoClockOutTime)) {
                $totalWorked   = $checkIn->diffInMinutes($autoClockOutTime) / 60;
                $attendance->update([
                    'status'                  => 'clocked_out',
                    'check_out_time'          => $autoClockOutTime,
                    'worked_hours'            => round(min($totalWorked, $shift->duration_hours), 2),
                    'overtime_hours'          => round(max(0, $totalWorked - $shift->duration_hours), 2),
                    'auto_clocked_out'        => true,
                    'auto_clocked_out_reason' => 'Maximum overtime hours reached',
                ]);
            }
        } else {
            if ($now->gte($shiftEnd)) {
                $totalWorked = $checkIn->diffInMinutes($shiftEnd) / 60;
                $attendance->update([
                    'status'                  => 'clocked_out',
                    'check_out_time'          => $shiftEnd,
                    'worked_hours'            => round(min($totalWorked, $shift->duration_hours), 2),
                    'overtime_hours'          => 0,
                    'auto_clocked_out'        => true,
                    'auto_clocked_out_reason' => 'Overtime not enabled for this shift',
                ]);
            }
        }
    }

    private function sendOvertimeWarning(Employee $employee, $shift, Carbon $autoClockOutTime): void
    {
        if (!$shift->employee_mobile_notifications) return;
        Log::info("Overtime warning → employee [{$employee->id}]", ['clock_out_at' => $autoClockOutTime->format('H:i')]);
    }

    private function sendAutoClockOutNotification(Employee $employee, $shift, string $reason): void
    {
        if (!$shift->employee_mobile_notifications) return;
        Log::info("Auto clock-out notification → employee [{$employee->id}]", ['reason' => $reason]);
    }

    private function notifyManagersAboutOvertime(Employee $employee, $shift, float $overtimeHours): void
    {
        Log::info("Manager overtime notification → employee [{$employee->id}]", ['overtime_hours' => $overtimeHours]);
    }
}
