<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Attendance;
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
        ]);

        return $this->processCheckIn($validated['identifier_value'],
            $validated['identifier_type'], $validated['check_in_time'], $validated['latitude'], $validated['longitude']);
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


    /**
     * Handle check-in logic
     */
    private function processCheckIn(string $value, string $column, string $checkInTime, $latitude, $longitude)
    {
        DB::beginTransaction();
        try {

            $employee = Employee::where($column, $value)->firstOrFail();
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
                        'message' => 'You cannot check in employees from another organization.'
                    ], 403);
                }

                if (!auth()->user()->can('manage-employee-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'You do not have permission to check in other employees.'
                    ], 403);
                }
            }

            $today = today()->toDateString();

            // Try to fetch today's attendance (seeded as absent/unchecked_in or none)
            $existing = Attendance::where('employee_id', $employee->id)
                ->whereDate('date', $today)
                ->latest()
                ->first();

            // Prevent check-in if already checked in and not checked out
            if ($existing && $existing->check_in_time && is_null($existing->check_out_time)) {
                return response()->json([
                    'code' => 1004,
                    'message' => 'Already checked in and not checked out.'
                ], 409);
            }

            // Determine whether to use existing record or create new
            if (!$existing || ($existing->check_in_time && $existing->check_out_time)) {
                $attendance = new Attendance([
                    'employee_id' => $employee->id,
                    'date' => $today,
                ]);
            } else {
                $attendance = $existing;
            }

            // Set check-in data
            $attendance->status = 'clocked_in';
            $attendance->check_in_time = $checkInTime ? Carbon::parse($checkInTime) : now();
            $attendance->latitude = $latitude;
            $attendance->longitude = $longitude;
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

                if (!auth()->user()->can('manage-employee-attendance')) {
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
