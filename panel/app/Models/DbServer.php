<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A remote database server managed from the panel. */
class DbServer extends Model
{
    protected $fillable = ['engine', 'name', 'host', 'port', 'username', 'password', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer'];
    }

    public function databases()
    {
        return $this->hasMany(MysqlDatabase::class, 'server_id');
    }

    public function label(): string
    {
        return $this->name ?: $this->host.':'.$this->port;
    }
}
