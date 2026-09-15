<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsZone extends Model
{
    protected $fillable = ['provider_id', 'name', 'external_id', 'manageable', 'note', 'records_count', 'synced_at'];

    protected function casts(): array
    {
        return ['manageable' => 'boolean', 'synced_at' => 'datetime', 'records_count' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DnsProvider::class, 'provider_id');
    }

    /** Relative record name of a host in this zone: "@" for the apex, "www" for www.<zone>. */
    public function relative(string $host): ?string
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === $this->name) {
            return '@';
        }

        return str_ends_with($host, '.'.$this->name) ? substr($host, 0, -strlen($this->name) - 1) : null;
    }

    public function fqdn(string $name): string
    {
        return $name === '@' || $name === '' ? $this->name : $name.'.'.$this->name;
    }
}
