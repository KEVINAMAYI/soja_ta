<?php

namespace App\Http\Controllers\SuperAdmin\Impersonation;

use App\Exceptions\ImpersonationException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Services\ImpersonationService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Superadmin/Impersonation')]
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    /**
     * POST /super-man/clients/{organization}/impersonate
     *
     * Issues a single-use link that logs the super admin into the client
     * portal as the organization's first admin. The caller should open
     * `redirect_url` in a new tab; the resulting session is capped at 30
     * minutes and is flagged as impersonated.
     */
    public function store(Organization $organization): JsonResponse
    {
        try {
            ['session' => $session, 'admin' => $admin, 'token' => $token] = $this->service->issueHandoff(
                auth()->user(),
                $organization,
            );
        } catch (ImpersonationException $e) {
            return ApiResponse::userFailure(
                code: $e->apiCode(),
                message: $e->getMessage(),
                httpStatusCode: $e->status(),
            );
        }

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: [
                'impersonation_session_id' => $session->id,
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ],
                'impersonated_user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
                'redirect_url' => route('impersonation.enter', ['token' => $token]),
                'link_expires_at' => $session->token_expires_at->toIso8601String(),
                'session_duration_minutes' => ImpersonationService::SESSION_MINUTES,
            ],
            message: 'Impersonation link generated. Open it in a new tab to continue as the client admin.',
        );
    }

    /**
     * DELETE /super-man/impersonations/{impersonationSession}
     *
     * Terminates an impersonation session remotely (from the super admin console).
     */
    public function destroy(ImpersonationSession $impersonationSession): JsonResponse
    {
        if ($impersonationSession->super_admin_id !== auth()->id()) {
            return ApiResponse::userFailure(
                code: ApiConstants::FORBIDDEN_CODE,
                message: 'You may only terminate impersonation sessions you started.',
                httpStatusCode: 403,
            );
        }

        $this->service->revoke($impersonationSession, 'revoked_by_super_admin');

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: ['ended_at' => $impersonationSession->ended_at->toIso8601String()],
            message: 'Impersonation session terminated.',
        );
    }
}
