<?php

namespace App\Http\Middleware;

use App\Models\OrganizationSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateElevateHrWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $hash = OrganizationSetting::where('organization_id', $request->route('organization'))
            ->where('key', 'elevatehr_leave_webhook_token_hash')
            ->value('value');
        $token = $request->query('token');

        if (! is_string($hash) || ! is_string($token) || strlen($token) !== 64
            || ! hash_equals($hash, hash('sha256', $token))) {
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
