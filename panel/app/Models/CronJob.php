<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronJob extends Model
{
    protected $fillable = ['name', 'type', 'schedule', 'cycles', 'command', 'params', 'run_as', 'keep', 'notes', 'is_active', 'last_run_at', 'last_status', 'last_duration'];

    protected $attributes = ['type' => 'shell'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'params' => 'array',
            'cycles' => 'array',
            'keep' => 'integer',
        ];
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function logFile(): string
    {
        return rtrim(config('gbx.root'), '/').'/logs/cron/'.$this->id.'.log';
    }

    public function isFlow(): bool
    {
        return $this->type === 'flow';
    }
}
