<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Jobs\ProcessCampaignConversationJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\GreenApiIncomingMessage;
use App\Services\AiChatbot\WhatsAppVoiceInboundService;
use App\Services\Malan\MalanConversationContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leads-only campaign inbound handler.
 *
 * Smart listening window: persist each inbound immediately, debounce per conversation,
 * then ONE AI call for the full customer burst (not per WhatsApp message).
 */
class MalanCampaignWebhookService
{
    public function __construct(
        protected MalanCampaignService $campaignService,
        protected ChatbotGreenApiService $greenApiService,
        protected AiChatbotService $chatbotService,
        protected MalanConversationContextService $contextService,
        protected CampaignConversationBurstService $burstService,
        protected WhatsAppVoiceInboundService $voiceInboundService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(MalanCampaign $campaign, Request $request): array
    {
        if ($dotSilence = $this->handleStaffDotSilenceIfNeeded($campaign, $request)) {
            return $dotSilence;
        }

        if (! $this->greenApiService->isInboundCustomerWebhook($request)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored non-inbound Green API webhook (outgoing/fromMe).',
                'typeWebhook' => $request->input('typeWebhook'),
            ];
        }

        $incoming = $this->greenApiService->parseIncomingMessage($request);
        if ($incoming === null || $incoming->chatId === '') {
            return ['ok' => true, 'ignored' => true, 'reason' => 'No supported inbound message.'];
        }

        return $this->handleParsedInbound($campaign, $incoming, $request);
    }

    /**
     * Staff typed ".." on the phone in this WhatsApp chat: stop the campaign bot
     * for that conversation only, and cancel any pending outbound immediately.
     *
     * @return array<string, mixed>|null
     */
    private function handleStaffDotSilenceIfNeeded(MalanCampaign $campaign, Request $request): ?array
    {
        if (! $this->greenApiService->isOutgoingPhoneMessageWebhook($request)) {
            return null;
        }

        $incoming = $this->greenApiService->parseIncomingMessage($request);
        if ($incoming === null || $incoming->chatId === '') {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored outbound phone message (not a .. silence).',
            ];
        }

