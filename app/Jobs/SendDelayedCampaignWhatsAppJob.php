<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\Malan\Campaigns\CampaignConversationBurstService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a campaign AI WhatsApp bubble after a human-like typing delay.
 * Skips if conversation version changed or a newer customer message arrived.
 */
class SendDelayedCampaignWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $campaignId,
        public int $messageId,
        public string $chatId,
        public int $triggerUserMessageId,
        public int $generatedForVersion = 0,
        public bool $skipPreType = false,
    ) {}

    public function handle(ChatbotGreenApiService $greenApi): void
    {
        $lock = Cache::lock('campaign-wa-send-'.$this->messageId, 30);
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
        $campaign = MalanCampaign::query()->find($this->campaignId);
        $message = ChatbotMessage::query()->find($this->messageId);

        if ($campaign === null || $message === null) {
            return;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        $state = (string) ($meta['campaign_response_state'] ?? $meta['campaign_delivery'] ?? '');
        if (in_array($state, [
            CampaignConversationBurstService::STATE_CANCELLED,
            CampaignConversationBurstService::STATE_STALE,
            'cancelled',
        ], true)) {
            return;
        }

        if (in_array((string) $message->delivery_status, ['sent', 'failed'], true)
            && ! empty($meta['greenapi_id_message'])) {
            return;
        }

        if (($meta['campaign_delivery'] ?? null) === 'sent'
            && ! empty($meta['greenapi_id_message'])) {
            return;
        }

        $conversation = ChatbotConversation::query()->find((int) $message->conversation_id);
        $currentVersion = 0;
        if ($conversation !== null) {
            $cMeta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $currentVersion = (int) ($cMeta['campaign_conversation_version'] ?? 0);

            if (! $conversation->allowsAutomaticReply()) {
                $message->forceFill([
                    'delivery_status' => 'failed',
                    'metadata' => array_merge($meta, [
                        'campaign_delivery' => CampaignConversationBurstService::STATE_CANCELLED,
                        'campaign_response_state' => CampaignConversationBurstService::STATE_STALE,
                        'campaign_cancel_reason' => 'conversation_bot_silenced',
                    ]),
                ])->save();

                return;
            }
        }

        $generatedFor = (int) ($this->generatedForVersion ?: ($meta['campaign_generated_for_version'] ?? 0));
        if ($generatedFor > 0 && $currentVersion > 0 && $generatedFor !== $currentVersion) {
            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'campaign_delivery' => CampaignConversationBurstService::STATE_CANCELLED,
                    'campaign_response_state' => CampaignConversationBurstService::STATE_STALE,
                    'campaign_cancel_reason' => 'conversation_version_mismatch',
                    'campaign_generated_for_version' => $generatedFor,
                    'campaign_current_version_at_send' => $currentVersion,
                ]),
            ])->save();

            return;
        }

        $conversationId = (int) $message->conversation_id;
        $triggerId = $this->triggerUserMessageId > 0
            ? $this->triggerUserMessageId
            : (int) ($meta['trigger_user_message_id'] ?? 0);

        $latestUserId = (int) ChatbotMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('id');

        if ($triggerId > 0 && $latestUserId > 0 && $latestUserId > $triggerId) {
            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'campaign_delivery' => CampaignConversationBurstService::STATE_CANCELLED,
                    'campaign_response_state' => CampaignConversationBurstService::STATE_STALE,
                    'campaign_cancel_reason' => 'newer_customer_message',
                    'newer_user_message_id' => $latestUserId,
                ]),
            ])->save();

            return;
        }

        $sendUrl = trim((string) $campaign->greenapi_url);
        if ($sendUrl === '') {
            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'campaign_delivery' => 'failed',
                    'campaign_response_state' => CampaignConversationBurstService::STATE_CANCELLED,
                    'campaign_cancel_reason' => 'missing_greenapi_url',
                ]),
            ])->save();

            return;
        }

        try {
            $text = (string) $message->message;
            $preTypeSeconds = $this->skipPreType ? 0.0 : $this->preTypeSecondsForText($greenApi, $text);
            if ($preTypeSeconds > 0.2) {
                $greenApi->sustainTypingPresence($sendUrl, $this->chatId, $preTypeSeconds);
            }
        } catch (Throwable $e) {
            Log::debug('Campaign sustainTyping skipped', [
                'campaign_id' => $this->campaignId,
                'error' => $e->getMessage(),
            ]);
        }

        $text = (string) $message->message;
        // Minimal typingTime on send — presence was already sustained above (avoids flicker).
        $sendResult = $greenApi->sendMessage(
            $sendUrl,
            $this->chatId,
            $text,
            1000,
        );
        $body = $sendResult['body'] ?? null;
        $idMessage = is_array($body) ? ($body['idMessage'] ?? null) : null;
        $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300
            && is_string($idMessage) && $idMessage !== '';

        $message->forceFill([
            'delivery_status' => $ok ? 'sent' : 'failed',
            'metadata' => array_merge($meta, [
                'campaign_id' => $campaign->id,
                'campaign_delivery' => $ok ? 'sent' : 'failed',
                'campaign_response_state' => $ok
                    ? CampaignConversationBurstService::STATE_SENT
                    : CampaignConversationBurstService::STATE_CANCELLED,
                'greenapi_chat_id' => $this->chatId,
                'greenapi_id_message' => $idMessage,
                'greenapi_status' => $sendResult['status'] ?? null,
                'campaign_generated_for_version' => $generatedFor ?: null,
            ]),
        ])->save();

        if ($ok && $conversation !== null) {
            $cMeta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $cMeta['campaign_response_state'] = CampaignConversationBurstService::STATE_SENT;
            $conversation->forceFill(['metadata' => $cMeta])->save();
        }
    }

    private function preTypeSecondsForText(ChatbotGreenApiService $greenApi, string $text): float
    {
        $length = mb_strlen(trim($text));
        $typed = ($greenApi->typingTimeForText($text) ?? 1000) / 1000;

        if ($length <= 55) {
            return max(0.35, min(0.7, $typed * 0.65));
        }

        return max(0.8, $typed * 0.85);
    }
}
