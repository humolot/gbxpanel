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
