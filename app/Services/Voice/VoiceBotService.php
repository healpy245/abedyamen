<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\AiChatbotService;
use RuntimeException;

class VoiceBotService
{
    public function __construct(
        protected AiChatbotService $chatbotService,
    ) {}

    /**
     * @return array{
     *     assistant_text: string,
     *     assistant_message_id: int,
     *     conversation_id: int
     * }
     */
    public function processCallerText(VoiceCall $call, string $text): array
    {
        $call->loadMissing(['user', 'chatbotInstance']);

        $user = $call->user;
        $instance = $call->chatbotInstance;

        if ($user === null || $instance === null) {
            throw new RuntimeException('Voice call is missing required associations.');
        }

        $conversationId = $call->chatbot_conversation_id;

        $result = $this->chatbotService->sendMessage(
            $user,
            $instance,
            $text,
            $conversationId,
            ['voice_mode' => true, 'channel' => 'voice'],
        );

        $conversation = $result['conversation'];
        $assistantMessage = $result['assistant_message'];

        if ($call->chatbot_conversation_id !== $conversation->id) {
            $call->chatbot_conversation_id = $conversation->id;
            $call->save();
        }

        return [
            'assistant_text' => (string) $assistantMessage->message,
            'assistant_message_id' => $assistantMessage->id,
            'user_message_id' => $result['user_message']->id,
            'conversation_id' => $conversation->id,
            'user_message' => $result['user_message'],
            'assistant_message' => $assistantMessage,
        ];
    }
}
