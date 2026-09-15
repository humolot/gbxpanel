<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'model'];

    public function messages()
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function actions()
    {
        return $this->hasMany(AiAction::class)->orderBy('id');
    }

    public function pendingActions()
    {
        return $this->hasMany(AiAction::class)->where('status', 'pending')->orderBy('id');
    }
}
