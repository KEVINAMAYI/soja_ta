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
use Carbon\Carbon;

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

        // strip the receiver_email query parameter from the target URL to avoid exposing it in the URL after redirection
        $targetUrl = preg_replace('/([?&])receiver_email=[^&]*/', '', $targetUrl);
        $targetUrl = str_replace(['?&', '&&'], ['?', '&'], $targetUrl);
        $targetUrl = rtrim($targetUrl, '&');
        if (str_ends_with($targetUrl, '?')) {
            $targetUrl = rtrim($targetUrl, '?');
        }

        
        // Consider link expired only when the leave start date is strictly
        // before today. If the start date is today the link remains valid
        // for the whole day.
        if ($leaveStartDate) {
            try {
                $leaveStart = Carbon::parse($leaveStartDate)->startOfDay();
            } catch (\Throwable $e) {
                return redirect()->route('login')
                    ->withErrors(['redirect_token' => 'Invalid details provided.']);
            }

            if (Carbon::now()->startOfDay()->gt($leaveStart)) {
                Log::info('Guest approver login redirect request received - link expired', ['leave_start_date' => $leaveStartDate, 'today' => now()->toDateString()]);
                return redirect()->route('login')
                    ->withErrors(['redirect_token' => 'The login link has expired.']);
            }
        }

        $user = User::where('email', $receiverEmail)->first();


        if (!$user || !$user->employee) {
            Log::info('Guest approver login redirect request received - user not found or has no employee record', ['receiver_email' => $receiverEmail]);
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'The account for the provided email could not be found.']);
        }

        if ((int) ($user->employee->active ?? 0) === 0) {
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'The account for the provided email is inactive.']);
        }

        session()->put('url.intended', $targetUrl);

        Auth::guard('web')->login($user, true);
        Session::regenerate();

        return redirect()->intended(route('dashboard', [], false));
    }
}
