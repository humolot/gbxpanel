<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    protected $fillable = ['domain', 'aliases', 'root_path', 'php_version', 'proxy_target', 'status', 'ssl_enabled', 'ssl_provider', 'ssl_expires_at', 'force_https', 'notes'];

    protected function casts(): array
    {
        return [
            'ssl_enabled' => 'boolean',
            'force_https' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function databases()
    {
        return $this->hasMany(MysqlDatabase::class);
    }

    public function ftpAccounts()
    {
        return $this->hasMany(FtpAccount::class);
    }

    /**
     * True for a registrable (apex) domain such as example.com or example.com.br, where a
     * www alias is common. Subdomains like shop.example.com usually have no www record.
     */
    public static function isApexDomain(string $domain): bool
    {
        $labels = explode('.', strtolower(trim($domain, '.')));
        if (count($labels) <= 2) {
            return true;
        }

        // two-part public suffixes: example.com.br, example.co.uk, example.org.ar ...
        return count($labels) === 3 && in_array($labels[1], ['com', 'net', 'org', 'gov', 'edu', 'co', 'ac', 'gob', 'or', 'ne'], true) && strlen($labels[2]) === 2;
    }

    public function aliasList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->aliases))));
    }

    public function logPath(string $type): string
    {
        return rtrim(config('gbx.paths.logs'), '/').'/'.$this->domain.'-'.$type.'.log';
    }

    public function sslDir(): string
    {
        return rtrim(config('gbx.paths.ssl'), '/').'/'.$this->domain;
    }
}
