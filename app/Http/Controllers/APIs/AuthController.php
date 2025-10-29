<?php

namespace App\Http\Controllers\APIs;

use App\Helpers\PhoneSanitizer;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Otp;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\AfricasTalkingSmsService;
use Illuminate\Support\Facades\Cache;


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

            $phone = PhoneSanitizer::sanitize($request->phone);

            // 3️⃣ Create employee linked to the new user
            $employee = Employee::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $phone,
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


    /**
     * Send OTP to user's registered phone number.
     */
    public function sendOtp(Request $request, AfricasTalkingSmsService $smsService)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $phone = PhoneSanitizer::sanitize($request->phone);

        $employee = Employee::where('phone', $phone)->first();

        if (!$employee) {
            return response()->json([
                'code' => 1003,
                'message' => 'Phone number not found in employee records.'
            ], 404);
        }

        $otpCode = rand(100000, 999999);

        // Store in DB with 5 min expiry
        Otp::updateOrCreate(
            ['phone' => $phone],
            ['otp' => $otpCode, 'expires_at' => now()->addMinutes(5)]
        );

        try {
            $smsService->sendSms($phone, "Your login OTP code is: {$otpCode}");
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'code' => 1000,
            'message' => 'OTP sent successfully',
        ], 200);
    }

    /**
     * Login using OTP code.
     */
    public function loginViaOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|digits:6',
        ]);

        $phone = PhoneSanitizer::sanitize($request->phone);

        $otp = Otp::where('phone', $phone)->latest()->first();

        if (!$otp || $otp->otp !== $request->otp || $otp->isExpired()) {
            return response()->json([
                'code' => 1003,
                'message' => 'Invalid or expired OTP',
            ], 401);
        }

        $employee = Employee::where('phone', $phone)->first();

        if (!$employee || !$employee->user) {
            return response()->json([
                'code' => 1003,
                'message' => 'No user found for this phone number.',
            ], 404);
        }

        // Delete OTP after successful use
        $otp->delete();

        $user = $employee->user;
        $token = $user->createToken('Api Token')->plainTextToken;

        return response()->json([
            'code' => 1000,
            'message' => 'Login successful via OTP',
            'data' => new UserResource($user),
            'token' => $token,
        ], 200);
    }

    /**
     * Login using Face ID.
     */
    public function loginViaFaceId(Request $request)
    {
        $request->validate([
            'face_id' => 'required|string',
        ]);

        $employee = Employee::where('face_id', $request->face_id)->first();

        if (!$employee || !$employee->user) {
            return response()->json([
                'code' => 1003,
                'message' => 'Invalid face ID or employee not found.',
            ], 404);
        }

        $user = $employee->user;
        $token = $user->createToken('Api Token')->plainTextToken;

        return response()->json([
            'code' => 1000,
            'message' => 'Login successful via Face ID',
            'data' => new UserResource($user),
            'token' => $token,
        ], 200);
    }


    /**
     * Login using Email and Password.
     */
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


    public function assignToken(Request $request)
    {
        try {
            // Validate request data
            $validated = $request->validate([
                'employee_id' => 'required|integer|exists:employees,id',
                'qr_code' => 'required|string|max:255',
            ]);

            $employeeId = $validated['employee_id'];
            $qrCode = trim($validated['qr_code']);

            // Check if this QR code is already assigned to someone else
            $existingQr = Employee::where('qr_code', $qrCode)
                ->where('id', '!=', $employeeId)
                ->first();

            if ($existingQr) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'This token is already assigned to another employee.',
                ], 409);
            }

            // Check if this employee already has a token assigned
            $employee = Employee::findOrFail($employeeId);

            if (!empty($employee->qr_code)) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'This employee already has a token assigned.',
                ], 409);
            }

            // Assign the new QR code
            $employee->update(['qr_code' => $qrCode]);

            return response()->json([
                'code' => 1000,
                'message' => 'Token Assigned  successful',
                'data' => new UserResource($employee->user),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'code' => 1003,
                'message' => 'An error occurred while assigning the token.',
            ], 500);
        }
    }


}
