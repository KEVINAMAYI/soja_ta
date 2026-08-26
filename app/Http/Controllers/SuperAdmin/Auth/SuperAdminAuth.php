<?php

namespace App\Http\Controllers\SuperAdmin\Auth;

use App\Http\Controllers\Controller;
use App\Helpers\ThrottlerHelper;
use App\Http\Payload\SuperAdmin\LoginRequestDTO;
use App\Http\Requests\SuperAdmin\LoginRequest;
use App\Http\Resources\SuperAdmin\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAuth extends Controller
{
    public function login(LoginRequest $request)
    {
        $dto = LoginRequestDTO::fromRequest($request);

        Log::warning('Super admin login request received.', $dto->toLogContext());


        $credentials = $request->only('email', 'password');

        ThrottlerHelper::hit($request->only('email'));

        if (!Auth::attempt($credentials)) {
            // Simulate role check for super admin to waste time for unauthorized users

            return response()->json([
                'code' => 1003,
                'message' => 'User not Authenticated',
            ], 401);
        }

        $user = User::where('email', $request->email)->first();

        // Check if user has super admin role
        if (!$user || !$user->hasRole('super-admin')) {
            // simulate token creation to waste time for unauthorized users

            return response()->json([
                'code' => 1004,
                'message' => 'User is not a super admin',
            ], 403);
        }

        $tokenResult = $user->createToken('Api Token');
        $token = $tokenResult->plainTextToken;

        $data = (new UserResource($user))->resolve();
        $data['token'] = $token;


        ThrottlerHelper::clear([$request->email, $request->ip()]);

        $made_response = ApiResponse::success(
            code: 1000,
            data: $data,
            message: 'Super Admin login successful.',
            httpStatusCode: Response::HTTP_OK,
        );
        return $made_response;
    }


}
