<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Limits of a hosting plan; 0 means unlimited. */
class ClientPackage extends Model
{
    protected $fillable = ['name', 'max_websites', 'max_databases', 'max_ftp', 'disk_mb', 'bandwidth_mb', 'php_versions', 'allow_ssl', 'notes'];

    protected function casts(): array
    {
        return [
            'php_versions' => 'array',
            'allow_ssl' => 'boolean',
            'max_websites' => 'integer',
            'max_databases' => 'integer',
            'max_ftp' => 'integer',
            'disk_mb' => 'integer',
            'bandwidth_mb' => 'integer',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'package_id');
    }

    public function allowsPhp(string $version): bool
    {
        return empty($this->php_versions) || in_array($version, $this->php_versions, true);
    }
}
