<?php

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatbotSetting extends Model
{
    use HasFactory;

    protected $table = 'ai_chatbot_settings';

    protected $fillable = [
        'key',
        'value',
    ];
}

