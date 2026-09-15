<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FtpAccount extends Model
{
    protected $fillable = ['username', 'password', 'path', 'is_active', 'website_id', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'is_active' => 'boolean'];
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