        if (! $this->greenApiService->isStaffDotCommand((string) ($incoming->text ?? $incoming->caption ?? ''))) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored outbound phone message (not a .. silence).',
            ];
        }

        return $this->silenceConversationForStaffDot($campaign, $incoming);
    }

    /**
     * @return array<string, mixed>
     */
    public function silenceConversationForStaffDot(
        MalanCampaign $campaign,
        GreenApiIncomingMessage $incoming,
    ): array {
        $instance = $campaign->instance;
        if ($instance === null) {
            return ['ok' => false, 'error' => 'Campaign chatbot instance missing.'];
        }

        if ($incoming->messageId !== null && $incoming->messageId !== '') {
            $idKey = 'malan.campaign.dot.'.$campaign->id.'.'.$incoming->messageId;
            if (! Cache::add($idKey, 1, 86400)) {
                return [
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'Ignored duplicate .. silence.',
                    'campaign_id' => $campaign->id,
                ];
            }
        }

        $debounceKey = 'campaign_phone_dot:'.$campaign->id.':'.sha1($incoming->chatId);
        if (! app()->runningUnitTests() && Cache::has($debounceKey)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate .. silence.',
                'campaign_id' => $campaign->id,
            ];
        }
        if (! app()->runningUnitTests()) {
            Cache::put($debounceKey, true, 3);
        }

        $contact = $this->resolveContact($campaign, $incoming->chatId);
        $conversation = $this->resolveConversation($campaign, $instance->id, $contact, $incoming);

        if ($contact !== null && $contact->conversation_id !== $conversation->id) {
            $contact->forceFill([
                'conversation_id' => $conversation->id,
                'chat_id' => $incoming->chatId,
            ])->save();
        }

        $wasActive = $conversation->allowsAutomaticReply();
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $version = ((int) ($meta['campaign_conversation_version'] ?? 0)) + 1;
        $meta['campaign_conversation_version'] = $version;
        $meta['campaign_burst_open'] = false;
        $meta['campaign_response_state'] = CampaignConversationBurstService::STATE_CANCELLED;
        $meta['phone_dot_control'] = 'stopped';
        $meta['phone_dot_control_at'] = now()->toIso8601String();

        $updates = [
            'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
            'metadata' => $meta,
        ];
        if ($wasActive) {
            $updates['attention_status'] = ChatbotConversation::ATTENTION_NEEDS;
            if ($instance->user_id) {
                $updates['assigned_user_id'] = $instance->user_id;
            }
        }

        $conversation->forceFill($updates)->save();
        $this->burstService->invalidatePendingOutbound($conversation, $version, 'phone_dot_silence');
        $this->burstService->closeBurst($conversation->fresh() ?? $conversation, $version);

        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'human',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_HUMAN,
            'external_message_id' => $incoming->messageId,
            'message' => '..',
            'delivery_status' => 'sent',
            'metadata' => [
                'phone_dot_toggle' => true,
                'campaign_dot_silence' => true,
                'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
                'greenapi_chat_id' => $incoming->chatId,
                'campaign_id' => $campaign->id,
            ],
        ]);

        Log::info('Campaign WhatsApp .. silenced conversation bot', [
            'campaign_id' => $campaign->id,
            'instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'chat_id' => $incoming->chatId,
            'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
        ]);

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'campaign_id' => $campaign->id,
            'phone_dot_toggle' => true,
            'bot_mode' => ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
            'bot_stopped' => $wasActive,
            'reply' => null,
            'routed' => 'campaign_lead_bot',
        ];
    }

    /**
     * Handle an already-parsed inbound customer message for a campaign (used by main webhook routing).
     *
     * @return array<string, mixed>
     */
    public function handleParsedInbound(
        MalanCampaign $campaign,
        GreenApiIncomingMessage $incoming,
        ?Request $request = null,
    ): array {
        $instance = $campaign->instance;
        if ($instance === null || ! $instance->hasMalanIntegration()) {
            return ['ok' => false, 'error' => 'Campaign chatbot instance missing.'];
        }

        $sendUrl = trim((string) ($campaign->greenapi_url ?: $instance->greenapi_url));
        if ($sendUrl === '') {
            return ['ok' => false, 'error' => 'Campaign Green API URL is not configured.'];
        }

        $contact = $this->resolveContact($campaign, $incoming->chatId);
        $conversation = $this->resolveConversation($campaign, $instance->id, $contact, $incoming);

        // Respect staff per-chat mute (paused / human_takeover). Do not auto-reactivate.

        if ($this->isEchoOfOwnAssistantMessage($conversation, $incoming)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored echo of our own assistant/campaign message.',
                'campaign_id' => $campaign->id,
            ];
        }

        if ($incoming->messageId !== null && $this->isKnownOutboundMessageId($conversation, $incoming->messageId)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored outbound Green API message id.',
                'campaign_id' => $campaign->id,
            ];
        }

        // Idempotency by Green API message id — do NOT debounce the whole chat (bursts need every line).
        if ($incoming->messageId !== null && $incoming->messageId !== '') {
            $idKey = 'malan.campaign.inbound.'.$campaign->id.'.'.$incoming->messageId;
            if (! Cache::add($idKey, 1, 86400)) {
                return [
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'Duplicate Green API message id.',
                    'campaign_id' => $campaign->id,
                ];
            }
        }

        if ($contact !== null) {
            if ($contact->conversation_id !== $conversation->id) {
                $contact->forceFill(['conversation_id' => $conversation->id, 'chat_id' => $incoming->chatId])->save();
            }
            $this->campaignService->markResponded($contact);
        }

        $this->contextService->beginCampaignSales($conversation, $instance);

        $user = $instance->user;
        if ($user === null) {
            return ['ok' => false, 'error' => 'Chatbot owner account is missing.'];
        }

        $shouldAutoReply = $campaign->isBotActive() && $conversation->allowsAutomaticReply();

        $storeOptions = [
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_message_id' => $incoming->messageId,
            'message_type' => 'text',
            'campaign_lead_bot' => true,
            'skip_ai' => true,
        ];

        $voicePrepared = null;
        if ($incoming->isAudio()) {
            $voicePrepared = $this->voiceInboundService->prepare($incoming);
            $text = $voicePrepared['message_text'];
            $storeOptions['message_type'] = $voicePrepared['message_type'];
            $storeOptions['attachment_disk'] = $voicePrepared['attachment_disk'];
            $storeOptions['attachment_path'] = $voicePrepared['attachment_path'];
            $storeOptions['attachment_mime'] = $voicePrepared['attachment_mime'];
            $storeOptions['metadata'] = $voicePrepared['metadata'];
        } else {
            $text = $incoming->customerFacingText();
        }

        if ($text === null) {
            return [
                'ok' => true,
                'stored_without_reply' => true,
                'campaign_id' => $campaign->id,
                'routed' => 'campaign_lead_bot',
            ];
        }

        // Unclear voice → short dialect reply, skip AI burst.
        if ($voicePrepared !== null && $voicePrepared['unclear'] && $shouldAutoReply) {
            try {
                $result = $this->chatbotService->appendDirectExchange(
                    $user,
                    $instance,
                    $text,
                    $this->voiceInboundService->unclearReplyText(),
                    (int) $conversation->id,
                    $storeOptions,
                );
            } catch (Throwable $e) {
                Log::error('Campaign unclear voice store failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);

                return ['ok' => false, 'error' => 'Failed to store campaign voice inbound.'];
            }

            $assistantMessage = $result['assistant_message'] ?? null;
            if ($assistantMessage instanceof ChatbotMessage) {
                $sendResult = $this->greenApiService->sendMessage(
                    $sendUrl,
                    $incoming->chatId,
                    (string) $assistantMessage->message,
                );
                $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
                $body = $sendResult['body'] ?? null;
                $idMessage = is_array($body) ? ($body['idMessage'] ?? null) : null;
                $meta = is_array($assistantMessage->metadata) ? $assistantMessage->metadata : [];
                $assistantMessage->forceFill([
                    'delivery_status' => $ok ? 'sent' : 'failed',
                    'external_message_id' => is_string($idMessage) ? $idMessage : null,
                    'metadata' => array_merge($meta, [
                        'campaign_id' => $campaign->id,
                        'campaign_delivery' => $ok ? 'sent' : 'failed',
                        'voice_unclear_reply' => true,
                        'greenapi_chat_id' => $incoming->chatId,
                    ]),
                ])->save();
            }

            return [
                'ok' => true,
                'campaign_id' => $campaign->id,
                'voice_unclear' => true,
                'routed' => 'campaign_lead_bot',
            ];
        }

        try {
            $result = $this->chatbotService->sendMessage(
                $user,
                $instance,
                $text,
                (int) $conversation->id,
                $storeOptions,
            );
        } catch (Throwable $e) {
            Log::error('Campaign webhook store inbound failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'Failed to store campaign inbound.'];
        }

        $userMessage = $result['user_message'] ?? null;
        $conversation = $result['conversation'] ?? $conversation->fresh() ?? $conversation;

        $registration = $this->burstService->registerInboundMessage($conversation);
        $version = (int) $registration['version'];
        $waitSeconds = (int) $registration['wait_seconds'];

        if ($userMessage instanceof ChatbotMessage) {
            $meta = is_array($userMessage->metadata) ? $userMessage->metadata : [];
            $userMessage->forceFill([
                'metadata' => array_merge($meta, [
                    'campaign_id' => $campaign->id,
                    'campaign_burst_unprocessed' => true,
                    'campaign_conversation_version' => $version,
                    'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                ]),
            ])->save();
        }

        if (! $shouldAutoReply) {
            return [
                'ok' => true,
                'stored_without_reply' => true,
                'campaign_id' => $campaign->id,
                'conversation_version' => $version,
                'routed' => 'campaign_lead_bot',
            ];
        }

        // Keep WhatsApp "typing…" visible during listening window / AI generation.
        $this->greenApiService->sendTyping(
            $sendUrl,
            $incoming->chatId,
            $this->greenApiService->thinkingTypingTimeMs(),
        );

        ProcessCampaignConversationJob::dispatch(
            (int) $campaign->id,
            (int) $conversation->id,
            $version,
            $incoming->chatId,
        )->delay(now()->addSeconds($waitSeconds));

        // Shared hosting: after HTTP responds, wait the listening window then process.
        // Skip in unit tests (and rely on the delayed job / explicit ProcessCampaignConversationJob calls).
        // Note: sync queue ignores delay — production uses database queue + this afterResponse path.
        if (! app()->runningUnitTests()) {
            $this->runListeningWindowAfterResponse(
                (int) $campaign->id,
                (int) $conversation->id,
                $version,
                $incoming->chatId,
                $waitSeconds,
            );
        }

        return [
            'ok' => true,
            'campaign_id' => $campaign->id,
            'chatId' => $incoming->chatId,
            'listening' => true,
            'conversation_version' => $version,
            'listen_seconds' => $waitSeconds,
            'reply' => null,
            'routed' => 'campaign_lead_bot',
        ];
    }

    private function runListeningWindowAfterResponse(
        int $campaignId,
        int $conversationId,
        int $version,
        string $chatId,
        int $waitSeconds,
    ): void {
        $waitSeconds = max(1, min(CampaignListeningWindow::MAX_BURST_SECONDS + 2, $waitSeconds));

        dispatch(function () use ($campaignId, $conversationId, $version, $chatId, $waitSeconds): void {
            try {
                @set_time_limit($waitSeconds + 120);
                if ($waitSeconds > 0) {
                    usleep($waitSeconds * 1_000_000);
                }

                $job = new ProcessCampaignConversationJob($campaignId, $conversationId, $version, $chatId);
                $job->handle(
                    app(AiChatbotService::class),
                    app(ChatbotGreenApiService::class),
                    app(CampaignConversationBurstService::class),
                    app(MalanConversationContextService::class),
                );
            } catch (Throwable $e) {
                Log::debug('Campaign listening-window afterResponse failed', [
                    'conversation_id' => $conversationId,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    private function isEchoOfOwnAssistantMessage(
        ChatbotConversation $conversation,
        GreenApiIncomingMessage $incoming,
    ): bool {
        $text = $this->normalizeComparableText($incoming->customerFacingText() ?? '');
        if ($text === '') {
            return false;
        }

        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderByDesc('id')
            ->limit(12)
            ->get(['message']);

        foreach ($recent as $message) {
            $assistantText = $this->normalizeComparableText((string) $message->message);
            if ($assistantText !== '' && $assistantText === $text) {
                return true;
            }
            similar_text($assistantText, $text, $percent);
            if ($percent >= 90) {
                return true;
            }
        }

        return false;
    }

    private function isKnownOutboundMessageId(ChatbotConversation $conversation, string $messageId): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['metadata']);

        foreach ($recent as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (($meta['greenapi_id_message'] ?? null) === $messageId) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strtolower($text);
    }

    private function resolveContact(MalanCampaign $campaign, string $chatId): ?MalanCampaignContact
    {
        return $this->campaignService->findContactForChat($campaign, $chatId);
    }

    private function resolveConversation(
        MalanCampaign $campaign,
        int $instanceId,
        ?MalanCampaignContact $contact,
        GreenApiIncomingMessage $incoming,
    ): ChatbotConversation {
        if ($contact?->conversation_id) {
            $existing = ChatbotConversation::query()->find($contact->conversation_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $scopedExternalId = 'campaign:'.$campaign->id.':'.$incoming->chatId;

        $existing = ChatbotConversation::query()
            ->where('instance_id', $instanceId)
            ->where('campaign_id', $campaign->id)
            ->where(function ($q) use ($incoming, $scopedExternalId): void {
                $q->where('external_chat_id', $incoming->chatId)
                    ->orWhere('external_chat_id', $scopedExternalId);
            })
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $phone = preg_replace('/\D+/', '', explode('@', $incoming->chatId)[0] ?? '') ?: null;

        return ChatbotConversation::query()->create([
            'user_id' => $campaign->instance?->user_id,
            'instance_id' => $instanceId,
            'campaign_id' => $campaign->id,
            'title' => $contact?->name ?: ($incoming->senderName ?: $phone ?: $incoming->chatId),
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $scopedExternalId,
            'contact_phone' => $phone ?: ($contact?->phone_normalized ?: $contact?->phone),
            'contact_name' => $contact?->name ?: $incoming->senderName,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            'metadata' => [
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $contact?->id,
                'campaign_lead_bot' => true,
                'source' => 'malan_campaign',
                'whatsapp_chat_id' => $incoming->chatId,
                'campaign_conversation_version' => 0,
            ],
        ]);
    }
}
