<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'category', 'action', 'details', 'ip', 'client_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $category, string $action, ?string $details = null): void
    {
        try {
            static::query()->create([
                'user_id' => auth('web')->id(),
                'client_id' => \App\Services\Clients\ClientContext::id(),
                'category' => $category,
                'action' => $action,
                'details' => $details,
                'ip' => app()->runningInConsole() ? 'cli' : request()->ip(),
            ]);
        } catch (\Throwable) {
            // never break an operation because of logging
        }
    }
}
