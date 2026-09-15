<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MysqlDatabase extends Model
{
    protected $table = 'databases';

    protected $fillable = ['name', 'username', 'password', 'host', 'charset', 'website_id', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
