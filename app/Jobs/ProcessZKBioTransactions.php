<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\EmployeeAssignment;
use App\Models\WorkLocation;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Events\ZKBioSyncCompleted;

class ProcessZKBioTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Minimum seconds between scans to count as a new action (duplicate tap protection)
    const MIN_GAP_SECONDS = 60;

    public function __construct(
        protected array $transactions,
        protected ?int $organizationId = null
    ) {}

    public function handle(): void
    {
        $transactions = array_values(array_filter(
            $this->transactions,
            fn($tx) => ($tx['eventName'] ?? '') === 'Normal Verify Open' && !empty($tx['pin'])
        ));

        if (empty($transactions)) return;

        $first = $transactions[0];
        $pin   = $first['pin'];
        $name  = trim(($first['name'] ?? '') . ' ' . ($first['lastName'] ?? ''));
        $date  = Carbon::parse($first['eventTime'])->toDateString();

        // Per-person per-day lock to prevent race conditions
        $lock = Cache::lock("zkbio_sync_{$pin}_{$date}", 30);
        if (!$lock->get()) return;

        try {
            DB::beginTransaction();

            $employee = $this->resolveEmployee($pin, $name, $first);
            if (!$employee) {
                DB::rollBack();
                return;
            }

            foreach ($transactions as $tx) {
                $eventTime = Carbon::parse($tx['eventTime']);
                $this->processScan($employee, $eventTime, $date);
            }

            DB::commit();

            broadcast(new ZKBioSyncCompleted(
                Carbon::today()->toDateString(),
                Carbon::today()->toDateString()
            ));

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ProcessZKBioTransactions failed', [
                'pin'   => $pin,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Core toggle logic:
     * - No record today           → check-in
     * - Any status + gap < 60s    → ignore (duplicate tap)
     * - Clocked in  + gap >= 60s  → checkout
     * - Clocked out + gap >= 60s  → new check-in (new record)
     */
    private function processScan(Employee $employee, Carbon $eventTime, string $date): void
    {
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $date)
            ->latest('check_in_time')
            ->first();

        // No record, or record exists but status is not clocked_in/clocked_out
        // (e.g. absent, unchecked_in, on_leave etc) → treat as fresh check-in
        if (!$attendance || !in_array($attendance->status, ['clocked_in', 'clocked_out'])) {
            $shift = $employee->shift;

            if ($attendance) {
                // Update the existing record instead of creating a duplicate
                $attendance->update([
                    'check_in_time'                 => $eventTime,
                    'status'                        => 'clocked_in',
                    'is_late_checkin'               => $shift?->isLateCheckIn($eventTime) ?? false,
                    'minutes_late'                  => $shift?->getMinutesLate($eventTime) ?? 0,
                    'within_grace_period'           => $shift?->isWithinGracePeriod($eventTime) ?? false,
                    'expected_check_in_time'        => $shift?->start_time,
                    'grace_period_end_time'         => $shift?->getGracePeriodEndTime()?->format('H:i:s'),
                    'expected_check_out_time'       => $shift?->end_time,
                    'early_checkout_threshold_time' => $shift?->getEarlyCheckoutThreshold()?->format('H:i:s'),
                ]);

                Log::info('ZKBio check-in (was ' . $attendance->status . ')', [
                    'employee' => $employee->name,
                    'time'     => $eventTime->toDateTimeString(),
                ]);
            } else {
                $this->createCheckIn($employee, $eventTime, $date);
            }
            return;
        }

        // Reference = last meaningful action time
        $lastActionTime = Carbon::parse(
            $attendance->check_out_time ?? $attendance->check_in_time
        );

        $gap = $lastActionTime->diffInSeconds($eventTime);

        // Duplicate tap — ignore
        if ($gap < self::MIN_GAP_SECONDS) {
            Log::info('ZKBio duplicate tap ignored', [
                'employee' => $employee->name,
                'gap_secs' => $gap,
                'time'     => $eventTime->toDateTimeString(),
            ]);
            return;
        }

        // Toggle
        if ($attendance->status === 'clocked_in') {
            // Clocked in + 60s+ later → checkout
            $this->applyCheckOut(
                $attendance,
                $employee,
                Carbon::parse($attendance->check_in_time),
                $eventTime
            );
        } else {
            // Clocked out + 60s+ later → new check-in
            $this->createCheckIn($employee, $eventTime, $date);
        }
    }

    private function resolveEmployee(string $pin, string $name, array $tx): ?Employee
    {
        $employee = Employee::where('zkbio_pin', $pin)->first();
        if ($employee) return $employee;

        $employee = Employee::where('id_number', "ZK-{$pin}")->first();
        if ($employee) {
            $employee->update(['zkbio_pin' => $pin]);
            return $employee;
        }

        $employee = Employee::where('email', "zkbio_{$pin}@auto.local")->first();
        if ($employee) {
            $employee->update(['zkbio_pin' => $pin]);
            return $employee;
        }

        return $this->createEmployeeFromZKBio($pin, $name);
    }

    private function createEmployeeFromZKBio(string $pin, string $name): ?Employee
    {
        $orgId   = $this->organizationId ?? config('zkbio.default_organization_id');
        $deptId  = config('zkbio.default_department_id');
        $shiftId = config('zkbio.default_shift_id');

        if (!$orgId || !$deptId || !$shiftId) {
            Log::warning('ZKBio auto-create skipped: missing org/dept/shift config', ['pin' => $pin]);
            return null;
        }

        $displayName = $name ?: "ZKBio User {$pin}";
        $email       = "zkbio_{$pin}@auto.local";

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $displayName, 'password' => Hash::make(Str::random(16))]
        );

        if ($user->wasRecentlyCreated) {
            $user->assignRole('employee');
        }

        $employee = Employee::firstOrCreate(
            ['zkbio_pin' => $pin],
            [
                'name'            => $displayName,
                'email'           => $email,
                'organization_id' => $orgId,
                'department_id'   => $deptId,
                'shift_id'        => $shiftId,
                'id_number'       => "ZK-{$pin}",
                'active'          => true,
                'user_id'         => $user->id,
            ]
        );

        if ($employee->wasRecentlyCreated) {
            $defaultLocation = WorkLocation::where('organization_id', $orgId)
                ->where('is_default', true)
                ->first();

            if ($defaultLocation) {
                EmployeeAssignment::create([
                    'employee_id'      => $employee->id,
                    'work_location_id' => $defaultLocation->id,
                    'is_current'       => true,
                ]);
            }

            Log::info('ZKBio auto-created employee', ['pin' => $pin, 'name' => $displayName]);
        }

        return $employee;
    }

    private function createCheckIn(Employee $employee, Carbon $time, string $date): Attendance
    {
        $shift = $employee->shift;

        $attendance = Attendance::create([
            'employee_id'                   => $employee->id,
            'date'                          => $date,
            'check_in_time'                 => $time,
            'status'                        => 'clocked_in',
            'is_late_checkin'               => $shift?->isLateCheckIn($time) ?? false,
            'minutes_late'                  => $shift?->getMinutesLate($time) ?? 0,
            'within_grace_period'           => $shift?->isWithinGracePeriod($time) ?? false,
            'expected_check_in_time'        => $shift?->start_time,
            'grace_period_end_time'         => $shift?->getGracePeriodEndTime()?->format('H:i:s'),
            'expected_check_out_time'       => $shift?->end_time,
            'early_checkout_threshold_time' => $shift?->getEarlyCheckoutThreshold()?->format('H:i:s'),
            'is_early_checkout'             => false,
            'minutes_early'                 => 0,
            'is_late_checkout'              => false,
            'late_checkout_hours'           => 0,
            'total_break_minutes'           => 0,
            'paid_break_minutes'            => 0,
            'excess_break_minutes'          => 0,
            'break_count'                   => 0,
            'is_break_checkout'             => false,
        ]);

        Log::info('ZKBio check-in', [
            'employee' => $employee->name,
            'time'     => $time->toDateTimeString(),
        ]);

        return $attendance;
    }

    private function applyCheckOut(Attendance $attendance, Employee $employee, Carbon $checkInTime, Carbon $checkOutTime): void
    {
        $shift         = $employee->shift;
        $minutesWorked = $checkInTime->diffInMinutes($checkOutTime);
        $hoursWorked   = round($minutesWorked / 60, 2);
        $shiftDuration = $shift ? (float)$shift->duration_hours : 8.0;
        $maxOvertime   = $shift ? (float)($shift->max_overtime_hours ?? 0) : 0;
        $regularHours  = min($hoursWorked, $shiftDuration);
        $overtimeHours = ($shift?->overtime_enabled)
            ? min(max(0, $hoursWorked - $shiftDuration), $maxOvertime)
            : 0;

        $attendance->update([
            'check_out_time'    => $checkOutTime,
            'status'            => 'clocked_out',
            'worked_hours'      => round($regularHours, 2),
            'overtime_hours'    => round($overtimeHours, 2),
            'is_early_checkout' => $shift?->isEarlyCheckOut($checkOutTime) ?? false,
            'minutes_early'     => $shift?->getMinutesEarly($checkOutTime) ?? 0,
            'is_break_checkout' => false,
        ]);

        Log::info('ZKBio check-out', [
            'employee'     => $employee->name,
            'time'         => $checkOutTime->toDateTimeString(),
            'hours_worked' => $hoursWorked,
        ]);
    }
}
