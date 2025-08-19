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

            $alreadyIn = Attendance::where('employee_id', $employee->id)
                ->whereNull('check_out_time')
                ->exists();

            if ($alreadyIn) {
                return response()->json(['message' => 'Already checked in.'], 409);
            }

            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'date' => $checkInTime ? Carbon::parse($checkInTime)->toDateString() : today()->toDateString(),
                'check_in_time' => $checkInTime ? Carbon::parse($checkInTime) : now(),
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);

            DB::commit();

            return response()->json([
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

            $employee = Employee::with('organization')
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


            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereNull('check_out_time')
                ->first();

            if (!$attendance) {
                return response()->json(['message' => 'Not checked in or already checked out.'], 409);
            }

            $org = $employee->organization;
            $standardHours = (float)$org->getSetting('daily_required_hours', 8);
            $otThreshold = (float)$org->getSetting('min_ot_threshold', 0);

            $checkInTime = Carbon::parse($attendance->check_in_time);
            $checkOutTime = $checkOutTime ? Carbon::parse($checkOutTime) : now();
            $workedHours = $checkInTime->diffInMinutes($checkOutTime) / 60;
            $overtimeHours = max(0, $checkInTime->copy()->addHours($standardHours)->diffInMinutes($checkOutTime) / 60);

            if ($overtimeHours < $otThreshold) {
                $overtimeHours = 0;
            }

            $attendance->update([
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
                        'message' => 'You cannot view employees from another organization.'
                    ], 403);
                }

                // Only users with permission can view others
                if ($targetEmployee->id !== $loggedInEmployee->id &&
                    !auth()->user()->can('view-all-attendance')) {
                    return response()->json([
                        'message' => 'You do not have permission to view other employees attendance.'
                    ], 403);
                }

                $query->where('employee_id', $employeeId);
            }

            // All employees request (for supervisors/managers)
            if ($all) {
                if (!auth()->user()->can('view-all-attendance')) {
                    return response()->json([
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
    private function errorResponse(string $message, \Exception $e)
    {
        return response()->json([
            'code' => 1003,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error'
        ], 500);
    }


}
