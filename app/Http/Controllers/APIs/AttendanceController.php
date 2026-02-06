<?php

namespace App\Http\Controllers\APIs;

use App\Helpers\ServerTime;
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
use App\Models\OrganizationShiftSetting;

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


    private function processCheckIn(
        string $value,
        string $column,
        string $checkInTime,
               $latitude,
               $longitude,
               $work_location_id,
               $deviceId = null,
               $manualShiftId = null
    )
    {
        DB::beginTransaction();

        try {
            $loggedInEmployee = auth()->user()->employee;

            if (!$loggedInEmployee) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No employee profile found.'
                ], 404);
            }

            if ($loggedInEmployee->active == 0) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Your account is inactive. Kindly Contact admin.',
                ], 403);
            }

            // ==========================================
            // STEP 1: IDENTIFY EMPLOYEE (QR/ID/FACE)
            // ==========================================
            $employee = null;

            if ($column === 'qr_code') {
                $incomingQr = trim($value);

                // Try to find employee by QR directly (DB first)
                $employee = Employee::where('qr_code', $incomingQr)->first();

                if ($employee && $employee->active == 0) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Employee account is inactive. Kindly Contact admin.',
                    ], 403);
                }

                if (!$employee) {
                    // Only if not found, decide if legacy or new JWT QR
                    if (substr_count($incomingQr, '.') !== 2) {
                        // Legacy 5-char (no dots, old style)
                        return response()->json([
                            'code' => 1003,
                            'message' => 'Legacy QR code not recognized or not linked to any employee.'
                        ], 404);
                    }

                    // Signed JWT QR (new style)
                    $publicKeyPath = storage_path('app/keys/public.pem');
                    if (!file_exists($publicKeyPath)) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'Public key not found on server.'
                        ], 500);
                    }

                    try {
                        $decoded = JWT::decode($incomingQr, new Key(file_get_contents($publicKeyPath), 'ES256'));
                    } catch (\Exception $e) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'Invalid or tampered QR code.',
                            'error' => $e->getMessage(),
                        ], 400);
                    }

                    $orgId = $decoded->o ?? null;

                    if (!$orgId) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'QR code expired or invalid.'
                        ], 400);
                    }

                    // Handle unassigned QR cases
                    if (!empty($deviceId)) {
                        // Supervisor/admin scanning from device
                        return response()->json([
                            'code' => 1003,
                            'message' => 'QR code is valid but not yet assigned to any employee. Please assign it before use.'
                        ], 403);
                    }

                    // Regular employee scanning (self-assignment)
                    if ($loggedInEmployee->organization_id != $orgId) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'QR code belongs to a different organization.'
                        ], 400);
                    }

                    // Check if code already assigned
                    if (Employee::where('qr_code', $incomingQr)->exists()) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'This QR code is already linked to another employee.'
                        ], 409);
                    }

                    // Assign QR to logged-in employee (self registration)
                    $loggedInEmployee->qr_code = $incomingQr;
                    $loggedInEmployee->save();

                    $employee = $loggedInEmployee;
                }
            } else {
                // Normal check-in (ID number, face_id, etc.)
                $employee = Employee::where($column, $value)->firstOrFail();

                if ($employee && $employee->active == 0) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Employee account is inactive. Kindly Contact admin.',
                    ], 403);
                }
            }

            // ==========================================
            // STEP 2: AUTHORIZATION CHECK
            // ==========================================
            $isSelf = $employee->id === $loggedInEmployee->id;

            if (!$isSelf) {
                if ($employee->organization_id !== $loggedInEmployee->organization_id) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You cannot check in employees from another organization.'
                    ], 403);
                }

                if (!auth()->user()->can('checkin-other-employees')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You do not have permission to check in other employees.'
                    ], 403);
                }
            }

            $checkInTimeCarbon = Carbon::parse($checkInTime);

            // ==========================================
            // STEP 3: SHIFT DETECTION/SELECTION
            // ==========================================
            $orgSettings = OrganizationShiftSetting::getForOrganization($employee->organization_id);
            $shiftDetectionResult = null;
            $selectedShift = null;
            $detectionMethod = 'unknown';

            // Case 1: Manual shift selection provided
            if ($manualShiftId) {
                if (!$orgSettings->allow_manual_shift_selection) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Manual shift selection is not allowed for your organization.',
                    ], 403);
                }

                // Verify the shift is assigned to the employee
                $manualShift = $employee->activeShifts()->where('shifts.id', $manualShiftId)->first();

                if (!$manualShift) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'The selected shift is not assigned to you or is not active.',
                        'available_shifts' => $employee->activeShifts()->get(['id', 'name', 'start_time', 'end_time']),
                    ], 403);
                }

                $selectedShift = $manualShift;
                $detectionMethod = 'manual';

                $shiftDetectionResult = [
                    'shift' => $selectedShift,
                    'auto_detected' => false,
                    'method' => 'manual',
                    'log' => [
                        'detection_method' => 'manual',
                        'reason' => 'Shift manually selected by user',
                        'shift_id' => $selectedShift->id,
                        'shift_name' => $selectedShift->name,
                    ],
                ];
            }
            // Case 2: Auto-detection or fallback
            else {
                $shiftDetectionResult = $employee->detectShiftForTime($checkInTimeCarbon);

                if (!$shiftDetectionResult['shift']) {
                    // Provide helpful error based on detection method
                    $errorMessage = match($shiftDetectionResult['method']) {
                        'fallback' => 'No shift assigned. Please contact your manager.',
                        'failed' => 'Could not automatically detect your shift. Multiple shifts available but none scored high enough.',
                        'none' => 'No shift found for this check-in time.',
                        default => 'Unable to determine shift. Please contact your manager.',
                    };

                    $responseData = [
                        'code' => 1003,
                        'message' => $errorMessage,
                        'detection_log' => $shiftDetectionResult['log'],
                    ];

                    // If manual selection is allowed, provide available shifts
                    if ($orgSettings->allow_manual_shift_selection) {
                        $responseData['available_shifts'] = $employee->activeShifts()
                            ->get(['id', 'name', 'start_time', 'end_time'])
                            ->map(function($shift) {
                                return [
                                    'id' => $shift->id,
                                    'name' => $shift->name,
                                    'start_time' => $shift->start_time,
                                    'end_time' => $shift->end_time,
                                ];
                            });
                        $responseData['message'] .= ' You can manually select a shift.';
                    }

                    return response()->json($responseData, 403);
                }

                $selectedShift = $shiftDetectionResult['shift'];
                $detectionMethod = $shiftDetectionResult['method'];
            }

            // ==========================================
            // STEP 4: SHIFT CHANGE COOLDOWN CHECK
            // ==========================================
            if ($employee->current_shift_id &&
                $employee->current_shift_id != $selectedShift->id) {

                if (!$employee->canChangeShift()) {
                    $cooldownEnds = $employee->getShiftChangeCooldownRemaining();
                    $minutesRemaining = now()->diffInMinutes($cooldownEnds);

                    return response()->json([
                        'code' => 1003,
                        'message' => 'Shift change cooldown active. You recently changed shifts.',
                        'current_shift' => [
                            'id' => $employee->currentShift->id,
                            'name' => $employee->currentShift->name,
                        ],
                        'attempted_shift' => [
                            'id' => $selectedShift->id,
                            'name' => $selectedShift->name,
                        ],
                        'cooldown_ends_at' => $cooldownEnds->toDateTimeString(),
                        'minutes_remaining' => $minutesRemaining,
                    ], 403);
                }

                // Update current shift (cooldown passed)
                $employee->switchToShift($selectedShift);

            } else if (!$employee->current_shift_id) {
                // First time assignment
                $employee->current_shift_id = $selectedShift->id;
                $employee->save();
            }

            // ==========================================
            // STEP 5: VALIDATE SHIFT PATTERN (DAY CHECK)
            // ==========================================
            $dayOfWeek = $checkInTimeCarbon->format('D');
            $isScheduledToday = $this->isEmployeeScheduledToday($selectedShift, $checkInTimeCarbon);

            if (!$isScheduledToday) {
                $patternName = $this->getPatternName($selectedShift->pattern_type);

                return response()->json([
                    'code' => 1003,
                    'message' => "You are not scheduled to work today on the '{$selectedShift->name}' shift.",
                    'shift_name' => $selectedShift->name,
                    'shift_pattern' => $patternName,
                    'scheduled_days' => $selectedShift->pattern_days ?? [],
                    'today' => $dayOfWeek,
                ], 403);
            }

            // ==========================================
            // STEP 6: CHECK OFF-SHIFT STATUS
            // ==========================================
            if (in_array($employee->shift_status, ['off_shift', 'sick_off', 'on_leave'])) {
                $statusLabel = match ($employee->shift_status) {
                    'off_shift' => 'temporarily off shift',
                    'sick_off' => 'on sick leave',
                    'on_leave' => 'on leave',
                    default => $employee->shift_status,
                };

                return response()->json([
                    'code' => 1003,
                    'message' => "You cannot check in. You are currently {$statusLabel}.",
                    'shift_status' => $employee->shift_status,
                    'end_date' => $employee->end_off_shift_date,
                ], 403);
            }

            // ==========================================
            // STEP 7: CHECK FOR UNCHECKED-OUT ATTENDANCE
            // ==========================================
            $shiftDurationHours = (float)$selectedShift->duration_hours;
            $maxOvertimeHours = (float)($selectedShift->max_overtime_hours ?? 0);
            $bufferHours = 4;
            $maxShiftWindow = $shiftDurationHours + $maxOvertimeHours + $bufferHours;

            $recentUncheckedOut = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->where('check_in_time', '>=', $checkInTimeCarbon->copy()->subHours($maxShiftWindow))
                ->latest('check_in_time')
                ->first();

            if ($recentUncheckedOut) {
                $lastCheckIn = Carbon::parse($recentUncheckedOut->check_in_time);
                $hoursSinceCheckIn = $lastCheckIn->diffInHours($checkInTimeCarbon);

                return response()->json([
                    'code' => 1003,
                    'message' => "You are already checked in from {$lastCheckIn->format('Y-m-d H:i')} ({$hoursSinceCheckIn} hours ago). Please check out first.",
                    'last_check_in_time' => $lastCheckIn->toDateTimeString(),
                    'hours_since_check_in' => round($hoursSinceCheckIn, 2),
                    'last_shift' => $recentUncheckedOut->shift?->name,
                ], 409);
            }

            // ==========================================
            // STEP 8: CALCULATE SHIFT TIMES & LATE STATUS
            // ==========================================
            $expectedCheckInTime = Carbon::parse($selectedShift->start_time);
            $gracePeriodEndTime = $selectedShift->getGracePeriodEndTime();
            $expectedCheckOutTime = Carbon::parse($selectedShift->end_time);
            $earlyCheckoutThresholdTime = $selectedShift->getEarlyCheckoutThreshold();

            // Late tracking
            $isLateCheckin = false;
            $minutesLate = 0;
            $withinGracePeriod = false;

            if ($selectedShift->track_late_checkin) {
                $withinGracePeriod = $selectedShift->isWithinGracePeriod($checkInTimeCarbon);
                $isLateCheckin = $selectedShift->isLateCheckIn($checkInTimeCarbon);
                $minutesLate = $selectedShift->getMinutesLate($checkInTimeCarbon);
            }

            // ==========================================
            // STEP 9: GEOFENCE CHECK
            // ==========================================
            $assignment = EmployeeAssignment::where('employee_id', $employee->id)
                ->where('work_location_id', $work_location_id)
                ->where('is_current', true)
                ->first();

            if (!$assignment || !$assignment->location) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No assigned work location found.'
                ], 403);
            }

            $workLocation = $assignment->location;
            $distance = $workLocation->distanceTo((float)$latitude, (float)$longitude);
            $radius = $workLocation->radius_m ?? 100;

            if ($distance > $radius) {
                return response()->json([
                    'code' => 1003,
                    'distance' => round($distance, 2),
                    'radius' => $radius,
                    'message' => 'You are outside the allowed geofence. Move closer to your work location.'
                ], 403);
            }

            // ==========================================
            // STEP 10: CREATE ATTENDANCE RECORD
            // ==========================================
            $today = today()->toDateString();

            $attendance = new Attendance([
                'employee_id' => $employee->id,
                'shift_id' => $selectedShift->id,
                'date' => $today,
                'status' => 'clocked_in',
                'check_in_time' => $checkInTimeCarbon,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'device_id' => $deviceId,
                'work_location_id' => $work_location_id,

                // Shift detection metadata
                'auto_shift_detected' => in_array($detectionMethod, ['auto_detection', 'single_shift']),
                'shift_detection_method' => $detectionMethod,
                'shift_detection_log' => json_encode($shiftDetectionResult['log'] ?? []),

                // Late tracking
                'is_late_checkin' => $isLateCheckin,
                'minutes_late' => $minutesLate,
                'within_grace_period' => $withinGracePeriod,

                // Early/late checkout tracking (set initial values)
                'is_early_checkout' => false,
                'minutes_early' => 0,
                'is_late_checkout' => false,
                'late_checkout_hours' => 0,

                // Expected times from shift
                'expected_check_in_time' => $expectedCheckInTime->format('H:i:s'),
                'grace_period_end_time' => $gracePeriodEndTime->format('H:i:s'),
                'expected_check_out_time' => $expectedCheckOutTime->format('H:i:s'),
                'early_checkout_threshold_time' => $earlyCheckoutThresholdTime->format('H:i:s'),
            ]);

            $attendance->save();

            DB::commit();

            // ==========================================
            // STEP 11: RETURN SUCCESS RESPONSE
            // ==========================================
            $responseData = [
                'code' => 1000,
                'message' => 'Check-in successful',
                'shift_info' => [
                    'id' => $selectedShift->id,
                    'name' => $selectedShift->name,
                    'detection_method' => $detectionMethod,
                    'auto_detected' => in_array($detectionMethod, ['auto_detection', 'single_shift']),
                    'score' => $shiftDetectionResult['score'] ?? null,
                ],
                'data' => new AttendanceResource($attendance),
            ];

            // Add warning if late
            if ($isLateCheckin && !$withinGracePeriod) {
                $responseData['warning'] = "You checked in {$minutesLate} minutes late.";
            } else if ($isLateCheckin && $withinGracePeriod) {
                $responseData['info'] = "You checked in within the grace period.";
            }

            return response()->json($responseData, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Check-in error: ' . $e->getMessage(), [
                'employee_id' => $employee->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Check-in failed', $e);
        }
    }


    /**
     * ========================================
     * HELPER METHODS - Add to your controller
     * ========================================
     */

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


    /**
     * Handle check-out logic
     */
    private function processCheckOut(string $value, string $column, string $checkOutTimeInput)
    {
        DB::beginTransaction();
        try {
            $employee = Employee::with('organization', 'shift')
                ->where($column, $value)
                ->firstOrFail();

            $loggedInEmployee = auth()->user()->employee;

            if (!$loggedInEmployee) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No employee profile found.'
                ], 404);
            }

            $isSelf = $employee->id === $loggedInEmployee->id;

            if (!$isSelf) {
                if ($employee->organization_id !== $loggedInEmployee->organization_id) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You cannot check out employees from another organization.'
                    ], 403);
                }

                if (!auth()->user()->can('checkin-other-employees')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You do not have permission to check out other employees.'
                    ], 403);
                }
            }

            $shift = $employee->shift;

            if (!$shift) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No shift assigned to employee.'
                ], 404);
            }

            $checkOutTime = $checkOutTimeInput ? Carbon::parse($checkOutTimeInput) : now();

            // ✅ Calculate maximum possible shift duration
            // shift duration + max overtime + buffer for delays
            $shiftDurationHours = (float)$shift->duration_hours;
            $maxOvertimeHours = (float)($shift->max_overtime_hours ?? 0);
            $bufferHours = 4; // Grace period for late checkouts

            $maxShiftWindow = $shiftDurationHours + $maxOvertimeHours + $bufferHours;

            // ✅ First try: Look for unchecked attendance within the shift's maximum duration
            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->where('check_in_time', '>=', $checkOutTime->copy()->subHours($maxShiftWindow))
                ->where('check_in_time', '<=', $checkOutTime)
                ->latest('check_in_time')
                ->first();

            // ✅ If not found within window, check for ANY older unchecked attendance
            if (!$attendance) {

                $attendance = Attendance::where('employee_id', $employee->id)
                    ->whereNotNull('check_in_time')
                    ->whereNull('check_out_time')
                    ->latest('check_in_time')
                    ->first();

                if (!$attendance) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Not checked in or already checked out.'
                    ], 409);
                }

                // ✅ Found old attendance - allow checkout but warn/flag it
                $checkInTime = Carbon::parse($attendance->check_in_time);
                $minutesWorked = $checkInTime->diffInMinutes($checkOutTime, false);
                $hoursWorked = round($minutesWorked / 60, 2);


                // Add flag that this is a late checkout
                $isLateCheckout = true;
                $lateCheckoutMessage = "Warning: Checking out {$hoursWorked} hours after check-in (expected max: {$maxShiftWindow} hours).";

            } else {
                // Normal checkout within expected window
                $checkInTime = Carbon::parse($attendance->check_in_time);
                $minutesWorked = $checkInTime->diffInMinutes($checkOutTime, false);
                $hoursWorked = round($minutesWorked / 60, 2);
                $isLateCheckout = false;
                $lateCheckoutMessage = null;

            }

            // ✅ Validate checkout time is not before check-in
            if ($hoursWorked < 0) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Check-out time cannot be before check-in time.'
                ], 400);
            }

            // ================================
            // SHIFT SETTINGS & CALCULATIONS
            // ================================
            $shiftStart = Carbon::parse($shift->start_time);
            $shiftEnd = Carbon::parse($shift->end_time);
            if ($shiftEnd->lt($shiftStart)) {
                $shiftEnd->addDay();
            }

            $durationHours = (float)$shift->duration_hours;
            $maxOvertimeHours = (float)$shift->max_overtime_hours;


            // ================================
            // EARLY CHECKOUT LOGIC
            // ================================
            $isEarlyCheckout = $shift->isEarlyCheckOut($checkOutTime);
            $minutesEarly = $isEarlyCheckout ? $shift->getMinutesEarly($checkOutTime) : 0;

            // ================================
            // CALCULATE HOURS (MATCH AUTO-CLOCKOUT)
            // ================================
            if ($shift->overtime_enabled) {
                // Regular hours capped at duration_hours
                $regularHours = min($hoursWorked, $durationHours);

                // Overtime = worked - duration hours
                $overtimeHours = max(0, $hoursWorked - $durationHours);

                // Enforce max overtime cap
                if ($maxOvertimeHours > 0) {
                    $overtimeHours = min($overtimeHours, $maxOvertimeHours);
                }
            } else {
                // If OT disabled → no overtime
                $regularHours = min($hoursWorked, $durationHours);
                $overtimeHours = 0;
            }

            // ================================
            // SAVE ATTENDANCE
            // ================================
            $attendance->update([
                'status' => 'clocked_out',
                'check_out_time' => $checkOutTime,
                'worked_hours' => round($regularHours , 2),
                'overtime_hours' => round($overtimeHours, 2),
                'is_early_checkout' => $isEarlyCheckout,
                'minutes_early' => $minutesEarly,
                'is_late_checkout' => $isLateCheckout ?? false,
                'late_checkout_hours' => $isLateCheckout ? round($hoursWorked - $maxShiftWindow, 2) : 0,
            ]);

            // ================================
            // CREATE OVERTIME RECORD
            // ================================
            if ($overtimeHours > 0) {
                Overtime::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'date' => $checkOutTime->toDateString(),
                    'start_time' => $checkInTime->copy()->addHours($durationHours),
                    'end_time' => $checkOutTime,
                    'hours' => round($overtimeHours, 2),
                    'reason' => 'Auto-calculated on checkout',
                ]);
            }

            DB::commit();

            $responseData = [
                'message' => 'Check-out successful',
                'data' => new AttendanceResource($attendance)
            ];

            // Add warning message if it's a late checkout
            if ($isLateCheckout && $lateCheckoutMessage) {
                $responseData['warning'] = $lateCheckoutMessage;
                $responseData['hours_since_checkin'] = round($hoursWorked, 2);
                $responseData['expected_max_hours'] = $maxShiftWindow;
            }

            return response()->json($responseData, 200);

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

            var_dump($message);

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
