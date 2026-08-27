<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ElevateHRApiController extends Controller
{
    /**
     * Sample endpoint to verify that a request was authenticated using a valid API key.
     */
    public function ping(Request $request)
    {
        $apiKey = $request->attributes->get('api_key');
        $organization = $request->attributes->get('api_key_organization');

        return response()->json([
            'code' => 1000,
            'message' => 'Endpoint hit successfully with a valid API key.',
            'data' => [
                'organization' => $organization?->name,
                'environment' => $apiKey?->environment,
                'key_name' => $apiKey?->name,
            ],
        ]);
    }
}
