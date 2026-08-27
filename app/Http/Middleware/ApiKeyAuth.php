<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    private const API_KEY_HEADER_NAME = 'X-Api-Key';
    /**
     * Authenticate requests using an X-API-KEY header against api_keys stored in the database.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = $request->header(self::API_KEY_HEADER_NAME) ?? $request->bearerToken();

        if (!$plainTextKey) {
            return response()->json([
                'code' => 1003,
                'message' => 'Missing API key. Provide it via the X-API-KEY header.',
            ], 401);
        }

        $apiKey = ApiKey::findValidByPlainKey($plainTextKey);

        if (!$apiKey) {
            return response()->json([
                'code' => 1003,
                'message' => 'Invalid or revoked API key.',
            ], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_key_organization', $apiKey->organization);

        return $next($request);
    }

    public static function getApiKeyFromRequestHeader(Request $request): ?string
    {
        return $request->header(self::API_KEY_HEADER_NAME) ?? $request->bearerToken();
    }
}
