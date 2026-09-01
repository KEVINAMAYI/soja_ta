<?php

namespace App\Services;

use App\Exceptions\ImpersonationException;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Utils\ApiConstants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-admin impersonation of a client organization's primary admin.
 *
 * A super admin requests an impersonation handoff over the API; the service
 * issues a single-use token that a browser tab exchanges for a web session
 * capped at self::SESSION_MINUTES.
 */
class ImpersonationService
{
    /** Hard cap on the impersonated web session. */
    public const SESSION_MINUTES = 30;

    /** Lifetime of the single-use handoff token before it must be re-requested. */
    public const TOKEN_TTL_SECONDS = 60;

    public const SESSION_KEY = 'impersonation';

    /**
     * Issue a single-use impersonation handoff for the organization's first admin.
     *
     * @return array{session: ImpersonationSession, admin: User, token: string}
     *
     * @throws ImpersonationException
     */
    public function issueHandoff(User $superAdmin, Organization $organization): array
    {
        if (!$organization->active) {
            throw new ImpersonationException(
                "Organization [{$organization->name}] is inactive and cannot be impersonated.",
                ApiConstants::FORBIDDEN_CODE,
                Response::HTTP_FORBIDDEN,
            );
        }

        $admin = $this->resolveOrganizationAdmin($organization);

        $token = Str::random(64);

        $session = DB::transaction(function () use ($superAdmin, $organization, $admin, $token) {
            // Only one live handoff/session per super admin at a time.
            ImpersonationSession::where('super_admin_id', $superAdmin->id)
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => now(),
                    'ended_reason' => 'superseded',
                ]);

            return ImpersonationSession::create([
                'super_admin_id' => $superAdmin->id,
                'impersonated_user_id' => $admin->id,
                'organization_id' => $organization->id,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });

        $this->log(
            action: ApiConstants::USER_ACTION_IMPERSONATION_REQUESTED,
            userId: $superAdmin->id,
            userType: 'super-admin',
            description: "Super admin [{$superAdmin->email}] requested impersonation of [{$admin->email}] for organization [{$organization->name}].",
            auditPayload: [
                'impersonation_session_id' => $session->id,
                'organization_id' => $organization->id,
                'impersonated_user_id' => $admin->id,
            ],
        );

        return ['session' => $session, 'admin' => $admin, 'token' => $token];
    }

    /**
     * Exchange a handoff token for an authenticated, time-boxed web session.
     *
     * @throws ImpersonationException
     */
    public function startSession(string $token): ImpersonationSession
    {
        $session = DB::transaction(function () use ($token) {
            $session = ImpersonationSession::where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (
                !$session
                || $session->consumed_at !== null
                || $session->ended_at !== null
                || $session->token_expires_at->isPast()
            ) {
                throw new ImpersonationException(
                    'This impersonation link is invalid or has expired.',
                    ApiConstants::UNAUTHORIZED_CODE,
                    Response::HTTP_UNAUTHORIZED,
                );
            }

            $session->forceFill([
                'consumed_at' => now(),
                'started_at' => now(),
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ])->save();

            return $session;
        });

        $session->loadMissing(['impersonatedUser', 'superAdmin', 'organization']);
        $admin = $session->impersonatedUser;

        Auth::guard('web')->logout();
        Session::invalidate();
        Auth::guard('web')->login($admin);
        Session::regenerate();

        Session::put(self::SESSION_KEY, [
            'session_id' => $session->id,
            'super_admin_id' => $session->super_admin_id,
            'super_admin_name' => $session->superAdmin?->name,
            'organization_id' => $session->organization_id,
            'organization_name' => $session->organization?->name,
            'impersonated_user_id' => $admin->id,
            'started_at' => $session->started_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);

        $this->log(
            action: ApiConstants::USER_ACTION_IMPERSONATION_START,
            userId: $session->super_admin_id,
            userType: 'super-admin',
            description: "Impersonation started: [{$session->superAdmin?->email}] is now acting as [{$admin->email}] of [{$session->organization?->name}] until {$session->expires_at}.",
            auditPayload: [
                'impersonation_session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'impersonated_user_id' => $admin->id,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        );

        return $session;
    }

    /**
     * End the active impersonation session and clear it from the web session.
     *
     * @param string $reason one of: manual_stop, logout, expired
     */
    public function endSession(string $reason): ?ImpersonationSession
    {
        $context = Session::get(self::SESSION_KEY);
        Session::forget(self::SESSION_KEY);

        if (!is_array($context) || empty($context['session_id'])) {
            return null;
        }

        $session = ImpersonationSession::with(['impersonatedUser', 'superAdmin', 'organization'])
            ->find($context['session_id']);

        if (!$session || $session->ended_at !== null) {
            return $session;
        }

        $this->revoke($session, $reason);

        return $session;
    }

    /**
     * Mark a session as ended and write the termination to both log trails.
     */
    public function revoke(ImpersonationSession $session, string $reason): ImpersonationSession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        $session->forceFill([
            'ended_at' => now(),
            'ended_reason' => $reason,
        ])->save();

        $session->loadMissing(['impersonatedUser', 'superAdmin', 'organization']);

        $this->log(
            action: ApiConstants::USER_ACTION_IMPERSONATION_END,
            userId: $session->super_admin_id,
            userType: 'super-admin',
            description: "Impersonation ended ({$reason}): [{$session->superAdmin?->email}] stopped acting as [{$session->impersonatedUser?->email}] of [{$session->organization?->name}].",
            auditPayload: [
                'impersonation_session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'impersonated_user_id' => $session->impersonated_user_id,
                'reason' => $reason,
            ],
        );

        return $session;
    }

    /**
     * First eligible admin account of the organization, ordered by account age.
     *
     * @throws ImpersonationException
     */
    public function resolveOrganizationAdmin(Organization $organization): User
    {
        $adminRoleId = Role::where('name', 'admin')
            ->where('organization_id', $organization->id)
            ->value('id');

        if (!$adminRoleId) {
            throw new ImpersonationException(
                "No admin role is configured for organization [{$organization->name}].",
            );
        }

        $admin = User::query()
            ->whereHas('roles', fn ($query) => $query->where('roles.id', $adminRoleId))
            ->whereHas('employee', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('active', true))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$admin) {
            throw new ImpersonationException(
                "No active admin user exists for organization [{$organization->name}]. Impersonation is not possible.",
            );
        }

        return $admin;
    }

    private function log(string $action, int $userId, string $userType, string $description, array $auditPayload): void
    {
        $common = [
            'action' => $action,
            'user_type' => $userType,
            'user_id' => $userId,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'project_location' => request()?->route()?->getActionName(),
            'description' => $description,
        ];

        UserActivityLog::create($common);

        AuditLog::create($common + [
            'request_data' => json_encode($auditPayload),
            'response_data' => null,
        ]);
    }
}
