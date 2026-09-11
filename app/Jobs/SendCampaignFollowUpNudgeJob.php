<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\Malan\MalanConversationContextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * One re-engagement nudge when the customer never answered the closing question
 * («حابب تكون جزء من نجاحنا بالطيبة؟»). Silence after it counts as no-response.
 */
class SendCampaignFollowUpNudgeJob implements ShouldQueue
{
    use Queueable;

    /** Silence the customer is allowed before the nudge goes out. */
    public const DELAY_SECONDS = 180;

    public const NUDGE_TEXT = 'بتحب ارجعلك بوقت متاخر اكثر؟';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $campaignId,
        public int $conversationId,
        public string $chatId,
        public int $triggerUserMessageId,
        public int $askedMessageId,
        public int $generatedForVersion = 0,
    ) {}

    /**
     * The closing question that must be answered with اه / لا.
     */
    public static function textAsksClosingQuestion(string $text): bool
    {
        return mb_stripos($text, 'جزء من نجاحنا') !== false;
    }

    public function handle(ChatbotGreenApiService $greenApi): void
    {
        $lock = Cache::lock('campaign-followup-nudge-'.$this->askedMessageId, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->sendNow($greenApi);
        } finally {
            optional($lock)->release();
        }
    }

    private function sendNow(ChatbotGreenApiService $greenApi): void
    {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        $campaign = MalanCampaign::query()->find($this->campaignId);
        $asked = ChatbotMessage::query()->find($this->askedMessageId);

        if ($conversation === null || $campaign === null || $asked === null) {
            return;
        }

        if (! $campaign->isBotActive() || ! $conversation->allowsAutomaticReply()) {
            return;
        }

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        if ((int) ($meta['campaign_followup_nudge_for_message_id'] ?? 0) === $this->askedMessageId) {
            return;
        }

        $currentVersion = (int) ($meta['campaign_conversation_version'] ?? 0);
        if ($this->generatedForVersion > 0 && $currentVersion > 0
            && $currentVersion !== $this->generatedForVersion) {
            return;
        }

        // Only nudge about a question the customer actually received.
        $askedMeta = is_array($asked->metadata) ? $asked->metadata : [];
        if ((string) $asked->delivery_status !== 'sent'
            && ($askedMeta['campaign_delivery'] ?? null) !== 'sent') {
            return;
        }

        // The customer must get the full silence window, whatever path got us here.
        if ($asked->created_at !== null
            && $asked->created_at->greaterThan(now()->subSeconds(self::DELAY_SECONDS - 10))) {
            return;
        }

        if ($this->customerReplied()) {
            return;
        }

        $contextService = app(MalanConversationContextService::class);
        if ($contextService->isCampaignOptedOut($contextService->getActive($conversation))) {
            return;
        }

        $message = ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_SYSTEM,
            'message_type' => 'text',
            'message' => self::NUDGE_TEXT,
            'delivery_status' => 'pending',
            'metadata' => [
                'campaign_id' => $campaign->id,
                'campaign_delivery' => 'queued',
                'campaign_followup_nudge' => true,
                'campaign_followup_for_message_id' => $this->askedMessageId,
                'trigger_user_message_id' => $this->triggerUserMessageId,
                'campaign_generated_for_version' => $currentVersion ?: null,
                'greenapi_chat_id' => $this->chatId,
            ],
        ]);

        $meta['campaign_followup_nudge_for_message_id'] = $this->askedMessageId;
        $meta['campaign_followup_nudge_message_id'] = (int) $message->id;
        $meta['campaign_followup_nudge_sent_at'] = now()->toIso8601String();
        $conversation->forceFill(['metadata' => $meta])->save();
        $conversation->recordAssistantActivity();

        (new SendDelayedCampaignWhatsAppJob(
            $this->campaignId,
            (int) $message->id,
            $this->chatId,
            $this->triggerUserMessageId,
            $currentVersion,
        ))->handle($greenApi);
    }

    private function customerReplied(): bool
    {
        $latestUserId = (int) ChatbotMessage::query()
            ->where('conversation_id', $this->conversationId)
            ->where('role', 'user')
            ->max('id');

        return $latestUserId > $this->triggerUserMessageId;
    }
}
