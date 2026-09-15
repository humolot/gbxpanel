<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues single-use tokens for the real-time terminal daemon (scripts/gbx-terminal).
 *
 * token = base64url(payload) "." base64url(HMAC-SHA256(APP_KEY, base64url(payload)))
 * The daemon verifies the signature, expiry, nonce (single use) and client address.
 */
class TerminalManager
{
    public function port(): int
    {
        return (int) config('gbx.terminal.port', 17878);
    }

    /** True when the daemon accepts connections on 127.0.0.1. */
    public function available(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port(), $errno, $error, 0.5);
        if (! $socket) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** Explicit WebSocket URL, or null to use the same origin at /gbx-terminal/. */
    public function url(): ?string
    {
        return config('gbx.terminal.url') ?: null;
    }

    public function token(User $user, ?string $ip, int $cols = 120, int $rows = 32): string
    {
        $payload = self::base64url(json_encode([
            'u' => $user->id,
            'n' => $user->username,
            'e' => time() + (int) config('gbx.terminal.token_ttl', 60),
            'r' => Str::random(32),
            'ip' => (string) $ip,
            'c' => max(10, min($cols, 500)),
            'h' => max(2, min($rows, 200)),
        ], JSON_UNESCAPED_SLASHES));

        return $payload.'.'.self::base64url(hash_hmac('sha256', $payload, self::key(), true));
    }

    public static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    public static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
