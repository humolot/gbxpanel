<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Hosting customer with access to the client sub-panel (/client). */
class Client extends Authenticatable
{
    use HasTwoFactor;

    protected $fillable = ['username', 'name', 'email', 'password', 'package_id', 'status', 'suspended_reason', 'suspended_state', 'expires_at', 'notes', 'disk_used', 'bandwidth_used', 'usage_updated_at', 'last_login_at', 'last_login_ip'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'date',
            'suspended_state' => 'array',
            'last_login_at' => 'datetime',
            'usage_updated_at' => 'datetime',
            'disk_used' => 'integer',
            'bandwidth_used' => 'integer',
            'isolated' => 'boolean',
        ] + $this->twoFactorCasts();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ClientPackage::class, 'package_id');
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(MysqlDatabase::class);
    }

    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(ClientUsage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    /** Package limit, 0 = unlimited; no package means nothing can be created. */
    public function limit(string $key): int
    {
        return $this->package ? (int) $this->package->{$key} : -1;
    }

    /** Whether one more resource of this kind fits in the package. */
    public function canAdd(string $relation, string $limitKey): bool
    {
        $limit = $this->limit($limitKey);

        return $limit === 0 || ($limit > 0 && $this->{$relation}()->count() < $limit);
    }

    public function diskLimitBytes(): int
    {
        return max(0, $this->limit('disk_mb')) * 1048576;
    }

    public function bandwidthLimitBytes(): int
    {
        return max(0, $this->limit('bandwidth_mb')) * 1048576;
    }

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(substr($parts[0] ?? 'C', 0, 1).substr($parts[1] ?? '', 0, 1));
    }
}
