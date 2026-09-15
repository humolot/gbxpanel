<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupTransfer extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'storage_id', 'direction', 'category', 'label', 'local_path', 'remote_path', 'size', 'parts', 'sha256', 'status',
        'delete_local', 'keep', 'after', 'cron_job_id', 'task_id', 'pid', 'attempts', 'progress', 'message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'parts' => 'integer',
            'delete_local' => 'boolean',
            'keep' => 'integer',
            'after' => 'array',
            'pid' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class, 'storage_id');
    }

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(CronJob::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function logFile(): string
    {
        return storage_path('logs/transfers/'.$this->id.'.log');
    }

    public function fileName(): string
    {
        return basename($this->local_path);
    }
}
