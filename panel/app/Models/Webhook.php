<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'last_status', 'last_error', 'last_at', 'failures'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    /** Whether this webhook wants an event ("websites.*" matches "websites.created"). */
    public function listensTo(string $event): bool
    {
        foreach ($this->events ?? [] as $pattern) {
            if ($pattern === '*' || $pattern === $event || (str_ends_with($pattern, '.*') && str_starts_with($event, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
