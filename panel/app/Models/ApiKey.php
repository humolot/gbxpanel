<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Key of the management API. The token is shown once when it is created and only its
 * SHA-256 hash is stored, so the database never holds a usable credential.
 */
class ApiKey extends Model
{
    protected $fillable = ['name', 'prefix', 'token_hash', 'scopes', 'allowed_ips', 'user_id', 'client_id', 'rate_limit', 'expires_at', 'last_used_at', 'last_ip', 'requests', 'is_active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'rate_limit' => 'integer',
            'requests' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return array{0: static, 1: string} the key and the token, which is never stored */
    public static function issue(array $attributes): array
    {
        $prefix = 'gbx'.Str::lower(Str::random(6));
        $secret = Str::random(40);
        $token = $prefix.'_'.$secret;

        $key = static::query()->create($attributes + [
            'prefix' => $prefix,
            'token_hash' => hash('sha256', $token),
        ]);

        return [$key, $token];
    }

    public static function findByToken(string $token): ?static
    {
        [$prefix] = explode('_', $token, 2);
        $key = static::query()->where('prefix', $prefix)->first();

        return $key && hash_equals($key->token_hash, hash('sha256', $token)) ? $key : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];
        if (in_array('*', $scopes, true)) {
            return true;
        }
        if (in_array($scope, $scopes, true)) {
            return true;
        }
        // "websites" grants "websites:read" and "websites:write"
        $group = explode(':', $scope)[0];

        return in_array($group, $scopes, true) || (str_ends_with($scope, ':read') && in_array($group.':write', $scopes, true));
    }

    /** Whether the caller address is allowed (list of IPs or CIDR ranges, empty = any). */
    public function allowsIp(?string $ip): bool
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->allowed_ips)));
        if (! $list) {
            return true;
        }
        foreach ($list as $entry) {
            if (self::ipMatches((string) $ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function ipMatches(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return $ip === $entry;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        $bits = (int) $bits;
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || $bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = chr(0xff << (8 - $rest) & 0xff);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }
}
