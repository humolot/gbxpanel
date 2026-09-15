<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\Clients\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client sub-panel: requires a signed-in, active client and exposes it to the services
 * (ActivityLog and tasks record client_id).
 */
class ClientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Client|null $client */
        $client = Auth::guard('client')->user();

        if (! $client) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => 'Your session expired. Sign in again.'], 401)
                : redirect()->guest(route('client.login'));
        }

        // administrators signed in as the client are never blocked
        $impersonating = $request->session()->has('client_impersonator') && Auth::guard('web')->check();
        if (! $client->isActive() && ! $impersonating) {
            Auth::guard('client')->logout();
            $message = 'Your account is suspended'.($client->suspended_reason ? ': '.$client->suspended_reason : '').'. Contact support.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 403)
                : redirect()->route('client.login')->withErrors(['username' => $message]);
        }

        ClientContext::set($client);
        try {
            return $next($request);
        } finally {
            ClientContext::set(null);
        }
    }
}
