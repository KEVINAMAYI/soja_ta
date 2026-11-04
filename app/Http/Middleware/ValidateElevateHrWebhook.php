<?php

namespace App\Http\Middleware;

use App\Models\OrganizationSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ValidateElevateHrWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $allowedIps = config('services.elevatehr.webhook_allowed_ips', []);
        $ipIsAllowed = is_string($ip) && is_array($allowedIps)
            && collect($allowedIps)->contains(
                fn ($allowedIp) => is_string($allowedIp) && IpUtils::checkIp($ip, $allowedIp)
            );

        if (! $ipIsAllowed) {
            $request->attributes->set('elevate_failure_reason', 'Request source IP is not allowed.');
            Log::error('REJECTED ELEVATE-HR WEBHOOK... REQUEST FROM UNKNOWN IP.', [
                'ip' => $ip,
                'organization_id' => $request->route('organization'),
            ]);

            return response()->json(['message' => 'Invalid webhook credentials.'], 403);
        }

        $hash = OrganizationSetting::where('organization_id', $request->route('organization'))
            ->where('key', 'elevatehr_leave_webhook_token_hash')
            ->value('value');
        $token = $request->query('token');

        if (! is_string($hash) || ! is_string($token) || strlen($token) !== 64
            || ! hash_equals($hash, hash('sha256', $token))) {
            $request->attributes->set('elevate_failure_reason', 'Missing, invalid or revoked webhook token.');
            return response()->json(['message' => 'Invalid webhook credentials.'], 403);
        }

        if (strlen($request->getContent()) > 262144) {
            return response()->json(['message' => 'Webhook payload is too large.'], 413);
        }

        if (! $request->isJson()) {
            return response()->json(['message' => 'A JSON payload is required.'], 415);
        }

        return $next($request);
    }
}
