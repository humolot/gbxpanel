<?php

namespace App\Models;

use App\Services\Dns\DnsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsProvider extends Model
{
    protected $fillable = ['type', 'alias', 'credentials', 'is_active', 'rate_limit', 'account', 'checked_at', 'last_error'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'rate_limit' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(DnsZone::class, 'provider_id');
    }

    public function label(): string
    {
        $type = DnsManager::TYPES[$this->type]['name'] ?? $this->type;

        return $this->alias ? "{$this->alias} ({$type})" : $type;
    }

    /** Credentials with secrets masked, for edit forms. */
    public function maskedCredentials(): array
    {
        $fields = DnsManager::TYPES[$this->type]['fields'] ?? [];
        $out = [];
        foreach ($this->credentials ?? [] as $key => $value) {
            $secret = ($fields[$key]['type'] ?? 'text') === 'password';
            $out[$key] = $secret ? '' : $value;
        }

        return $out;
    }
}
