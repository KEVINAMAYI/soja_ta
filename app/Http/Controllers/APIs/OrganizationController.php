<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;


class OrganizationController extends Controller
{
    public function departments(Request $request)
    {
        $user = auth()->user();

        // Restrict access to supervisors
        if (!$user->hasRole('supervisor')) {
            return response()->json([
                'message' => 'Unauthorized. Only supervisors can access this.'
            ], 403);
        }

        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found.'], 404);
        }

        $departments = Department::where('organization_id', $employee->organization_id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Departments retrieved successfully',
            'data' => $departments
        ]);
    }


    public function employees(Request $request)
    {

        $user = auth()->user();

        if (!$user->hasRole('supervisor')) {
            return response()->json([
                'message' => 'Unauthorized. Only supervisors can access this.'
            ], 403);
        }

        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['message' => 'No employee profile found.'], 404);
        }

        $query = Employee::with('department', 'employeeType', 'user')
            ->where('organization_id', $employee->organization_id);

        // Optional: filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $employees = $query->orderBy('name')->get();

        return response()->json([
            'message' => 'Employees retrieved successfully',
            'data' => UserResource::collection($employees->pluck('user')) // Only return user info formatted
        ], 200);
    }



}
