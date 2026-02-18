<?php

namespace App\Http\Controllers\APIs;

use App\Helpers\ServerTime;
use App\Models\AttendanceBreakLog;
use App\Services\BreakDetector;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\EmployeeAssignment;
use App\Models\Overtime;
use App\Http\Resources\AttendanceResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * Employee Check-In
     */
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'identifier_type' => 'required|in:id_number,qr_code,face_id',
            'identifier_value' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'device_id' => 'nullable|exists:devices,id',
            'work_location_id' => 'required|exists:work_locations,id',
        ]);

        return $this->processCheckIn($validated['identifier_value'],
            $validated['identifier_type'],
            ServerTime::now(),
            $validated['latitude'],
            $validated['longitude'],
            $validated['work_location_id'],
            $validated['device_id'] ?? null);
    }

    /**
     * Employee Check-Out
     */
    public function checkOut(Request $request)
    {
        $validated = $request->validate([
            'identifier_type' => 'required|in:id_number,qr_code,face_id',
            'identifier_value' => 'required|string',
        ]);

        return $this->processCheckOut(
            $validated['identifier_value'],
            $validated['identifier_type'],
            ServerTime::now()
        );
    }


    /* ==============================================================
   UPDATED processCheckIn
   Handles two scenarios:
   A) First check-in of the day   → normal flow
   B) Re-check-in after break     → close open break log
   ============================================================== */

    private function processCheckIn(
        string        $value,
        string        $column,
        Carbon|string $checkInTime,
                      $latitude,
                      $longitude,
                      $work_location_id,
                      $deviceId = null
    )
    {
        DB::beginTransaction();
        try {

            /* 1. Resolve logged-in employee ------------------------------------ */
            $loggedInEmployee = auth()->user()->employee;
            if (!$loggedInEmployee) {
                return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
            }
            if ($loggedInEmployee->active == 0) {
                return response()->json(['code' => 1003, 'message' => 'Your account is inactive. Kindly contact admin.'], 403);
            }

            /* 2. Resolve target employee (QR / ID / face_id / etc.) ----------- */
            if ($column === 'qr_code') {
                $incomingQr = trim($value);
                $employee = Employee::where('qr_code', $incomingQr)->first();

                if ($employee && $employee->active == 0) {
                    return response()->json(['code' => 1003, 'message' => 'Employee account is inactive.'], 403);
                }

                if (!$employee) {
                    if (substr_count($incomingQr, '.') !== 2) {
                        return response()->json(['code' => 1003, 'message' => 'Legacy QR code not recognised.'], 404);
                    }
                    $publicKeyPath = storage_path('app/keys/public.pem');
                    if (!file_exists($publicKeyPath)) {
                        return response()->json(['code' => 1003, 'message' => 'Public key not found on server.'], 500);
                    }
                    try {
                        $decoded = JWT::decode($incomingQr, new Key(file_get_contents($publicKeyPath), 'ES256'));
                    } catch (\Exception $e) {
                        return response()->json(['code' => 1003, 'message' => 'Invalid or tampered QR code.', 'error' => $e->getMessage()], 400);
                    }
                    $orgId = $decoded->o ?? null;
                    if (!$orgId) {
                        return response()->json(['code' => 1003, 'message' => 'QR code expired or invalid.'], 400);
                    }
                    if (!empty($deviceId)) {
                        return response()->json(['code' => 1003, 'message' => 'QR not assigned to any employee.'], 403);
                    }
                    if ($loggedInEmployee->organization_id != $orgId) {
                        return response()->json(['code' => 1003, 'message' => 'QR code belongs to a different organization.'], 400);
                    }
                    if (Employee::where('qr_code', $incomingQr)->exists()) {
                        return response()->json(['code' => 1003, 'message' => 'QR code already linked to another employee.'], 409);
                    }
                    $loggedInEmployee->qr_code = $incomingQr;
                    $loggedInEmployee->save();
                    $employee = $loggedInEmployee;
                }
            } else {
                $employee = Employee::where($column, $value)->firstOrFail();
                if ($employee->active == 0) {
                    return response()->json(['code' => 1003, 'message' => 'Employee account is inactive.'], 403);
                }
            }

            /* 3. Authorisation ------------------------------------------------- */
            $isSelf = $employee->id === $loggedInEmployee->id;
            if (!$isSelf) {
                if ($employee->organization_id !== $loggedInEmployee->organization_id) {
                    return response()->json(['code' => 1003, 'message' => 'Cannot check in employees from another organization.'], 403);
                }
                if (!auth()->user()->can('checkin-other-employees')) {
                    return response()->json(['code' => 1003, 'message' => 'No permission to check in other employees.'], 403);
                }
            }

            /* 4. Shift validation --------------------------------------------- */
            $shift = $employee->shift;
            if (!$shift) {
                return response()->json(['code' => 1003, 'message' => 'No shift assigned. Please contact your manager.'], 403);
            }
            if (!$this->isEmployeeScheduledToday($shift, now())) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'You are not scheduled to work today.',
                    'shift_pattern' => $shift->pattern_type,
                    'scheduled_days' => $shift->pattern_days ?? [],
                ], 403);
            }
            if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
                $label = match ($employee->shift_status) {
                    'off_shift' => 'temporarily off shift',
                    'sick_off' => 'on sick leave',
                    'on_leave' => 'on leave',
                    default => $employee->shift_status,
                };
                return response()->json(['code' => 1003, 'message' => "You cannot check in. You are currently {$label}."], 403);
            }

            /* 5. Resolve check-in time ---------------------------------------- */
            $checkInTimeCarbon = $checkInTime ? Carbon::parse($checkInTime) : now();

            /* 6. Geofence check ----------------------------------------------- */
            $assignment = EmployeeAssignment::where('employee_id', $employee->id)
                ->where('work_location_id', $work_location_id)
                ->where('is_current', true)
                ->first();

            if (!$assignment || !$assignment->location) {
                return response()->json(['code' => 1003, 'message' => 'No assigned work location found.'], 403);
            }
            $workLocation = $assignment->location;
            $distance = $workLocation->distanceTo((float)$latitude, (float)$longitude);
            $radius = $workLocation->radius_m ?? 100;
            if ($distance > $radius) {
                return response()->json(['code' => 1003, 'distance' => $distance, 'radius' => $radius, 'message' => 'You are outside the allowed geofence.'], 403);
            }

            // Also check for a break-checkout attendance (check_out_time IS set but is_break_checkout = true)
            $breakCheckoutAttendance = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNotNull('check_out_time')
                ->where('is_break_checkout', true)
                ->whereDate('date', today())
                ->latest('check_out_time')
                ->first();

            if ($breakCheckoutAttendance && BreakDetector::isEnabled($employee)) {
                /* ---- RETURNING FROM BREAK ------------------------------------ */

                // Close the open break log
                $closedLog = BreakDetector::closeBreakLog($breakCheckoutAttendance, $checkInTimeCarbon);

                // Re-open the attendance record for continued work
                $breakCheckoutAttendance->update([
                    'check_out_time' => null,         // re-open
                    'is_break_checkout' => false,
                    'status' => 'clocked_in',
                    'break_count' => $breakCheckoutAttendance->break_count + 1,
                ]);

                DB::commit();

                $breakMinutes = $closedLog?->actual_duration_minutes ?? 0;

                return response()->json([
                    'code' => 1000,
                    'message' => 'Welcome back! Break recorded.',
                    'type' => 'break_return',
                    'data' => new AttendanceResource($breakCheckoutAttendance->fresh()),
                    'break' => [
                        'duration_minutes' => $breakMinutes,
                        'is_compliant' => $closedLog?->is_compliant ?? true,
                        'excess_minutes' => $closedLog?->excess_minutes ?? 0,
                        'matched_break' => $closedLog?->shiftBreak?->name ?? 'Unscheduled Break',
                    ],
                ], 200);
            }

            /* 8. ---------------------------------------------------------------
               NORMAL FIRST CHECK-IN OF THE DAY
               Prevent double check-in
            ------------------------------------------------------------------- */
            $shiftDurationHours = (float)$shift->duration_hours;
            $maxOvertimeHours = (float)($shift->max_overtime_hours ?? 0);
            $maxShiftWindow = $shiftDurationHours + $maxOvertimeHours + 4;

            $recentUncheckedOut = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->where('is_break_checkout', false)
                ->where('check_in_time', '>=', $checkInTimeCarbon->copy()->subHours($maxShiftWindow))
                ->latest('check_in_time')
                ->first();

            if ($recentUncheckedOut) {
                $lastCheckIn = Carbon::parse($recentUncheckedOut->check_in_time);
                $hoursSince = $lastCheckIn->diffInHours($checkInTimeCarbon);
                return response()->json([
                    'code' => 1003,
                    'message' => "Already checked in from {$lastCheckIn->format('Y-m-d H:i')} ({$hoursSince}h ago). Please check out first.",
                    'last_check_in_time' => $lastCheckIn->toDateTimeString(),
                    'hours_since_check_in' => round($hoursSince, 2),
                ], 409);
            }

            /* 9. Late / grace period ------------------------------------------ */
            $expectedCheckInTime = Carbon::parse($shift->start_time);
            $gracePeriodEndTime = $shift->getGracePeriodEndTime();
            $expectedCheckOutTime = Carbon::parse($shift->end_time);
            $earlyCheckoutThresholdTime = $shift->getEarlyCheckoutThreshold();

            $isLateCheckin = false;
            $minutesLate = 0;
            $withinGracePeriod = false;

            if ($shift->track_late_checkin) {
                $withinGracePeriod = $shift->isWithinGracePeriod($checkInTimeCarbon);
                $isLateCheckin = $shift->isLateCheckIn($checkInTimeCarbon);
                $minutesLate = $shift->getMinutesLate($checkInTimeCarbon);
            }

            /* 10. Create / fill attendance record ------------------------------ */
            $today = today()->toDateString();

            $attendance = Attendance::where('employee_id', $employee->id)
                ->where('date', $today)
                ->whereIn('status', ['absent', 'unchecked_in'])
                ->whereNull('check_in_time')
                ->first() ?? new Attendance(['employee_id' => $employee->id, 'date' => $today]);

            $attendance->status = 'clocked_in';
            $attendance->check_in_time = $checkInTimeCarbon;
            $attendance->latitude = $latitude;
            $attendance->longitude = $longitude;
            $attendance->device_id = $deviceId;
            $attendance->work_location_id = $work_location_id;
            $attendance->is_late_checkin = $isLateCheckin;
            $attendance->minutes_late = $minutesLate;
            $attendance->within_grace_period = $withinGracePeriod;
            $attendance->is_early_checkout = false;
            $attendance->minutes_early = 0;
            $attendance->is_late_checkout = false;
            $attendance->late_checkout_hours = 0;
            $attendance->total_break_minutes = 0;
            $attendance->paid_break_minutes = 0;
            $attendance->excess_break_minutes = 0;
            $attendance->break_count = 0;
            $attendance->is_break_checkout = false;
            $attendance->expected_check_in_time = $expectedCheckInTime->format('H:i:s');
            $attendance->grace_period_end_time = $gracePeriodEndTime->format('H:i:s');
            $attendance->expected_check_out_time = $expectedCheckOutTime->format('H:i:s');
            $attendance->early_checkout_threshold_time = $earlyCheckoutThresholdTime->format('H:i:s');
            $attendance->save();

            DB::commit();

            return response()->json([
                'code' => 1000,
                'message' => 'Check-in successful',
                'type' => 'check_in',
                'data' => new AttendanceResource($attendance),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Check-in failed', $e);
        }
    }


    /**
     * Check if employee is scheduled to work today based on shift pattern
     */
    private function isEmployeeScheduledToday($shift, $date): bool
    {
        $patternType = $shift->pattern_type ?? 'weekdays';
        $patternDays = $shift->pattern_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

        // Get current day abbreviation (Mon, Tue, etc.)
        $currentDay = $date->format('D');

        // Check based on pattern type
        return match ($patternType) {
            'weekdays' => in_array($currentDay, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
            'weekends' => in_array($currentDay, ['Sat', 'Sun']),
            'daily' => true,
            'custom', 'rotating' => in_array($currentDay, $patternDays),
            default => in_array($currentDay, $patternDays),
        };
    }

    /**
     * Get human-readable pattern name
     */
    private function getPatternName(string $patternType): string
    {
        return match ($patternType) {
            'weekdays' => 'Weekdays Only (Monday - Friday)',
            'weekends' => 'Weekends Only (Saturday - Sunday)',
            'daily' => 'Daily (All 7 days)',
            'rotating' => 'Rotating Schedule',
            'custom' => 'Custom Days',
            default => ucfirst($patternType),
        };
    }


    /* ==============================================================
   UPDATED processCheckOut
   Handles two scenarios:
   A) Checkout during a break window  → mark as break_checkout
   B) Final checkout                  → calculate everything and close
   ============================================================== */

    private function processCheckOut(string $value, string $column, string $checkOutTimeInput)
    {
        DB::beginTransaction();
        try {

            /* 1. Resolve employee --------------------------------------------- */
            $employee = Employee::with('organization', 'shift')
                ->where($column, $value)
                ->firstOrFail();

            $loggedInEmployee = auth()->user()->employee;
            if (!$loggedInEmployee) {
                return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
            }

            $isSelf = $employee->id === $loggedInEmployee->id;
            if (!$isSelf) {
                if ($employee->organization_id !== $loggedInEmployee->organization_id) {
                    return response()->json(['code' => 1003, 'message' => 'Cannot check out employees from another organization.'], 403);
                }
                if (!auth()->user()->can('checkin-other-employees')) {
                    return response()->json(['code' => 1003, 'message' => 'No permission to check out other employees.'], 403);
                }
            }

            /* 2. Shift guard -------------------------------------------------- */
            $shift = $employee->shift;
            if (!$shift) {
                return response()->json(['code' => 1003, 'message' => 'No shift assigned to employee.'], 404);
            }

            $checkOutTime = $checkOutTimeInput ? Carbon::parse($checkOutTimeInput) : now();

            /* 3. Find open attendance ----------------------------------------- */
            $shiftDurationHours = (float)$shift->duration_hours;
            $maxOvertimeHours = (float)($shift->max_overtime_hours ?? 0);
            $maxShiftWindow = $shiftDurationHours + $maxOvertimeHours + 4;

            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->where('check_in_time', '>=', $checkOutTime->copy()->subHours($maxShiftWindow))
                ->where('check_in_time', '<=', $checkOutTime)
                ->latest('check_in_time')
                ->first();

            // Fallback: any open record
            if (!$attendance) {
                $attendance = Attendance::where('employee_id', $employee->id)
                    ->whereNotNull('check_in_time')
                    ->whereNull('check_out_time')
                    ->latest('check_in_time')
                    ->first();

                if (!$attendance) {
                    return response()->json(['code' => 1003, 'message' => 'Not checked in or already checked out.'], 409);
                }
            }

            $checkInTime = Carbon::parse($attendance->check_in_time);
            $rawMinutesWorked = $checkInTime->diffInMinutes($checkOutTime, false);

            if ($rawMinutesWorked < 0) {
                return response()->json(['code' => 1003, 'message' => 'Check-out time cannot be before check-in time.'], 400);
            }

            /* 4. ---------------------------------------------------------------
               BREAK WINDOW DETECTION
               If break tracking is enabled and checkout falls in a break window
               → treat as a break checkout, not a final checkout
            ------------------------------------------------------------------- */
            if (BreakDetector::isBreakCheckout($checkOutTime, $employee, $attendance)) {

                // Open a break log
                BreakDetector::openBreakLog($attendance, $checkOutTime, $shift);

                // Mark attendance as "on break" — set check_out_time so we know when break started
                $attendance->update([
                    'check_out_time' => $checkOutTime,
                    'is_break_checkout' => true,
                    'status' => 'on_break',
                ]);

                DB::commit();

                $matchedBreak = BreakDetector::matchBreakWindow($checkOutTime, $shift);

                return response()->json([
                    'code' => 1000,
                    'message' => 'Break started. Check back in when ready.',
                    'type' => 'break_checkout',
                    'data' => new AttendanceResource($attendance->fresh()),
                    'break' => [
                        'matched_break' => $matchedBreak?->name ?? 'Break',
                        'expected_duration' => $matchedBreak?->duration_minutes,
                        'max_duration' => $matchedBreak?->max_duration_minutes ?? $matchedBreak?->duration_minutes,
                        'break_started_at' => $checkOutTime->toDateTimeString(),
                    ],
                ], 200);
            }

            /* 5. ---------------------------------------------------------------
               FINAL CHECKOUT
               Auto-close any still-open break log (employee forgot to check back in)
            ------------------------------------------------------------------- */
            $inProgressBreak = AttendanceBreakLog::where('attendance_id', $attendance->id)
                ->where('status', 'in_progress')
                ->latest('break_start_time')
                ->first();

            if ($inProgressBreak) {
                $breakStart = Carbon::parse($inProgressBreak->break_start_time);
                $actualBreakMins = $breakStart->diffInMinutes($checkOutTime);
                $sb = $inProgressBreak->shiftBreak;
                $maxAllowed = $sb ? ($sb->max_duration_minutes ?? $sb->duration_minutes) : null;
                $excessMins = $maxAllowed !== null ? max(0, $actualBreakMins - $maxAllowed) : 0;

                $inProgressBreak->update([
                    'break_end_time' => $checkOutTime,
                    'actual_duration_minutes' => $actualBreakMins,
                    'excess_minutes' => $excessMins,
                    'is_compliant' => $excessMins === 0,
                    'is_taken' => true,
                    'status' => $excessMins === 0 ? 'completed' : 'exceeded',
                    'notes' => 'Auto-closed on final check-out.',
                ]);
            }

            /* 6. Calculate break deductions ----------------------------------- */
            $deductions = BreakDetector::calculateDeductions($attendance);
            $unpaidMinutes = $deductions['unpaid_minutes'];
            $paidMinutes = $deductions['paid_minutes'];
            $excessMinutes = $deductions['excess_minutes'];
            $breakLogs = $deductions['logs'];

            /* 7. Net worked hours --------------------------------------------- */
            $netMinutesWorked = max(0, $rawMinutesWorked - $unpaidMinutes);
            $hoursWorked = round($netMinutesWorked / 60, 2);
            $effectiveDurationHours = (float)$shift->duration_hours;

            /* 8. Regular + overtime split ------------------------------------- */
            if ($shift->overtime_enabled) {
                $regularHours = min($hoursWorked, $effectiveDurationHours);
                $overtimeHours = min(max(0, $hoursWorked - $effectiveDurationHours), $maxOvertimeHours);
            } else {
                $regularHours = min($hoursWorked, $effectiveDurationHours);
                $overtimeHours = 0;
            }

            /* 9. Apply break penalties ---------------------------------------- */
            foreach ($breakLogs as $log) {
                if ($log->is_compliant || !$log->shiftBreak || $log->excess_minutes <= 0) {
                    continue;
                }
                $excessHours = round($log->excess_minutes / 60, 2);
                switch ($log->shiftBreak->penalty_type) {
                    case 'deduct_overtime':
                        $spill = max(0, $excessHours - $overtimeHours);
                        $overtimeHours = max(0, $overtimeHours - $excessHours);
                        $regularHours = max(0, $regularHours - $spill);
                        break;
                    case 'auto_deduct':
                        $regularHours = max(0, $regularHours - $excessHours);
                        break;
                    case 'flag_review':
                    case 'none':
                    default:
                        break;
                }
            }

            /* 10. Early checkout ---------------------------------------------- */
            $isEarlyCheckout = $shift->isEarlyCheckOut($checkOutTime);
            $minutesEarly = $isEarlyCheckout ? $shift->getMinutesEarly($checkOutTime) : 0;

            /* 11. Late checkout flag ------------------------------------------ */
            $isLateCheckout = $rawMinutesWorked > ($maxShiftWindow * 60);
            $lateHours = $isLateCheckout ? round(($rawMinutesWorked / 60) - $maxShiftWindow, 2) : 0;

            /* 12. Save attendance --------------------------------------------- */
            $attendance->update([
                'status' => 'clocked_out',
                'check_out_time' => $checkOutTime,
                'is_break_checkout' => false,
                'worked_hours' => round($regularHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
                'is_early_checkout' => $isEarlyCheckout,
                'minutes_early' => $minutesEarly,
                'is_late_checkout' => $isLateCheckout,
                'late_checkout_hours' => $lateHours,
                'total_break_minutes' => $unpaidMinutes,
                'paid_break_minutes' => $paidMinutes,
                'excess_break_minutes' => $excessMinutes,
                'break_count' => $deductions['break_count'],
            ]);

            /* 13. Mark pending break logs as skipped -------------------------- */
            AttendanceBreakLog::where('attendance_id', $attendance->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped', 'notes' => 'Employee checked out without taking this break.']);

            /* 14. Create overtime record ------------------------------------- */
            if ($overtimeHours > 0) {
                Overtime::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'date' => $checkOutTime->toDateString(),
                    'start_time' => $checkInTime->copy()->addHours($effectiveDurationHours),
                    'end_time' => $checkOutTime,
                    'hours' => round($overtimeHours, 2),
                    'reason' => 'Auto-calculated on check-out',
                ]);
            }

            /* 15. Commit ------------------------------------------------------- */
            DB::commit();

            return response()->json([
                'code' => 1000,
                'message' => 'Check-out successful',
                'type' => 'final_checkout',
                'data' => new AttendanceResource($attendance->fresh()),
                'break_summary' => [
                    'total_unpaid_break_minutes' => $unpaidMinutes,
                    'total_paid_break_minutes' => $paidMinutes,
                    'total_excess_minutes' => $excessMinutes,
                    'break_count' => $deductions['break_count'],
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Check-out failed', $e);
        }
    }

    public function attendanceHistory(Request $request, $employeeId = null)
    {
        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $departmentId = $request->query('department_id'); // optional department filter
            $all = $request->query('all', false); // optional flag to get all employees

            $loggedInEmployee = auth()->user()->employee;
            if (!$loggedInEmployee) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No employee profile found for the logged-in user.'
                ], 404);
            }

            $query = Attendance::with(['employee.user'])
                ->whereHas('employee', function ($q) use ($loggedInEmployee, $departmentId) {
                    $q->where('organization_id', $loggedInEmployee->organization_id);

                    if ($departmentId) {
                        $q->where('department_id', $departmentId);
                    }
                });

            // Self-request (default)
            if (!$all && !$employeeId) {
                $query->where('employee_id', $loggedInEmployee->id);
            }

            // Specific employee request
            if ($employeeId) {
                $targetEmployee = Employee::findOrFail($employeeId);

                if ($targetEmployee->organization_id !== $loggedInEmployee->organization_id) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You cannot view employees from another organization.'
                    ], 403);
                }

                // Only users with permission can view others
                if ($targetEmployee->id !== $loggedInEmployee->id &&
                    !auth()->user()->can('view-all-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You do not have permission to view other employees attendance.'
                    ], 403);
                }

                $query->where('employee_id', $employeeId);
            }

            // All employees request (for supervisors/managers)
            if ($all) {
                if (!auth()->user()->can('view-all-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You do not have permission to view all employees attendance.'
                    ], 403);
                }
            }

            // Filter by date range
            if ($startDate && $endDate) {
                $query->whereBetween('date', [$startDate, $endDate]);
            }

            $history = $query->orderBy('date', 'desc')->get();

            return response()->json([
                'code' => 1000,
                'message' => 'Attendance history retrieved successfully',
                'data' => AttendanceResource::collection($history)
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error fetching attendance history: ' . $e->getMessage());
            return $this->errorResponse('Error fetching attendance history', $e);
        }
    }


    /**
     * Standard error response
     */
    private function errorResponse(string $message, \Throwable $e): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'code' => 1003,
            'message' => $this->getFriendlyErrorMessage($e, $message),
        ], 500);
    }


    private function getFriendlyErrorMessage(\Throwable $e, string $defaultMessage = 'An unexpected error occurred.'): string
    {
        // Handle Laravel's common exceptions
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return 'Authentication failed. Please log in again.';
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return 'Validation failed. Please check your input.';
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 'Requested resource not found.';
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return 'The requested endpoint was not found.';
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            return 'HTTP method not allowed on this route.';
        }

        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 'You are not authorized to perform this action.';
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            $message = $e->getMessage();

            // Optional: more specific DB error handling
            if (str_contains($message, 'Duplicate entry')) {
                return 'Duplicate data. This record already exists.';
            }

            if (str_contains($message, 'foreign key constraint')) {
                return 'Cannot delete or update because of related data.';
            }

            return 'A database error occurred. Please try again later.';
        }

        // You may also match known substrings if really needed
        if (str_contains($e->getMessage(), 'specific known issue')) {
            return 'A specific known error occurred.';
        }

        // Default fallback
        return $defaultMessage;
    }


}
