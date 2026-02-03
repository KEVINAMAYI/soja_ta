<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupOldAttendance extends Command
{
    protected $signature = 'attendance:cleanup-old';
    protected $description = 'Auto-checkout old unchecked attendance records';

    public function handle()
    {
        $employees = Employee::with('shift')->get();
        $totalFixed = 0;
        $totalSkipped = 0;

        $this->info("Starting attendance cleanup...");

        foreach ($employees as $employee) {
            $shift = $employee->shift;

            if (!$shift) {
                $this->warn("Skipping employee {$employee->id} - no shift assigned");
                continue;
            }

            // Calculate cutoff based on shift duration (not hardcoded 48 hours)
            $shiftDurationHours = (float)$shift->duration_hours;
            $maxOvertimeHours = (float)($shift->max_overtime_hours ?? 0);
            $bufferHours = 4;
            $maxShiftWindow = $shiftDurationHours + $maxOvertimeHours + $bufferHours;

            $cutoffTime = now()->subHours($maxShiftWindow);

            // Find old unchecked records for this employee
            $oldRecords = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->where('check_in_time', '<', $cutoffTime)
                ->get();

            if ($oldRecords->isEmpty()) {
                continue;
            }

            $this->info("Found {$oldRecords->count()} old unchecked record(s) for employee {$employee->id}");

            foreach ($oldRecords as $attendance) {
                $checkInTime = Carbon::parse($attendance->check_in_time);

                // Calculate expected checkout time based on shift
                $shiftStart = Carbon::parse($shift->start_time);
                $shiftEnd = Carbon::parse($shift->end_time);

                // Handle overnight shifts
                if ($shiftEnd->lessThan($shiftStart)) {
                    $shiftEnd->addDay();
                }

                // Set check-out time to shift end time on the same day as check-in
                $expectedCheckOut = $checkInTime->copy()
                    ->setTimeFrom($shiftEnd);

                if ($expectedCheckOut->lessThan($checkInTime)) {
                    $expectedCheckOut->addDay();
                }

                // Calculate hours based on shift settings
                $totalWorked = $checkInTime->diffInMinutes($expectedCheckOut) / 60;
                $durationHours = (float)$shift->duration_hours;

                // Match the checkout logic
                if ($shift->overtime_enabled) {
                    $regularHours = min($totalWorked, $durationHours);
                    $overtimeHours = max(0, $totalWorked - $durationHours);

                    // Enforce max overtime cap
                    if ($maxOvertimeHours > 0) {
                        $overtimeHours = min($overtimeHours, $maxOvertimeHours);
                    }
                } else {
                    $regularHours = min($totalWorked, $durationHours);
                    $overtimeHours = 0;
                }

                // Calculate late checkout hours
                $hoursSinceCheckIn = $checkInTime->diffInHours($expectedCheckOut);
                $isLateCheckout = $hoursSinceCheckIn > $maxShiftWindow;
                $lateCheckoutHours = $isLateCheckout ? $hoursSinceCheckIn - $maxShiftWindow : 0;

                $attendance->update([
                    'status' => 'clocked_out',
                    'check_out_time' => $expectedCheckOut,
                    'worked_hours' => round($regularHours , 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'auto_clocked_out' => true,
                    'auto_clocked_out_reason' => 'System cleanup - missing checkout',
                    'is_late_checkout' => $isLateCheckout,
                    'late_checkout_hours' => round($lateCheckoutHours, 2),
                ]);

                $this->info("✓ Fixed attendance {$attendance->id} for employee {$employee->id} (checked in: {$checkInTime->format('Y-m-d H:i')})");
                $totalFixed++;
            }
        }

        $this->newLine();
        $this->info("Cleanup complete!");
        $this->info("Total records fixed: {$totalFixed}");

        return Command::SUCCESS;
    }
}
