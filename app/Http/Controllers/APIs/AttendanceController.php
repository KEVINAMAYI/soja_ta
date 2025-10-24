<?php

namespace App\Http\Controllers\APIs;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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
            'check_in_time' => 'required|date',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'device_id' => 'nullable|exists:devices,id',
            'work_location_id' => 'required|exists:work_locations,id',
        ]);

        return $this->processCheckIn($validated['identifier_value'],
            $validated['identifier_type'], $validated['check_in_time'], $validated['latitude'], $validated['longitude'], $validated['work_location_id'], $validated['device_id'] ?? null);
    }

    /**
     * Employee Check-Out
     */
    public function checkOut(Request $request)
    {
        $validated = $request->validate([
            'identifier_type' => 'required|in:id_number,qr_code,face_id',
            'identifier_value' => 'required|string',
            'check_out_time' => 'required|date'
        ]);

        return $this->processCheckOut($validated['identifier_value'], $validated['identifier_type'], $validated['check_out_time']);
    }


    private function processCheckIn(string $value, string $column, string $checkInTime, $latitude, $longitude, $work_location_id, $deviceId = null)
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

            /**
             * ✅ Case 1: Check-in via QR Code (JWT or Legacy)
             */
            if ($column === 'qr_code') {
                $incomingQr = trim($value);
                $employee = null;

                // 🧩 CASE A: Legacy 5-char (no dots, old style)
                if (substr_count($incomingQr, '.') !== 2) {
                    $employee = Employee::where('qr_code', $incomingQr)->first();

                    if (!$employee) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'Legacy QR code not recognized.'
                        ], 404);
                    }
                } // 🧩 CASE B: Signed JWT QR (new style)
                else {

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

                    // Find employee under the same organization
                    $employee = Employee::where('organization_id', $orgId)
                        ->where('id', $loggedInEmployee->id)
                        ->first();

                    if (!$employee) {
                        return response()->json([
                            'code' => 1003,
                            'message' => 'Employee not found for this organization.'
                        ], 404);
                    }

                    // If employee already has a QR code, it must match
                    if (!empty($employee->qr_code)) {
                        if ($employee->qr_code !== $incomingQr) {
                            return response()->json([
                                'code' => 1003,
                                'message' => 'This QR code does not belong to this employee.'
                            ], 403);
                        }
                    } else {
                        // Assign the verified QR to employee
                        $employee->qr_code = $incomingQr;
                        $employee->save();
                    }
                }
            } /**
             * ✅ Case 2: Normal check-in (ID number, face_id, etc.)
             */
            else {
                $employee = Employee::where($column, $value)->firstOrFail();
            }

            /**
             * ✅ Authorization and Organization Validation
             */
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

            /**
             * ✅ 1. Fetch assigned work location (geofence check)
             */
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
                    'distance' => $distance,
                    'radius' => $radius,
                    'message' => 'You are outside the allowed geofence. Move closer to your work location.'
                ], 403);
            }

            /**
             * ✅ 2. Prevent duplicate check-ins
             */
            $today = today()->toDateString();
            $existing = Attendance::where('employee_id', $employee->id)
                ->whereDate('date', $today)
                ->latest()
                ->first();

            if ($existing && $existing->check_in_time && is_null($existing->check_out_time)) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Already checked in and not checked out.'
                ], 409);
            }

            /**
             * ✅ 3. Create or update attendance record
             */
            $attendance = $existing && !$existing->check_out_time
                ? $existing
                : new Attendance([
                    'employee_id' => $employee->id,
                    'date' => $today,
                ]);

            $attendance->status = 'clocked_in';
            $attendance->check_in_time = $checkInTime ? Carbon::parse($checkInTime) : now();
            $attendance->latitude = $latitude;
            $attendance->longitude = $longitude;
            $attendance->device_id = $deviceId;
            $attendance->work_location_id = $work_location_id; // ✅ new line
            $attendance->save();

            DB::commit();

            return response()->json([
                'code' => 1000,
                'message' => 'Check-in successful',
                'data' => new AttendanceResource($attendance)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Check-in failed', $e);
        }
    }


    /**
     * Handle check-out logic
     */
    private function processCheckOut(string $value, string $column, string $checkOutTime)
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


            $today = today()->toDateString();

            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereDate('date', $today)
                ->whereNull('check_out_time')
                ->latest()
                ->first();

            if (!$attendance || !$attendance->check_in_time) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Not checked in or already checked out.'
                ], 409);
            }

            $org = $employee->organization;
            $standardHours = $employee->shift->duration;
            $otThreshold = (float)$org->getSetting('min_ot_threshold', 0);

            $checkInTime = Carbon::parse($attendance->check_in_time);
            $checkOutTime = $checkOutTime ? Carbon::parse($checkOutTime) : now();
            $workedHours = $checkInTime->diffInMinutes($checkOutTime) / 60;
            $overtimeHours = max(0, $checkInTime->copy()->addHours($standardHours)->diffInMinutes($checkOutTime) / 60);

            if ($overtimeHours < $otThreshold) {
                $overtimeHours = 0;
            }

            $attendance->update([
                'status' => 'clocked_out',
                'check_out_time' => $checkOutTime,
                'worked_hours' => round($workedHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
            ]);

            if ($overtimeHours >= $otThreshold) {
                Overtime::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'date' => $checkOutTime->toDateString(),
                    'start_time' => $checkInTime->copy()->addHours($standardHours),
                    'end_time' => $checkOutTime,
                    'hours' => round($overtimeHours, 2),
                    'reason' => 'Auto-generated on checkout',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Check-out successful',
                'data' => new AttendanceResource($attendance)
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
