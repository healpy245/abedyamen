<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\KamanHumanDelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a follow-up Kaman WhatsApp bubble after a short human-like pause.
 */
class SendDelayedKamanWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $instanceId,
        public int $messageId,
        public string $chatId,
        public int $triggerUserMessageId = 0,
    ) {}

    public function handle(ChatbotGreenApiService $greenApi): void
    {
        $instance = ChatbotInstance::query()->find($this->instanceId);
        $message = ChatbotMessage::query()->find($this->messageId);

        if ($instance === null || $message === null) {
            return;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        if (in_array((string) $message->delivery_status, ['sent', 'failed'], true)
            && ! empty($meta['greenapi_id_message'])) {
            return;
        }

        $conversationId = (int) $message->conversation_id;
        if ($this->triggerUserMessageId > 0) {
            $latestUserId = (int) ChatbotMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->value('id');

            if ($latestUserId > $this->triggerUserMessageId) {
                $message->forceFill([
                    'delivery_status' => 'failed',
                    'metadata' => array_merge($meta, [
                        'kaman_delivery' => 'stale',
                        'kaman_cancel_reason' => 'newer_customer_message',
                    ]),
                ])->save();

                return;
            }
        }

        $sendUrl = trim((string) $instance->greenapi_url);
        if ($sendUrl === '') {
            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'kaman_delivery' => 'failed',
                    'kaman_cancel_reason' => 'missing_greenapi_url',
                ]),
            ])->save();

            return;
        }

        $text = (string) $message->message;

        try {
            $preType = max(1.0, KamanHumanDelay::secondsForText($text) * 0.9);
            $greenApi->sustainTypingPresence($sendUrl, $this->chatId, $preType);
        } catch (Throwable $e) {
            Log::debug('Kaman sustainTyping skipped', [
                'instance_id' => $this->instanceId,
                'error' => $e->getMessage(),
            ]);
        }

        $sendResult = $greenApi->sendMessage($sendUrl, $this->chatId, $text, 1000);
        $body = $sendResult['body'] ?? null;
        $idMessage = is_array($body) ? ($body['idMessage'] ?? null) : null;
        $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;

        $message->forceFill([
            'delivery_status' => $ok ? 'sent' : 'failed',
            'metadata' => array_merge($meta, [
                'kaman_delivery' => $ok ? 'sent' : 'failed',
                'greenapi_chat_id' => $this->chatId,
                'greenapi_id_message' => $idMessage,
                'greenapi_status' => $sendResult['status'] ?? null,
            ]),
        ])->save();

        $conversation = ChatbotConversation::query()->find($conversationId);
        $conversation?->recordAssistantActivity();
    }
}
