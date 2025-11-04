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
                continue; // skip employees with no shift
            }

            // Build full timestamps for today
            $shiftStart = Carbon::parse("{$today} {$shift->start_time}");
            $shiftEnd = Carbon::parse("{$today} {$shift->end_time}");

            // Handle overnight shifts
            if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
                $shiftEnd->addDay();
            }

            // Get or create today's attendance
            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'date' => $today,
            ]);

            // ========================
            // CASE 1: No check-in yet
            // ========================
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

                // Skip CASE 2 if they never clocked in
                continue;
            }

            // =================================================
            // CASE 2: Auto clock-out if still clocked_in
            // =================================================
            if ($attendance->status === 'clocked_in' && $attendance->check_in_time && $now->greaterThan($shiftEnd)) {

                $checkIn = Carbon::parse($attendance->check_in_time);
                $workedHours = $checkIn->diffInHours($shiftEnd, false);

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

                info("✅ Attendance {$attendance->id} auto clocked out successfully.");
            }

            // =================================================
            // CASE 3: Already clocked out → do nothing
            // =================================================
        }
    }
}
