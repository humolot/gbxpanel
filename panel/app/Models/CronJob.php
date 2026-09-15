<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronJob extends Model
{
    protected $fillable = ['name', 'schedule', 'command', 'run_as', 'is_active', 'last_run_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function logFile(): string
    {
        return rtrim(config('gbx.root'), '/').'/logs/cron/'.$this->id.'.log';
    }
}
