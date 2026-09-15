<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security entrance: the panel only answers after the visitor
 * opened https://IP:PORT/<entry>. Everyone else gets a neutral 404 page.
 */
class SecurityEntrance
{
    public function handle(Request $request, Closure $next): Response
    {
        // IP allow list (Settings > Security)
        $allowed = array_filter(array_map('trim', explode(',', (string) Setting::get('allowed_ips', ''))));
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            return response()->view('errors.entrance', ['reason' => 'ip'], 403);
        }

        // Domain binding (gbx domain <name>): the panel is hidden on any other host name or IP
        $domain = strtolower(trim((string) config('gbx.domain')));
        if ($domain !== '' && strtolower($request->getHost()) !== $domain) {
            return response()->view('errors.entrance', ['reason' => 'domain'], 404);
        }

        $entry = trim((string) config('gbx.entry'), '/');
        if ($entry === '' || $request->session()->get('gbx_entry') === true || auth()->check()) {
            return $next($request);
        }

        if (trim($request->path(), '/') === $entry) {
            $request->session()->put('gbx_entry', true);

            return $next($request);
        }

        return response()->view('errors.entrance', ['reason' => 'entry'], 404);
    }
}
