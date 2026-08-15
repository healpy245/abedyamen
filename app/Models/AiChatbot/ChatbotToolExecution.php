<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotToolExecution extends Model
{
    protected $table = 'ai_chatbot_tool_executions';

    protected $fillable = [
        'conversation_id',
        'chatbot_instance_id',
        'tool_name',
        'arguments',
        'result',
        'success',
        'external_reference',
        'channel',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'success' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'chatbot_instance_id');
    }
}
