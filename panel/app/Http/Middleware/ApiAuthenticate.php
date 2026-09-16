<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiRequest;
use App\Services\Clients\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication of the management API.
 *
 * A key is sent as "Authorization: Bearer <token>" (or the X-API-Key header). Only the hash of
 * the token is stored, and every call is checked against the permissions, the address list, the
 * expiration and the rate limit of the key, then written to the API log.
 */
class ApiAuthenticate
{
    public const CURRENT = 'gbx.api.key';

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $request->attributes->set('request_id', (string) Str::uuid());

        $token = $this->token($request);
        if ($token === '') {
            return $this->log($request, $this->error('unauthorized', 'Send your key as: Authorization: Bearer <token>', 401), null, $started);
        }

        $key = ApiKey::findByToken($token);
        if (! $key) {
            return $this->log($request, $this->error('unauthorized', 'Unknown API key.', 401), null, $started);
        }
        if (! $key->is_active) {
            return $this->log($request, $this->error('key_disabled', 'This API key is disabled.', 403), $key, $started);
        }
        if ($key->isExpired()) {
            return $this->log($request, $this->error('key_expired', 'This API key expired on '.$key->expires_at->toDateString().'.', 403), $key, $started);
        }
        if (! $key->allowsIp($request->ip())) {
            return $this->log($request, $this->error('ip_not_allowed', 'The address '.$request->ip().' is not in the allow list of this key.', 403), $key, $started);
        }

        $scope = $request->route()?->defaults['scope'] ?? null;
        if ($scope && ! $key->hasScope($scope)) {
            return $this->log($request, $this->error('missing_scope', 'This key does not have the permission '.$scope.'.', 403, ['required_scope' => $scope]), $key, $started);
        }

        // rate limit per key
        $limiter = 'gbx-api:'.$key->id;
        $limit = max(1, (int) $key->rate_limit);
        if (RateLimiter::tooManyAttempts($limiter, $limit)) {
            $response = $this->error('rate_limited', 'Too many requests. Wait '.RateLimiter::availableIn($limiter).' seconds.', 429);
            $response->headers->set('Retry-After', (string) RateLimiter::availableIn($limiter));

            return $this->log($request, $this->limitHeaders($response, $limiter, $limit), $key, $started);
        }
        RateLimiter::hit($limiter, 60);

        $request->attributes->set(self::CURRENT, $key);
        // the API acts as the user that owns the key, so permissions and the activity log stay the same
        if ($key->user) {
            Auth::setUser($key->user);
        }
        // a key bound to a client acts inside that client; any other key clears the context
        ClientContext::set($key->client_id ? $key->client : null);

        // a repeated call with the same Idempotency-Key answers the stored result instead of acting twice
        $idempotency = $this->idempotencyKey($request, $key);
        if ($idempotency && ($stored = Cache::get($idempotency))) {
            $replay = response()->json($stored['body'], $stored['status'])->header('Idempotent-Replay', 'true');

            return $this->log($request, $this->limitHeaders($replay, $limiter, $limit), $key, $started);
        }

        $response = $next($request);

        if ($idempotency && $response->isSuccessful() && $response instanceof \Illuminate\Http\JsonResponse) {
            Cache::put($idempotency, ['body' => $response->getData(true), 'status' => $response->getStatusCode()], now()->addDay());
        }

        $key->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip(), 'requests' => $key->requests + 1])->saveQuietly();

        return $this->log($request, $this->limitHeaders($response, $limiter, $limit), $key, $started);
    }

    protected function token(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }

        return trim((string) $request->header('X-API-Key', ''));
    }

    protected function idempotencyKey(Request $request, ApiKey $key): ?string
    {
        $value = trim((string) $request->header('Idempotency-Key', ''));

        return $value !== '' && ! $request->isMethod('GET')
            ? 'gbx.api.idem.'.$key->id.'.'.hash('sha256', $request->method().$request->path().$value)
            : null;
    }

    protected function limitHeaders(Response $response, string $limiter, int $limit): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($limiter, $limit)));

        return $response;
    }

    protected function error(string $code, string $message, int $status, array $extra = []): Response
    {
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }

    protected function log(Request $request, Response $response, ?ApiKey $key, float $started): Response
    {
        $requestId = (string) $request->attributes->get('request_id');
        $response->headers->set('X-Request-Id', $requestId);

        $message = null;
        if ($response instanceof \Illuminate\Http\JsonResponse && ! $response->isSuccessful()) {
            $data = $response->getData(true);
            $message = $data['error']['message'] ?? $data['message'] ?? null;
        }

        try {
            ApiRequest::query()->create([
                'api_key_id' => $key?->id,
                'key_name' => $key?->name,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'message' => $message ? mb_substr((string) $message, 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // the log must never break an answer
        }

        return $response;
    }
}
