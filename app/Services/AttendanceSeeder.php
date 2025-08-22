<?php

// app/Services/AttendanceSeeder.php
namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;

class AttendanceSeeder
{
    public function seedMissingAttendanceRecords(?int $orgId = null): void
    {
        $today = now()->toDateString();
        $now = now()->format('H:i:s');

        $employees = Employee::with('shift')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        foreach ($employees as $employee) {
            $shift = $employee->shift;
            if (!$shift) {
                continue;
            }

            $startTime = $shift->start_time->format('H:i:s');
            $endTime = $shift->end_time->format('H:i:s');
            $status = null;

            if ($endTime <= $startTime) {
                if ($now >= $startTime || $now <= $endTime) {
                    $status = 'unchecked_in';
                } elseif ($now > $endTime && $now < $startTime) {
                    $status = 'absent';
                }
            } else {
                if ($now >= $endTime) {
                    $status = 'absent';
                } elseif ($now >= $startTime) {
                    $status = 'unchecked_in';
                }
            }

            if ($status) {
                $attendance = Attendance::firstOrNew([
                    'employee_id' => $employee->id,
                    'date' => $today,
                ]);

                if (!$attendance->check_in_time) {
                    $attendance->status = $status;
                    $attendance->check_in_time = null;
                    $attendance->check_out_time = null;
                    $attendance->worked_hours = 0;
                    $attendance->overtime_hours = 0;
                    $attendance->save();
                }
            }
        }
    }

}

