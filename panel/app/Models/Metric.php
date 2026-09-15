<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cpu', 'memory', 'load1', 'disk', 'net_rx', 'net_tx'];
}
