<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceSeeder — Multi-shift version
 *
 * For employees with multiple shifts (Day + Night):
 *   - Seeds absent for Day shift after 17:30 if no day punch
 *   - Seeds absent for Night shift after 05:00 next morning if no night punch
 *   - Each shift gets its own attendance record (identified by shift_id on the record)
 *
 * NEVER seeds absent on weekends (Sat/Sun = voluntary OT days).
 */
class AttendanceSeeder
{
    public function seedMissingAttendanceRecords(?int $orgId = null, ?Carbon $targetDate = null): void
    {
        $now   = $targetDate ?? now();
        $today = $now->toDateString();

        // NEVER seed absent on weekends
        if (in_array($now->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
            return;
        }

        $employees = Employee::with(['shifts', 'shift', 'organization'])
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

    private function seedStudentRecord(Employee $student, string $today): void
    {
        $exists = Attendance::where('employee_id', $student->id)->whereDate('date', $today)->exists();
        if ($exists) return;

        $lastRecord = Attendance::where('employee_id', $student->id)
            ->whereDate('date', '<', $today)
            ->orderByDesc('date')->orderByDesc('id')->first();

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

    private function seedStaffRecord(Employee $employee, Carbon $now, string $today): void
    {
        $assignedShifts = $employee->shifts;

        if ($assignedShifts->isEmpty() && $employee->shift) {
            $assignedShifts = collect([$employee->shift]);
        }

        if ($assignedShifts->isEmpty()) return;

        // Weekend: never absent — voluntary OT days only
        // Top-level guard in seedMissingAttendanceRecords handles this,
        // but guard here too in case called directly
        if (in_array($now->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) return;

        $onLeave = Leave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();

        if (in_array($employee->shift_status, ['off_shift', 'sick_off'])) {
            $primaryShift = $assignedShifts->firstWhere('pivot.is_primary', true)
                ?? $assignedShifts->first();
            $this->seedSingleShiftAbsent($employee, $primaryShift, $now, $today, $onLeave);
            return;
        }

        // KEY RULE: employee is required to attend ONE shift per day.
        // If they attended ANY shift today (clocked_in or clocked_out),
        // their daily obligation is met — do NOT seed absent for other shifts.
        $attendedToday = Attendance::where('employee_id', $employee->id)
            ->where('date', $today)
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->exists();

        if ($attendedToday) return;

        foreach ($assignedShifts as $shift) {
            $this->seedSingleShiftAbsent($employee, $shift, $now, $today, $onLeave);
        }
    }

    private function seedSingleShiftAbsent(
        Employee $employee,
                 $shift,
        Carbon $now,
        string $today,
        bool $onLeave
    ): void {
        // Double-guard: never seed absent on weekends
        if (in_array($now->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) return;

        if (!$shift->isScheduledOn($today)) return;

        $shiftEnd = $shift->getEffectiveEndTime($today);

        // Shift hasn't ended yet — don't seed absent prematurely
        if ($now->lt($shiftEnd)) return;

        // Check for shift-specific record
        $existing = Attendance::where('employee_id', $employee->id)
            ->where('date', $today)
            ->where('shift_id', $shift->id)
            ->first();

        if ($existing) return;

        // Check for general record (pre-pivot migration)
        $generalExisting = Attendance::where('employee_id', $employee->id)
            ->where('date', $today)
            ->whereNull('shift_id')
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->first();

        if ($generalExisting) {
            $checkInHour = $generalExisting->check_in_time
                ? (int) Carbon::parse($generalExisting->check_in_time)->format('H')
                : null;

            if ($checkInHour !== null) {
                $isNightShift = $shift->shift_type === 'night';
                $isNightPunch = $checkInHour >= 16 || $checkInHour < 5;

                if ($isNightShift === $isNightPunch) {
                    $generalExisting->update(['shift_id' => $shift->id]);
                    return;
                }
            }
        }

        $status = $onLeave ? 'on_leave' : 'absent';

        Attendance::create([
            'employee_id'    => $employee->id,
            'date'           => $today,
            'shift_id'       => $shift->id,
            'status'         => $status,
            'check_in_time'  => null,
            'check_out_time' => null,
            'worked_hours'   => 0,
            'overtime_hours' => 0,
            'defined_hours'  => 9.0,
        ]);
    }

}
