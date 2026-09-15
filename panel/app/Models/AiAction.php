<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tool call proposed by the AI that changes the server and waits for
 * the user's approval.
 */
class AiAction extends Model
{
    protected $fillable = ['ai_conversation_id', 'ai_message_id', 'tool_call_id', 'tool', 'arguments', 'status', 'result', 'user_id'];

    protected function casts(): array
    {
        return ['arguments' => 'array'];
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function message()
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }
}
