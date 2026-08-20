<?php

namespace App\Http\Controllers\SuperAdmin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SuperAdmin\Payload\AuthRequestDTO;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SuperAdminAuth extends Controller
{
    public function login(Request $request)
    {
        $authRequest = AuthRequestDTO::fromRequest($request);

        Log::info('Super admin login request received.', $authRequest->toLogContext());

        return response()->json([
            'message' => 'Login data received successfully.',
            'data' => $authRequest->toLogContext(),
        ]);
    }
}
