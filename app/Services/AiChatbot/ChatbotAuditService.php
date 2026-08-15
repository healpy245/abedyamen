<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotAuditLog;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;

class ChatbotAuditService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        ChatbotInstance $instance,
        string $action,
        ?User $user = null,
        ?ChatbotConversation $conversation = null,
        array $payload = [],
    ): ChatbotAuditLog {
        return ChatbotAuditLog::query()->create([
            'instance_id' => $instance->id,
            'conversation_id' => $conversation?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'payload' => $payload !== [] ? $payload : null,
        ]);
    }
}
