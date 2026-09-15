<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseRecycle extends Model
{
    protected $table = 'database_recycle';

    protected $fillable = ['engine', 'name', 'username', 'password', 'host', 'charset', 'file', 'size', 'notes'];

    protected $hidden = ['password'];

    public const KEEP_DAYS = 7;

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'size' => 'integer'];
    }
}
