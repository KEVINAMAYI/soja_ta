<?php

namespace App\Services;

use App\Jobs\SendSuperAdminPasswordResetEmailJob;
use App\Models\SuperAdminPasswordReset;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Utils\ApiConstants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SuperAdminPasswordResetService
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    /**
     * Issue a reset token for the given email when it belongs to a super admin.
     *
     * Any previously issued, unused token for that user is invalidated first so only
     * one token is ever active. Always returns void so callers cannot enumerate accounts.
     */
    public function requestReset(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        $user = User::where('email', $email)->first();

        if (!$user || !$user->hasRole(self::SUPER_ADMIN_ROLE)) {
            Log::warning('Super admin password reset requested for a non super admin or unknown email.', [
                'email' => $email,
                'ip' => $ip,
                'requested_at' => now()->toDateTimeString(),
            ]);

            return;
        }

        $ttlHours = $this->tokenTtlHours();
        $token = Str::random(64);

        $reset = DB::transaction(function () use ($user, $email, $token, $ip, $userAgent, $ttlHours) {
            SuperAdminPasswordReset::query()
                ->where('user_id', $user->id)
                ->outstanding()
                ->update(['invalidated_at' => now()]);

            $attemptNumber = SuperAdminPasswordReset::where('user_id', $user->id)->count() + 1;

            return SuperAdminPasswordReset::create([
                'user_id' => $user->id,
                'email' => $email,
                'token_hash' => $this->hashToken($token),
                'attempt_number' => $attemptNumber,
                'requested_at' => now(),
                'requested_ip' => $ip,
                'user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
                'expires_at' => now()->addHours($ttlHours),
            ]);
        });

        UserActivityLog::storeUserActivityLog(
            ApiConstants::USER_ACTION_PASSWORD_RESET_REQUESTED,
            sprintf(
                'Super admin password reset requested for %s. Request #%d at %s from IP %s.',
                $email,
                $reset->attempt_number,
                $reset->requested_at->toDateTimeString(),
                $ip ?? 'unknown'
            )
        );

        SendSuperAdminPasswordResetEmailJob::dispatch(
            $user->name ?? 'there',
            $user->email,
            $this->buildResetUrl($token),
            $ttlHours
        );
    }

    /**
     * Resolve a plaintext token to an active reset record, or null when it is
     * unknown, already used, superseded or expired.
     */
    public function findActiveReset(string $token): ?SuperAdminPasswordReset
    {
        return SuperAdminPasswordReset::with('user')
            ->where('token_hash', $this->hashToken($token))
            ->active()
            ->first();
    }

    /**
     * Consume the token and persist the new password.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $reset = $this->findActiveReset($token);

        if (!$reset || !$reset->user || !$reset->user->hasRole(self::SUPER_ADMIN_ROLE)) {
            return false;
        }

        DB::transaction(function () use ($reset) {
            $reset->forceFill(['used_at' => now()])->save();

            SuperAdminPasswordReset::query()
                ->where('user_id', $reset->user_id)
                ->outstanding()
                ->update(['invalidated_at' => now()]);
        });

        $user = $reset->user;
        $user->password = Hash::make($newPassword);
        $user->save();

        // Existing API sessions must not survive a password change.
        $user->tokens()->delete();

        UserActivityLog::storeUserActivityLog(
            ApiConstants::USER_ACTION_PASSWORD_RESET_COMPLETED,
            sprintf(
                'Super admin password reset completed for %s at %s (request #%d).',
                $user->email,
                now()->toDateTimeString(),
                $reset->attempt_number
            )
        );

        return true;
    }

    /**
     * Number of reset requests ever made for an email address.
     */
    public function requestCountFor(string $email): int
    {
        return SuperAdminPasswordReset::where('email', $email)->count();
    }

    private function buildResetUrl(string $token): string
    {
        $base = (string) config('superadmin.password_reset.url');

        return $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . $token;
    }

    private function tokenTtlHours(): int
    {
        return (int) config('superadmin.password_reset.token_ttl_hours', 6);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
