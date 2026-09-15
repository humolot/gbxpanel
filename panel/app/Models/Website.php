<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Website extends Model
{
    protected $fillable = ['domain', 'aliases', 'root_path', 'php_version', 'proxy_target', 'status', 'ssl_enabled', 'ssl_provider', 'ssl_expires_at', 'force_https', 'expires_at', 'settings', 'notes'];

    public const DEFAULT_INDEX = ['index.php', 'index.html', 'index.htm', 'default.php', 'default.html'];

    protected function casts(): array
    {
        return [
            'ssl_enabled' => 'boolean',
            'force_https' => 'boolean',
            'ssl_expires_at' => 'datetime',
            'expires_at' => 'date',
            'settings' => 'array',
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

    /** Read a per-site option (dot notation). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    /** Change a per-site option in memory; call save() to persist. */
    public function putSetting(string $key, mixed $value): static
    {
        $settings = $this->settings ?? [];
        Arr::set($settings, $key, $value);
        $this->settings = $settings;

        return $this;
    }

    /** Folder below the site root that Apache serves (e.g. /public for Laravel). */
    public function runPath(): string
    {
        $run = trim((string) $this->setting('run_path', ''), '/');

        return $run === '' ? '/' : '/'.$run;
    }

    public function documentRoot(): string
    {
        return rtrim($this->root_path, '/').($this->runPath() === '/' ? '' : $this->runPath());
    }

    public function indexFiles(): array
    {
        return $this->setting('index_files') ?: self::DEFAULT_INDEX;
    }

    /** Reverse proxy rules; the legacy proxy_target column is the rule for "/". */
    public function proxies(): array
    {
        $rules = array_values(array_filter((array) $this->setting('proxies', []), fn ($r) => ! empty($r['target'])));
        if ($this->proxy_target && ! collect($rules)->contains('path', '/')) {
            array_unshift($rules, ['name' => 'Site', 'path' => '/', 'target' => $this->proxy_target, 'enabled' => true, 'websocket' => true]);
        }

        return $rules;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
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

    /** Private per-site data kept by the panel (htpasswd files, deploy keys). */
    public function privateDir(): string
    {
        return rtrim(config('gbx.root'), '/').'/sites/'.$this->domain;
    }
}
