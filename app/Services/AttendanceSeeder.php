<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;

class AttendanceSeeder
{
    public function seedMissingAttendanceRecords(?int $orgId = null): void
    {
        $today = now()->toDateString();
        $now = now();

        $employees = Employee::with('shift')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        foreach ($employees as $employee) {

            // Check if the employee has an associated shift
            $shift = $employee->shift;
            if (!$shift) continue;

            // Determine shift start & end
            $shiftStart = preg_match('/\d{4}-\d{2}-\d{2}/', $shift->start_time)
                ? Carbon::parse($shift->start_time)
                : Carbon::parse("{$today} {$shift->start_time}");

            $shiftEnd = preg_match('/\d{4}-\d{2}-\d{2}/', $shift->end_time)
                ? Carbon::parse($shift->end_time)
                : Carbon::parse("{$today} {$shift->end_time}");

            if ($shiftEnd->lessThanOrEqualTo($shiftStart)) $shiftEnd->addDay();

            // =========================
            // Check if employee is on approved leave today
            // =========================
            $onLeave = Leave::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->exists();

            // Get or create attendance record for today
            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'date' => $today,
            ]);

            // Skip processing if the employee is on leave
            if ($onLeave) {
                $attendance->status = 'on_leave';
                $attendance->check_in_time = null;
                $attendance->check_out_time = null;
                $attendance->worked_hours = 0;
                $attendance->overtime_hours = 0;
                $attendance->save();
                continue; // skip the rest of the logic
            }

            // =========================
            // Check shift status if it's 'off_shift' or 'inactive'
            // =========================
            if ($employee->shift_status === 'off_shift' || ($shift && $shift->status === 'inactive')) {
                // Mark attendance as 'off_shift' if employee is off shift or shift is inactive
                $attendance->status = 'off_shift';
                $attendance->check_in_time = null;
                $attendance->check_out_time = null;
                $attendance->worked_hours = 0;
                $attendance->overtime_hours = 0;
                $attendance->save();
                continue; // skip the rest of the logic
            }

            // =========================
            // Existing attendance logic
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
                continue;
            }

            // Handle 'clocked_in' status and calculate worked hours
            if ($attendance->status === 'clocked_in' && $attendance->check_in_time && $now->greaterThan($shiftEnd)) {
                $checkIn = Carbon::parse($attendance->check_in_time);
                $workedHours = $checkIn->diffInHours($shiftEnd, false);

                if ($workedHours < 0 || $workedHours > 24) continue;

                $attendance->update([
                    'status' => 'clocked_out',
                    'check_out_time' => $shiftEnd,
                    'worked_hours' => $workedHours,
                ]);
            }
        }
    }
}
