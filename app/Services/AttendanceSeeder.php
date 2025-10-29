<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
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
            $shift = $employee->shift;
            if (!$shift) {
                continue;
            }

            // Build full timestamps for today
            $shiftStart = Carbon::hasFormat($shift->start_time, 'H:i:s')
                ? Carbon::parse("{$today} {$shift->start_time}")
                : Carbon::parse($shift->start_time);

            $shiftEnd = Carbon::hasFormat($shift->end_time, 'H:i:s')
                ? Carbon::parse("{$today} {$shift->end_time}")
                : Carbon::parse($shift->end_time);

            // Handle overnight shifts
            if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
                $shiftEnd->addDay();
            }

            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'date' => $today,
            ]);

            // CASE 1: No check-in yet
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

            // CASE 2: Auto clock-out if still clocked_in after shift end
            elseif ($attendance->status === 'clocked_in' && $now->greaterThan($shiftEnd)) {

                // --- DEBUG LOGGING START ---
                info("======================================");
                info("AUTO CLOCK OUT DEBUG");
                info("Employee ID: {$employee->id}");
                info("Attendance ID: {$attendance->id}");
                info("Shift start: {$shiftStart}");
                info("Shift end:   {$shiftEnd}");
                info("Now:         {$now}");
                info("Check-in:    {$attendance->check_in_time}");
                info("--------------------------------------");
                // --- DEBUG LOGGING END ---

                $checkIn = Carbon::parse($attendance->check_in_time);
                $workedHours = $checkIn->diffInHours($shiftEnd, false); // false → preserve sign

                info("Raw worked hours (can be negative): {$workedHours}");

                // Skip invalid values
                if ($workedHours < 0 || $workedHours > 24) {
                    info("❌ Skipping invalid worked hours for attendance {$attendance->id}: {$workedHours}");
                    continue;
                }

                $attendance->update([
                    'status' => 'clocked_out',
                    'check_out_time' => $shiftEnd,
                    'worked_hours' => $workedHours,
                ]);

                info("✅ Attendance {$attendance->id} clocked out successfully.");
                info("======================================");
            }
        }
    }
}
