<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientUsage extends Model
{
    protected $table = 'client_usage';

    protected $fillable = ['client_id', 'day', 'bandwidth', 'requests', 'disk'];

    protected function casts(): array
    {
        // day is stored as Y-m-d text so it can be matched exactly
        return ['bandwidth' => 'integer', 'requests' => 'integer', 'disk' => 'integer'];
    }
}
