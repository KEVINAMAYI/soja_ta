<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{

    public function enroll(Request $request)
    {

        $currentUser = auth()->user();
        $org_id = $currentUser->employee->organization->id;

        // Only supervisors can enroll
        if (!$currentUser->can('enroll-employee')) {
            return response()->json([
                'code' => 1003,
                'message' => 'Only supervisors can enroll new employees.'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|max:255',
            'confirm_password' => 'required|string|max:255|same:password',
            'phone' => 'nullable|string|max:20',
            'department_id' => 'required|exists:departments,id',
            'id_number' => 'required|string|unique:employees,id_number',
            'role_id' => 'required|exists:roles,id', // ✅ validate role_id
        ]);

        DB::beginTransaction();
        try {
            // 1️⃣ Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // 2️⃣ Determine shift_id
            $shiftId = $request->shift_id;
            if (!$shiftId) {
                $firstShift = Shift::where('organization_id', $org_id)
                    ->orderBy('id')
                    ->first();

                $shiftId = $firstShift ? $firstShift->id : null;
            }

            // 3️⃣ Create employee linked to the new user
            $employee = Employee::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'shift_id' => $shiftId,
                'organization_id' => $org_id,
                'id_number' => $request->id_number,
                'active' => true,
                'user_id' => $user->id,
                'department_id' => $request->department_id,
                'face_id' => $request->face_id,
            ]);

            // 4️⃣ Assign the role using role_id
            $role = Role::find($request->role_id);

            if (!$role) {
                DB::rollBack();
                return response()->json([
                    'code' => 1003,
                    'message' => 'Invalid role ID.'
                ], 400);
            }

            $user->assignRole($role->name);

            // 5️⃣ Generate token
            $token = $user->createToken('Api Token')->plainTextToken;

            // 6️⃣ Assign default work location
            $defaultLocation = WorkLocation::where('organization_id', $org_id)
                ->where('is_default', 1)
                ->first();

            if ($defaultLocation) {
                EmployeeAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'work_location_id' => $defaultLocation->id,
                        'start_date' => null,
                        'end_date' => null,
                        'is_current' => true,
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Employee successfully enrolled',
                'data' => new UserResource($user),
                'token' => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Enrollment failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    public function login(Request $request)
    {

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|max:255',
        ]);

        $credentials = request(['email', 'password']);

        if (!Auth::attempt($credentials)) {

            return response()->json([
                'code' => 1003,
                'message' => 'User not Authenticated',
            ], 401);

        }

        $user = User::where('email', $request->email)->first();

        $tokenResult = $user->createToken('Api Token');

        $token = $tokenResult->plainTextToken;

        return response()->json([
            'code' => 1000,
            'message' => 'Login was successful',
            'data' => new UserResource($user),
            'token' => $token,
        ], 200);

    }


    public function logout(Request $request)
    {
        try {
            // Ensure the request has an authenticated user
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No authenticated user found',
                ], 401);
            }

            // Delete all tokens for this user
            $user->tokens()->delete();

            return response()->json([
                'code' => 1000,
                'message' => 'User logged out successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Logout failed. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
