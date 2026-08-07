<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Helper to build URLs for named routes with optional query parameters.
 * Use when generating links that should return guests back to a specific
 * page (including modal/query flags) after they authenticate.
 */
class GuestRoute
{
    /**
     * Build a URL for a named route and append query parameters.
     *
     * Example: GuestRoute::make('leaves.index', [], ['review_modal' => 'details', 'leave_id' => 1])
     *
     * @param string $routeName
     * @param array $routeParams
     * @param array $query
     * @param string|null $receiverEmail
     * @return string
     */
    public static function make(string $routeName, string $leave_start_date, array $routeParams = [], array $query = [], ?string $receiverEmail = null): string
    {
        if ($receiverEmail !== null) {
            $query['receiver_email'] = $receiverEmail;
            $query['leave_start_date'] = $leave_start_date;
        }

        $url = route($routeName, $routeParams);

        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }

    /**
     * Build a guest login redirect URL using the leave.approver.guest.login route.
     * The target URL is base64 encoded and encrypted into redirect_token.
     *
     * @param string $routeName
     * @param array $routeParams
     * @param array $query
     * @param string|null $receiverEmail
     * @return string
     */
    public static function makeGuestLoginRedirect(string $routeName, string $leave_start_date, array $routeParams = [], array $query = [], ?string $receiverEmail = null): string
    {
        $targetUrl = self::make($routeName, $leave_start_date, $routeParams, $query, $receiverEmail);
        $encoded = base64_encode($targetUrl);
        $encrypted = Crypt::encryptString($encoded);

        return route('leave.approver.guest.login', ['redirect_token' => $encrypted]);
    }

    /**
     * Decrypt the guest redirect token and return the original target URL.
     * Handles invalid tokens and throttles repeated failures.
     *
     * @param string $token
     * @return string|null
     */
    public static function decryptRedirectToken(string $token): ?string
    {
        $rateLimiterKey = self::rateLimiterKey($token);

        if (RateLimiter::tooManyAttempts($rateLimiterKey, 5)) {
            return null;
        }

        try {
            $decoded = Crypt::decryptString($token);
            $url = base64_decode($decoded, true);

            if ($url === false) {
               Log::error('Failed to base64 decode redirect token: ' . $token);
                throw new \RuntimeException('Invalid redirect token.');
            }

            RateLimiter::clear($rateLimiterKey);

            return $url;
        } catch (Throwable $e) {
            Log::error('Failed to decrypt redirect token: ' . $token . '. Error: ' . $e->getMessage());
            RateLimiter::hit($rateLimiterKey);
            return null;
        }
    }

    private static function rateLimiterKey(string $token): string
    {
        return 'guest-route-decrypt:' . sha1($token) . '|' . request()->ip();
    }
}
