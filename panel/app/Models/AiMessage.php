<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    protected $fillable = ['ai_conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'tool_name', 'images', 'meta'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'images' => 'array', 'meta' => 'array'];
    }

    public function actions()
    {
        return $this->hasMany(AiAction::class);
    }
}
