<?php

namespace App\Http\Controllers\SuperAdmin\Auth;

use App\Http\Controllers\Controller;
use App\Helpers\ThrottlerHelper;
use App\Http\Payload\SuperAdmin\LoginRequestDTO;
use App\Http\Requests\SuperAdmin\LoginRequest;
use App\Http\Resources\SuperAdmin\UserResource;
use App\Models\User;
use App\Utils\ApiConstants;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Dedoc\Scramble\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('Superadmin/Auth')]
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
            Auth::logout();

            $made_response = ApiResponse::userFailure(
                code: ApiConstants::UNAUTHORIZED_CODE,
                message: 'User not Authenticated',
                httpStatusCode: Response::HTTP_UNAUTHORIZED,
            );
            return $made_response;
        }

        $user = User::where('email', $request->email)->first();

        // Check if user has super admin role
        if (!$user || !$user->hasRole('super-admin')) {
            // simulate token creation to waste time for unauthorized users
            Auth::logout();

            $made_response = ApiResponse::userFailure(
                code: ApiConstants::FORBIDDEN_CODE,
                message: 'User is not a super admin',
                httpStatusCode: Response::HTTP_FORBIDDEN,
            );

            return $made_response;
        }

        $tokenResult = $user->createToken('Api Token');
        $token = $tokenResult->plainTextToken;

        $data = (new UserResource($user))->resolve();
        $data['token'] = $token;


        ThrottlerHelper::clear([$request->email, $request->ip()]);

        $made_response = ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: $data,
            message: 'Super Admin login successful.',
            httpStatusCode: Response::HTTP_OK,
        );
        return $made_response;
    }


}
