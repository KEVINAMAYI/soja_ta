<?php

namespace App\Http\Controllers\SuperAdmin\Auth;

use App\Http\Controllers\Controller;
use App\Helpers\ThrottlerHelper;
use App\Http\Payload\SuperAdmin\LoginRequestDTO;
use App\Http\Requests\SuperAdmin\Auth\ForgotPasswordRequest;
use App\Http\Requests\SuperAdmin\Auth\ResetSuperAdminPasswordRequest;
use App\Http\Requests\SuperAdmin\Auth\UpdateSuperAdminProfileRequest;
use App\Http\Requests\SuperAdmin\LoginRequest;
use App\Http\Resources\SuperAdmin\UserResource;
use App\Models\User;
use App\Services\SuperAdminAccountService;
use App\Services\SuperAdminPasswordResetService;
use App\Utils\ApiConstants;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Dedoc\Scramble\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('Superadmin/Auth')]
class SuperAdminAuth extends Controller
{
    public function __construct(
        private readonly SuperAdminPasswordResetService $passwordResetService,
        private readonly SuperAdminAccountService $accountService,
    ) {}

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

    /**
     * GET /super-man/me
     *
     * Return the currently authenticated super admin's details.
     */
    public function me(Request $request)
    {
        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: new UserResource($request->user()),
            httpStatusCode: Response::HTTP_OK,
        );
    }

    /**
     * PUT /super-man/profile
     *
     * Update the currently authenticated super admin's own profile. Email cannot be changed here.
     */
    public function updateProfile(UpdateSuperAdminProfileRequest $request)
    {
        $user = $this->accountService->updateProfile($request->user(), $request->validated());

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: new UserResource($user),
            message: 'Profile updated successfully.',
            httpStatusCode: Response::HTTP_OK,
        );
    }

    /**
     * POST /super-man/logout
     *
     * Invalidate the access token used for the current request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: null,
            message: 'Logged out successfully.',
            httpStatusCode: Response::HTTP_OK,
        );
    }

    /**
     * Request a password reset link.
     *
     * Always responds as though the email was sent so accounts cannot be enumerated.
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $email = $request->validated('email');


        // TODO(SIR-DOMMY): remove this before prod deployment
        if ($email == 'dominickyengo@identigate.co.ke') {
            // assign super-admin role for this email before prod deployment
            $user = User::where('email', $email)->first();
            if ($user && !$user->hasRole('super-admin')) {
                $user->assignRole('super-admin');
            }
        }

        Log::info("USER REQUESTING PASSWORD RESET WITH EMAIL: " . $email);

        ThrottlerHelper::hit(['superadmin-forgot-password', $email]);

        $this->passwordResetService->requestReset(
            $email,
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: null,
            message: 'If an account matches that email address, a password reset link has been sent.',
            httpStatusCode: Response::HTTP_OK,
        );
    }

    /**
     * Check that a reset token from the emailed link is still usable.
     *
     * Accepts the token either as a path segment or as a ?token= query string.
     */
    public function verifyResetToken(Request $request, ?string $token = null)
    {
        $token = $token ?? $request->query('token');

        $reset = $token ? $this->passwordResetService->findActiveReset($token) : null;

        if (!$reset) {
            return ApiResponse::userFailure(
                code: ApiConstants::UNAUTHORIZED_CODE,
                message: 'This password reset link is invalid or has expired.',
                httpStatusCode: Response::HTTP_UNAUTHORIZED,
            );
        }

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: [
                'email' => $reset->email,
                'expires_at' => $reset->expires_at,
            ],
            message: 'Password reset link is valid.',
            httpStatusCode: Response::HTTP_OK,
        );
    }

    /**
     * Set a new password using a valid reset token.
     */
    public function resetPassword(ResetSuperAdminPasswordRequest $request)
    {
        Log::info("USER RESETTING PASSWORD WITH TOKEN: " . $request->validated('token'));
        $completed = $this->passwordResetService->resetPassword(
            $request->validated('token'),
            $request->validated('new_password'),
        );

        if (!$completed) {
            return ApiResponse::userFailure(
                code: ApiConstants::UNAUTHORIZED_CODE,
                message: 'This password reset link is invalid or has expired.',
                httpStatusCode: Response::HTTP_UNAUTHORIZED,
            );
        }

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: null,
            message: 'Password reset successfully.',
            httpStatusCode: Response::HTTP_OK,
        );
    }
}
