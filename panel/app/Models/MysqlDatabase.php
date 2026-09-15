<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A database tracked by the panel. Despite the historical name it covers every engine
 * (engine = mysql, pgsql, mongodb or sqlserver); server_id is null for the local server.
 */
class MysqlDatabase extends Model
{
    protected $table = 'databases';

    protected $fillable = ['engine', 'server_id', 'name', 'username', 'password', 'host', 'charset', 'website_id', 'notes', 'client_id'];

    protected $hidden = ['password'];

    protected $attributes = ['engine' => 'mysql'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    public function server()
    {
        return $this->belongsTo(DbServer::class, 'server_id');
    }

    public function scopeEngine($query, string $engine)
    {
        return $query->where('engine', $engine);
    }

    /** MySQL access hosts (localhost, %, IP addresses). */
    public function hosts(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($this->host ?: 'localhost')))));
    }

    public function location(): string
    {
        return $this->server ? $this->server->label() : 'Localhost';
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
