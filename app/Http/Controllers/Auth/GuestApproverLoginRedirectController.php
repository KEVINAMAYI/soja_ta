<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GuestRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class GuestApproverLoginRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $token = $request->query('redirect_token');

        if (empty($token)) {
            Log::error('Missing redirect token in guest login request.');
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'Invalid details provided.']);
        }

        $targetUrl = GuestRoute::decryptRedirectToken($token);

        if ($targetUrl === null) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'Invalid or expired login link.']);
        }

        $parsedTargetUrl = parse_url($targetUrl, PHP_URL_QUERY);
        $queryParams = [];

        if ($parsedTargetUrl) {
            parse_str($parsedTargetUrl, $queryParams);
        }

        $receiverEmail = $queryParams['receiver_email'] ?? null;
        $leaveStartDate = $queryParams['leave_start_date'] ?? null;

        if (empty($receiverEmail) || empty($leaveStartDate)) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'Invalid details provided.']);
        }

        // if date today is greater than leave start date, then the link has expired
        if ($leaveStartDate && now()->isAfter($leaveStartDate)) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'The login link has expired.']);
        }

        $user = User::where('email', $receiverEmail)->first();

        if (!$user || !$user->employee) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'The account for the provided email could not be found.']);
        }

        if ((int) ($user->employee->active ?? 0) === 0) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'The account for the provided email is inactive.']);
        }

        Auth::guard('web')->login($user, true);
        Session::regenerate();

        return redirect()->away($targetUrl);
    }
}
