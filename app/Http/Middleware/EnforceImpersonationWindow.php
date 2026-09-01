<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates an impersonated session once it exceeds its 30-minute window or
 * once it has been revoked from the super admin console.
 */
class EnforceImpersonationWindow
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // The handoff route establishes a fresh session, so never bounce it.
        if ($request->routeIs('impersonation.enter')) {
            return $next($request);
        }

        $context = $request->session()->get(ImpersonationService::SESSION_KEY);

        if (!is_array($context) || empty($context['session_id'])) {
            return $next($request);
        }

        $record = ImpersonationSession::find($context['session_id']);
        $expired = !$record
            || $record->ended_at !== null
            || $record->expires_at === null
            || $record->expires_at->isPast();

        if (!$expired) {
            return $next($request);
        }

        $this->service->endSession($record && $record->ended_at ? 'revoked' : 'expired');

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['email' => 'Your impersonation session has ended. Please start a new one.']);
    }
}
