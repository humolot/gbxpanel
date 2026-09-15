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

        // read-only users may still chat with the AI (it only gets read tools) and change their password
        $viewerAllowed = $request->routeIs('ai.send', 'ai.action', 'ai.action.all', 'ai.model', 'ai.destroy', 'profile.password', 'logout');

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
