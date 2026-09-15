<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role checks. Usage: panel.access (any active user, viewers read only)
 * or panel.access:admin (administrators only).
 */
class PanelAccess
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            auth()->logout();

            return redirect()->route('login');
        }

        // every account can manage its own password and two-factor authentication
        $selfService = $request->routeIs('profile.password', 'account.security', 'account.2fa.*', 'logout');

        // administrators can require two-factor authentication: nothing else is reachable until it is enabled
        if (! $selfService && ! $user->hasTwoFactor() && \App\Services\TwoFactor::required()) {
            $message = 'Enable two-factor authentication to continue.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message, 'redirect' => route('account.security')], 403)
                : redirect()->route('account.security')->with('warning', $message);
        }

        // read-only users may still chat with the AI (it only gets read tools)
        $viewerAllowed = $selfService || $request->routeIs('ai.send', 'ai.action', 'ai.action.all', 'ai.model', 'ai.destroy');

        $denied = ($role === 'admin' && ! $user->isAdmin())
            || (! $request->isMethodSafe() && ! $user->canWrite() && ! $viewerAllowed);

        if ($denied) {
            $message = 'Your account does not have permission for this action.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 403)
                : abort(403, $message);
        }

        return $next($request);
    }
}
