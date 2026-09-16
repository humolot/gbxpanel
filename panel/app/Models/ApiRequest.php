<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['api_key_id', 'key_name', 'method', 'path', 'status', 'ip', 'duration_ms', 'message', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'status' => 'integer', 'duration_ms' => 'integer'];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
