<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = ['type', 'title', 'script', 'status', 'exit_code', 'meta', 'user_id', 'started_at', 'finished_at'];

    protected $hidden = ['script'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logFile(): string
    {
        return storage_path('app/tasks/'.$this->id.'.log');
    }

    public function output(int $offset = 0): string
    {
        $file = $this->logFile();
        if (! is_file($file)) {
            return '';
        }

        return (string) file_get_contents($file, false, null, $offset);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }
}
