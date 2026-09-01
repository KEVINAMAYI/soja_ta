<?php

namespace App\Http\Controllers\Impersonation;

use App\Exceptions\ImpersonationException;
use App\Http\Controllers\Controller;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;

class ImpersonationSessionController extends Controller
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    /**
     * GET /impersonate/{token}
     *
     * Exchanges the super admin's single-use handoff token for a time-boxed
     * client session and drops the user on the client dashboard.
     */
    public function enter(string $token): RedirectResponse
    {
        try {
            $this->service->startSession($token);
        } catch (ImpersonationException $e) {
            return redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('dashboard');
    }

    /**
     * POST /impersonate/stop
     *
     * Ends impersonation, logs it and returns to the login screen.
     */
    public function leave(): RedirectResponse
    {
        $this->service->endSession('manual_stop');

        auth()->guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Impersonation ended.');
    }
}
