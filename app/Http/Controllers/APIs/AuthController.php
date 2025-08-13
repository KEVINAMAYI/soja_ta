<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{

    public function enroll(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|max:255',
            'confirm_password' => 'required|string|max:255|same:password',
            'phone' => 'nullable|string|max:20',
            'employee_type_id' => 'required|exists:employee_types,id',
            'department_id' => 'required|exists:departments,id',
            'id_number' => 'required|string|unique:employees,id_number',
        ]);

        DB::beginTransaction();
        try {
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // Create employee linked to the new user
            Employee::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'employee_type_id' => $request->employee_type_id,
                'organization_id' => auth()->user()->employee->organization_id,
                'id_number' => $request->id_number,
                'active' => true,
                'user_id' => $user->id,
                'department_id' => $request->department_id,
            ]);

            DB::commit();

            // Generate token for immediate login
            $token = $user->createToken('Api Token')->plainTextToken;

            return response()->json([
                'message' => 'User successfully enrolled',
                'user' => new UserResource($user),
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Enrollment failed',
                'details' => $e->getMessage()
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
                'error' => 'User not Authenticated',
            ], 401);

        }

        $user = User::where('email', $request->email)->first();

        $tokenResult = $user->createToken('Api Token');

        $token = $tokenResult->plainTextToken;

        return response()->json([
            'message' => 'Login was successful',
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);

    }


    public function logout(Request $request)
    {

        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'User logged in and out successfully'
        ], 200);
    }

}
