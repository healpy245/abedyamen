<?php

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;

class ChatbotSetting extends Model
{
    protected $table = 'ai_chatbot_settings';

    protected $fillable = [
        'key',
        'value',
    ];
}
