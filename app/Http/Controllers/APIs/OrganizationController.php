<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrganizationController extends Controller
{
    public function departments(Request $request)
    {
        $user = auth()->user();

        // Restrict access to supervisors
        if (!$user->can('view-departments')) {
            return response()->json([
                'code' => 1003,
                'message' => 'Unauthorized. You have have permission to view all departments.'
            ], 403);
        }

        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'code' => 1003,
                'message' => 'No employee profile found.'
            ], 404);
        }

        $departments = Department::where('organization_id', $employee->organization_id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'code' => 1000,
            'message' => 'Departments retrieved successfully',
            'data' => $departments
        ]);
    }


    public function employees(Request $request)
    {

        $user = auth()->user();

        if (!$user->can('view-employees')) {
            return response()->json([
                'code' => 1003,
                'message' => 'Unauthorized. You have have permission to view all employees.'
            ], 403);
        }

        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'code' => 1003,
                'message' => 'No employee profile found.'
            ], 404);
        }

        $query = Employee::with('department', 'employeeType', 'user')
            ->where('organization_id', $employee->organization_id);

        // Optional: filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $employees = $query->orderBy('name')->get();

        return response()->json([
            'code' => 1000,
            'message' => 'Employees retrieved successfully',
            'data' => UserResource::collection($employees->pluck('user')) // Only return user info formatted
        ], 200);
    }


    public function employeeByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required',
        ]);


        // Step 1: Normalize the phone number input
        $phone = $this->normalizePhoneNumber($request->input('phone'));

        $employee = Employee::where('phone', 'like', '%' . $phone . '%')->first();

        if ($employee) {
            return response()->json([
                'code' => 1000,  // Success code
                'message' => 'Employee retrieved successfully',
                'data' => new UserResource($employee->user),
            ], 200);
        }

        return response()->json([
            'code' => 1003,
            'message' => 'Employee not found.'
        ], 404);
    }


    private function normalizePhoneNumber($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (substr($phone, 0, 1) == '0') {
            $phone = substr($phone, 1);
        }

        return $phone;
    }


    /**
     * Get working hours and overtime for an employee.
     */
    public function workingHours(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
            ]);

            $employee = Employee::findOrFail($request->employee_id);

            return response()->json([
                'code' => 1000,
                'message' => 'Employee working hours retrieved successfully',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'working_hours' => [
                        'weekly_worked_hours' => $employee->weeklyWorkedHours($employee->id),
                        'monthly_worked_hours' => $employee->monthlyWorkedHours($employee->id),
                        'weekly_overtime_hours' => $employee->weeklyOvertimeHours($employee->id),
                    ],
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 422,
                'message' => 'Invalid input',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve working hours', [
                'error' => $e->getMessage(),
                'employee_id' => $request->employee_id ?? null,
            ]);

            return response()->json([
                'code' => 500,
                'message' => 'Failed to retrieve employee working hours',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * GET /api/work-locations
     * List work locations by organization
     */
    public function workLocations(Request $request)
    {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json([
                'code' => 1003,
                'message' => 'User is not linked to an employee record'
            ], 400);
        }

        $employeeId = $request->query('employee_id');

        if ($employeeId) {
            // Get current assignments for this employee
            $assignments = EmployeeAssignment::with('location')
                ->where('employee_id', $employeeId)
                ->where('is_current', true)
                ->get();

            $locations = $assignments->pluck('location')->filter()->values();

            if ($locations->isEmpty()) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No assigned work location found for this employee.'
                ], 403);
            }
        } else {
            // Default: all active locations in the organization
            $locations = WorkLocation::where('organization_id', $employee->organization_id)
                ->where('active', true)
                ->get();
        }

        return response()->json([
            'code' => 1000,
            'data' => $locations
        ]);
    }


    /**
     * POST /api/work-locations/assign
     * Assign or activate an employee's work location
     */
    public function assignWorkLocation(Request $request)
    {
        $request->validate([
            'work_location_id' => 'required|exists:work_locations,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'User is not linked to an employee record'
            ], 400);
        }

        // Delete existing assignment for this employee + location
        EmployeeAssignment::where('employee_id', $employee->id)
            ->where('work_location_id', $request->work_location_id)
            ->delete();

        // Create a new assignment
        $assignment = EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'work_location_id' => $request->work_location_id,
            'start_date' => $request->start_date ?? null,
            'end_date' => $request->end_date ?? null,
            'is_current' => true,
        ]);


        return response()->json([
            'success' => true,
            'message' => 'Work location assigned successfully',
            'data' => $assignment
        ]);
    }


}
