<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Services\AiChatbot\KamanWhatsappLeadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores a Kaman Agents CRM lead after a WhatsApp conversation ends or goes idle.
 */
class StoreKamanWhatsappLeadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $conversationId,
        public string $reason = KamanWhatsappLeadStoreService::REASON_CLOSED,
        public ?string $idleSince = null,
    ) {}

    public function handle(KamanWhatsappLeadStoreService $store): void
    {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        if ($this->reason === KamanWhatsappLeadStoreService::REASON_IDLE
            && $this->idleSince !== null
            && $conversation->last_message_at !== null
            && $conversation->last_message_at->gt($this->idleSince)
        ) {
            return;
        }

        try {
            $store->storeFromConversation($conversation, $this->reason);
        } catch (Throwable $e) {
            Log::warning('StoreKamanWhatsappLeadJob failed', [
                'conversation_id' => $this->conversationId,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
