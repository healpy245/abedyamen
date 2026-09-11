<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Jobs\ProcessKamanConversationJob;
use App\Jobs\SendDelayedKamanWhatsAppJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\Malan\Campaigns\CampaignHumanDelay;
use App\Services\Malan\Proof\MalanPaymentProofService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotGreenApiService
{
    private const CONVERSATION_CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const PROCESSED_MESSAGE_TTL_SECONDS = 60 * 60 * 24;

    private const DISABLED_NOTICE_COOLDOWN_SECONDS = 60 * 60 * 6;

    public function __construct(
        protected AiChatbotService $chatbotService,
        protected GreenApiMediaDownloader $mediaDownloader,
        protected MalanPaymentProofService $paymentProofService,
        protected ChatbotAuditService $auditService,
        protected AiChatbotSettingsService $settingsService,
        protected WhatsAppVoiceInboundService $voiceInboundService,
        protected KamanConversationBurstService $kamanBurstService,
    ) {}

    public function webhookUrl(ChatbotInstance $instance): string
    {
        $token = $this->ensureWebhookToken($instance);

        return route('ai-chatbot.greenapi.webhook', ['token' => $token]);
    }

    public function ensureWebhookToken(ChatbotInstance $instance): string
    {
        if (is_string($instance->greenapi_webhook_token) && $instance->greenapi_webhook_token !== '') {
            return $instance->greenapi_webhook_token;
        }

        $token = Str::random(48);
        $instance->forceFill(['greenapi_webhook_token' => $token])->save();

        return $token;
    }

    public function findByWebhookToken(string $token): ?ChatbotInstance
    {
        return ChatbotInstance::query()
            ->where('greenapi_webhook_token', $token)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(ChatbotInstance $instance, Request $request): array
    {
        $this->ensureOutgoingPhoneWebhook($instance);

        if ($dotToggle = $this->handleStaffDotToggleIfNeeded($instance, $request)) {
            return $dotToggle;
        }

        if (! $this->isIncomingMessageWebhook($request)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored non-incoming webhook event.',
            ];
        }

        $sendUrl = trim((string) ($instance->greenapi_url ?? ''));
        if ($sendUrl === '') {
            return [
                'ok' => false,
                'error' => 'Green API send URL is not configured for this chatbot.',
            ];
        }

        $incoming = $this->parseIncomingMessage($request);
        if ($incoming === null || $incoming->chatId === '') {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'No chatId or supported message found in webhook payload.',
            ];
        }

        // Ignore echoes of our own recently sent assistant text (shared-instance / fromMe gaps).
        if ($this->looksLikeOwnAssistantEcho($instance, $incoming)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored echo of outbound assistant message.',
            ];
        }

        // Shared Green API: customer replies to campaign blasts must stay on the leads-only path.
        $campaignRoute = $this->routeInboundToCampaignIfNeeded($instance, $incoming, $request);
        if ($campaignRoute !== null) {
            return $campaignRoute;
        }

        if ($this->isInboundFromBeforeActivation($instance, $incoming)) {
            if ($incoming->messageId !== null) {
                $this->markMessageAsProcessed($instance, $incoming->messageId);
            }

            Log::info('Green API auto-reply skipped: message predates bot activation', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'message_id' => $incoming->messageId,
                'message_timestamp' => $incoming->timestamp,
                'bot_activated_at' => optional($instance->bot_activated_at)?->toIso8601String(),
            ]);

            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored message sent before the bot was activated.',
            ];
        }

        if ($incoming->messageId !== null && $this->isMessageAlreadyProcessed($instance, $incoming->messageId)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate incoming message event.',
            ];
        }

        // Also dedupe via DB external_message_id when present.
        if ($incoming->messageId !== null && $this->messageExistsInDatabase($instance, $incoming->messageId)) {
            $this->markMessageAsProcessed($instance, $incoming->messageId);

            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate incoming message event.',
            ];
        }

        if ($incoming->messageId !== null) {
            $this->markMessageAsProcessed($instance, $incoming->messageId);
        }

        $user = $instance->user;
        if ($user === null) {
            return [
                'ok' => false,
                'error' => 'Chatbot owner account is missing.',
            ];
        }

        $conversation = $this->findOrCreateWhatsAppConversation($instance, $incoming);
        $conversationId = (int) $conversation->id;
        $this->rememberConversationForChat($instance, $incoming->chatId, $conversationId);

        $senderAllowed = $this->isSenderAllowedToReceiveReply($instance, $incoming->chatId);
        $senderIgnored = $this->isSenderIgnoredFromReply($instance, $incoming->chatId);
        $shouldAutoReply = $senderAllowed
            && ! $senderIgnored
            && $instance->isBotGloballyActive()
            && $conversation->allowsAutomaticReply();

        if ($shouldAutoReply && $instance->hasKamanWhatsappIntegration() && ! $incoming->isImage() && ! $incoming->isPdf()) {
            return $this->enqueueKamanSalesBurst($instance, $incoming, $conversation, $sendUrl, $user);
        }

        if (! $senderAllowed) {
            Log::info('Green API auto-reply skipped: sender not in allowlist', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'allowed' => $instance->allowedReplyPhones(),
            ]);
        }

        if ($senderIgnored) {
            Log::info('Green API auto-reply skipped: sender in ignore list', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'ignored' => $instance->ignoredReplyPhones(),
            ]);
        }

        // Show WhatsApp "typing…" while the bot thinks / generates the reply.
        if ($shouldAutoReply) {
            $this->sendTyping($sendUrl, $incoming->chatId, $this->thinkingTypingTimeMs());
        }

        try {
            if (! $shouldAutoReply) {
                $result = $this->storeWithoutAiReply($instance, $incoming, $conversation);
            } elseif ($incoming->isImage() || $incoming->isPdf() || $incoming->isSticker()) {
                $result = $this->handleMediaMessage($instance, $incoming, $conversationId);
            } elseif ($incoming->isAudio()) {
                $result = $this->handleAudioMessage($instance, $incoming, $conversationId);
            } else {
                $text = $incoming->customerFacingText();
                if ($text === null) {
                    return [
                        'ok' => true,
                        'ignored' => true,
                        'reason' => 'No chatId or text message found in webhook payload.',
                    ];
                }

                $result = $this->chatbotService->sendMessage(
                    $user,
                    $instance,
                    $text,
                    $conversationId,
                    [
                        'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                        'external_message_id' => $incoming->messageId,
                        'message_type' => $incoming->isImage() ? 'image' : ($incoming->isPdf() ? 'pdf' : 'text'),
                    ],
                );
            }
        } catch (Throwable $e) {
            Log::error('Green API chatbot reply failed', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => 'Failed to generate chatbot reply.',
            ];
        }

        $conversation = $result['conversation'];
        $this->rememberConversationForChat($instance, $incoming->chatId, (int) $conversation->id);

        $assistantMessage = $result['assistant_message'] ?? null;
        if ($assistantMessage === null) {
            return [
                'ok' => true,
                'chatId' => $incoming->chatId,
                'incoming' => $incoming->customerFacingText() ?? ('['.$incoming->type.']'),
                'reply' => null,
                'stored_without_reply' => true,
                'bot_mode' => $conversation->bot_mode,
                'instance_active' => $instance->isBotGloballyActive(),
                'sender_allowed' => $senderAllowed,
                'sender_ignored' => $senderIgnored,
            ];
        }

        $reply = (string) $assistantMessage->message;
        $sendResult = $instance->hasKamanWhatsappIntegration()
            ? $this->sendKamanWhatsAppReply($instance, $sendUrl, $incoming->chatId, $assistantMessage, $reply)
            : $this->sendMessage(
                $sendUrl,
                $incoming->chatId,
                $reply,
                $this->typingTimeForText($reply),
            );

        if ($assistantMessage instanceof ChatbotMessage && ! $instance->hasKamanWhatsappIntegration()) {
            $assistantMessage->forceFill([
                'delivery_status' => $sendResult['status'] >= 200 && $sendResult['status'] < 300 ? 'sent' : 'failed',
            ])->save();
        }

        $assistantMessage = $assistantMessage instanceof ChatbotMessage ? $assistantMessage->fresh() : $assistantMessage;
        $reply = $assistantMessage instanceof ChatbotMessage
            ? (string) $assistantMessage->message
            : $reply;

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'incoming' => $incoming->customerFacingText() ?? ('['.$incoming->type.']'),
            'reply' => $reply,
            'green_api_status' => $sendResult['status'],
        ];
    }

    /**
     * Store the inbound line immediately, wait for split WhatsApp bubbles, then one AI reply.
     * A newer customer line bumps the version so an unsent reply is dropped and regenerated.
     *
     * @return array<string, mixed>
     */
    private function enqueueKamanSalesBurst(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
        ChatbotConversation $conversation,
        string $sendUrl,
        User $user,
    ): array {
        $storeOptions = [
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_message_id' => $incoming->messageId,
            'message_type' => 'text',
            'skip_ai' => true,
        ];

        $text = $incoming->customerFacingText();

        if ($incoming->isSticker()) {
            $text = $incoming->customerFacingText() ?? GreenApiIncomingMessage::STICKER_LABEL;
            $storeOptions['message_type'] = 'image';
            $storeOptions['metadata'] = array_merge(
                is_array($storeOptions['metadata'] ?? null) ? $storeOptions['metadata'] : [],
                ['whatsapp_sticker' => true],
            );
            if ($incoming->downloadUrl) {
                try {
                    $stored = $this->mediaDownloader->downloadToPrivateStorage(
                        $incoming->downloadUrl,
                        $incoming->mimeType,
                    );
                    $storeOptions['attachment_disk'] = $stored['disk'] ?? config('malan.media.disk', 'local');
                    $storeOptions['attachment_path'] = $stored['path'];
                    $storeOptions['attachment_mime'] = $stored['mime_type'];
                } catch (Throwable $e) {
                    Log::warning('Green API sticker download failed; continuing with sticker label', [
                        'instance_id' => $instance->id,
                        'chat_id' => $incoming->chatId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($incoming->isAudio()) {
            $prepared = $this->voiceInboundService->prepare($incoming, $instance);
            $text = $prepared['message_text'];
            $storeOptions['message_type'] = $prepared['message_type'];
            $storeOptions['attachment_disk'] = $prepared['attachment_disk'];
            $storeOptions['attachment_path'] = $prepared['attachment_path'];
            $storeOptions['attachment_mime'] = $prepared['attachment_mime'];
            $storeOptions['metadata'] = $prepared['metadata'] ?? [];

            if ($prepared['unclear']) {
                $result = $this->chatbotService->appendDirectExchange(
                    $user,
                    $instance,
                    $text,
                    $this->voiceInboundService->unclearReplyText(),
                    (int) $conversation->id,
                    $storeOptions,
                );
                $assistantMessage = $result['assistant_message'] ?? null;
                if ($assistantMessage instanceof ChatbotMessage) {
                    $this->sendKamanWhatsAppReply(
                        $instance,
                        $sendUrl,
                        $incoming->chatId,
                        $assistantMessage,
                        (string) $assistantMessage->message,
                    );
                }

                return [
                    'ok' => true,
                    'chatId' => $incoming->chatId,
                    'reply' => $assistantMessage instanceof ChatbotMessage
                        ? (string) $assistantMessage->message
                        : null,
                    'voice_unclear' => true,
                ];
            }
        }

        if ($text === null || trim($text) === '') {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'No chatId or text message found in webhook payload.',
            ];
        }

        $result = $this->chatbotService->sendMessage(
            $user,
            $instance,
            $text,
            (int) $conversation->id,
            $storeOptions,
        );

        $userMessage = $result['user_message'] ?? null;
        $conversation = $result['conversation'] ?? $conversation->fresh() ?? $conversation;

        $registration = $this->kamanBurstService->registerInboundMessage($conversation);
        $version = (int) $registration['version'];
        $waitSeconds = (int) $registration['wait_seconds'];

        if ($userMessage instanceof ChatbotMessage) {
            $meta = is_array($userMessage->metadata) ? $userMessage->metadata : [];
            $userMessage->forceFill([
                'metadata' => array_merge($meta, [
                    'kaman_burst_unprocessed' => true,
                    'kaman_conversation_version' => $version,
                ]),
            ])->save();
        }

        $this->sendTyping($sendUrl, $incoming->chatId, $this->thinkingTypingTimeMs());

        ProcessKamanConversationJob::dispatch(
            (int) $instance->id,
            (int) $conversation->id,
            $version,
            $incoming->chatId,
        )->delay(now()->addSeconds($waitSeconds));

        if (! app()->runningUnitTests()) {
            $this->runKamanListeningWindowAfterResponse(
                (int) $instance->id,
                (int) $conversation->id,
                $version,
                $incoming->chatId,
                $waitSeconds,
            );
        }

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'incoming' => $text,
            'listening' => true,
            'conversation_version' => $version,
            'listen_seconds' => $waitSeconds,
            'reply' => null,
        ];
    }

    private function runKamanListeningWindowAfterResponse(
        int $instanceId,
        int $conversationId,
        int $version,
        string $chatId,
        int $waitSeconds,
    ): void {
        $waitSeconds = max(1, min(KamanListeningWindow::MAX_BURST_SECONDS + 2, $waitSeconds));

        dispatch(function () use ($instanceId, $conversationId, $version, $chatId, $waitSeconds): void {
            try {
                @set_time_limit($waitSeconds + 120);
                if ($waitSeconds > 0) {
                    usleep($waitSeconds * 1_000_000);
                }

                $job = new ProcessKamanConversationJob($instanceId, $conversationId, $version, $chatId);
                $job->handle(
                    app(AiChatbotService::class),
                    app(ChatbotGreenApiService::class),
                    app(KamanConversationBurstService::class),
                );
            } catch (Throwable $e) {
                Log::debug('Kaman listening-window afterResponse failed', [
                    'conversation_id' => $conversationId,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * Find or create the WhatsApp conversation. Database is authoritative; cache is optional.
     */
    public function findOrCreateWhatsAppConversation(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
    ): ChatbotConversation {
        $cachedId = $this->conversationIdForChat($instance, $incoming->chatId);

        if ($cachedId !== null) {
            $cached = ChatbotConversation::query()
                ->where('id', $cachedId)
                ->where('instance_id', $instance->id)
                ->where('channel', ChatbotConversation::CHANNEL_WHATSAPP)
                ->first();

            if ($cached !== null) {
                $this->touchWhatsAppContact($cached, $incoming);

                return $cached;
            }
        }

        $existing = ChatbotConversation::query()
            ->forExternalChat($instance->id, ChatbotConversation::CHANNEL_WHATSAPP, $incoming->chatId)
            ->first();

        if ($existing !== null) {
            $this->touchWhatsAppContact($existing, $incoming);
            $this->rememberConversationForChat($instance, $incoming->chatId, (int) $existing->id);

            return $existing;
        }

        $phone = $this->phoneFromChatId($incoming->chatId);
        $metadata = $this->safeWebhookMetadata($incoming);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'title' => $incoming->senderName ?: ($phone ?: $incoming->chatId),
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $incoming->chatId,
            'contact_phone' => $phone,
            'contact_name' => $incoming->senderName,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            'metadata' => $metadata,
        ]);

        $this->rememberConversationForChat($instance, $incoming->chatId, (int) $conversation->id);

        return $conversation;
    }

    /**
     * Send a staff/manual reply through GreenAPI for a WhatsApp conversation.
     *
     * @return array{status:int,body:mixed}
     */
    public function sendStaffReply(ChatbotInstance $instance, ChatbotConversation $conversation, string $message): array
    {
        $sendUrl = trim((string) ($instance->greenapi_url ?? ''));
        if ($sendUrl === '') {
            Log::warning('Green API staff reply skipped: missing send URL', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
            ]);

            return ['status' => 0, 'body' => 'missing_greenapi_url'];
        }

        if (! $conversation->isWhatsApp()) {
            return ['status' => 0, 'body' => 'not_whatsapp'];
        }

        $chatId = is_string($conversation->external_chat_id) ? trim($conversation->external_chat_id) : '';
        if ($chatId === '') {
            // Recover chat id from contact phone when possible.
            $phone = is_string($conversation->contact_phone) ? preg_replace('/\D+/', '', $conversation->contact_phone) : '';
            if (is_string($phone) && $phone !== '') {
                if (str_starts_with($phone, '0') && strlen($phone) === 10) {
                    $phone = '972'.substr($phone, 1);
                }
                $chatId = $phone.'@c.us';
                $conversation->forceFill(['external_chat_id' => $chatId])->save();
            }
        }

        if ($chatId === '') {
            Log::warning('Green API staff reply skipped: missing chat id', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
            ]);

            return ['status' => 0, 'body' => 'missing_chat_id'];
        }

        try {
            return $this->sendMessage($sendUrl, $chatId, $message);
        } catch (Throwable $e) {
            Log::warning('Green API staff reply transport failed', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 0, 'body' => $e->getMessage()];
        }
    }

    /**
     * @return array{conversation: mixed, user_message: mixed, assistant_message: mixed|null}
     */
    private function storeWithoutAiReply(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
        ChatbotConversation $conversation,
    ): array {
        $user = $instance->user;
        if ($user === null) {
            throw new \RuntimeException('Chatbot owner account is missing.');
        }

        $text = $incoming->customerFacingText() ?? ('['.$incoming->type.']');
        $optionalReply = null;

        if (! $instance->isBotGloballyActive()) {
            $optionalReply = $this->maybeDisabledMessage($instance, $conversation);
        }

        if ($incoming->isImage() || $incoming->isPdf() || $incoming->isAudio() || $incoming->isSticker()) {
            // Store media only — never run AI/payment-proof from the no-reply path.
            if ($incoming->isAudio()) {
                $prepared = $this->voiceInboundService->prepare($incoming, $instance);

                return $this->chatbotService->appendDirectExchange(
                    $user,
                    $instance,
                    $prepared['message_text'],
                    $optionalReply ?? '',
                    (int) $conversation->id,
                    [
                        'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                        'external_message_id' => $incoming->messageId,
                        'message_type' => $prepared['message_type'],
                        'attachment_disk' => $prepared['attachment_disk'],
                        'attachment_path' => $prepared['attachment_path'],
                        'attachment_mime' => $prepared['attachment_mime'],
                        'metadata' => $prepared['metadata'],
                    ],
                );
            }

            if ($incoming->downloadUrl) {
                try {
                    $stored = $this->mediaDownloader->downloadToPrivateStorage(
                        $incoming->downloadUrl,
                        $incoming->mimeType,
                    );

                    $messageType = $incoming->isPdf() ? 'pdf' : 'image';
                    $storeMeta = $incoming->isSticker() ? ['whatsapp_sticker' => true] : [];

                    return $this->chatbotService->appendDirectExchange(
                        $user,
                        $instance,
                        $text,
                        $optionalReply ?? '',
                        (int) $conversation->id,
                        [
                            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                            'external_message_id' => $incoming->messageId,
                            'message_type' => $messageType,
                            'attachment_disk' => $stored['disk'] ?? config('malan.media.disk', 'local'),
                            'attachment_path' => $stored['path'],
                            'attachment_mime' => $stored['mime_type'],
                            'metadata' => $storeMeta,
                        ],
                    );
                } catch (Throwable $e) {
                    Log::warning('Green API media store-without-reply download failed', [
                        'instance_id' => $instance->id,
                        'chat_id' => $incoming->chatId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $result = $this->chatbotService->storeIncomingWithoutReply(
            $user,
            $instance,
            $text,
            (int) $conversation->id,
            [
                'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                'external_message_id' => $incoming->messageId,
                'message_type' => match (true) {
                    $incoming->isAudio() => 'audio',
                    $incoming->isPdf() => 'pdf',
                    $incoming->isImage() || $incoming->isSticker() => 'image',
                    default => 'text',
                },
            ],
            $optionalReply,
        );

        // If optional reply is empty string from appendDirectExchange path, drop empty assistant.
        if (($result['assistant_message'] ?? null) !== null
            && trim((string) $result['assistant_message']->message) === '') {
            $result['assistant_message']->delete();
            $result['assistant_message'] = null;
        }

        return $result;
    }

    private function maybeDisabledMessage(ChatbotInstance $instance, ChatbotConversation $conversation): ?string
    {
        $message = trim((string) ($instance->disabled_message ?? ''));
        if ($message === '') {
            return null;
        }

        $cacheKey = 'chatbot_disabled_notice:'.$instance->id.':'.$conversation->id;
        if (Cache::has($cacheKey)) {
            return null;
        }

        Cache::put($cacheKey, true, self::DISABLED_NOTICE_COOLDOWN_SECONDS);

        return $message;
    }

    /**
     * @return array{conversation: mixed, user_message: mixed, assistant_message: mixed}
     */
    private function handleMediaMessage(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
        ?int $conversationId,
    ): array {
        $user = $instance->user;
        if ($user === null) {
            throw new \RuntimeException('Chatbot owner account is missing.');
        }

        $userFacing = $incoming->customerFacingText()
            ?? ($incoming->isSticker()
                ? GreenApiIncomingMessage::STICKER_LABEL
                : '[أرسل الزبون صورة/ملف عبر WhatsApp]');

        if ($incoming->downloadUrl === null || $incoming->downloadUrl === '') {
            return $this->chatbotService->sendMessage(
                $user,
                $instance,
                $userFacing,
                $conversationId,
                [
                    'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                    'external_message_id' => $incoming->messageId,
                ],
            );
        }

        try {
            $stored = $this->mediaDownloader->downloadToPrivateStorage(
                $incoming->downloadUrl,
                $incoming->mimeType,
            );
        } catch (Throwable $e) {
            Log::warning('Green API media download failed; falling back to text handling', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'error' => $e->getMessage(),
            ]);

            return $this->chatbotService->sendMessage(
                $user,
                $instance,
                $userFacing,
                $conversationId,
                [
                    'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                    'external_message_id' => $incoming->messageId,
                    'message_type' => $incoming->isPdf() ? 'pdf' : 'image',
                ],
            );
        }

        $conversation = $this->chatbotService->resolveConversation($user, $instance, $conversationId);

        $attachmentOptions = [
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_message_id' => $incoming->messageId,
            'message_type' => $incoming->isPdf() ? 'pdf' : 'image',
            'attachment_disk' => $stored['disk'] ?? config('malan.media.disk', 'local'),
            'attachment_path' => $stored['path'],
            'attachment_mime' => $stored['mime_type'],
            'metadata' => $incoming->isSticker() ? ['whatsapp_sticker' => true] : [],
        ];

        if (! $incoming->isSticker()) {
            $proofResult = $this->paymentProofService->handleIncomingProofFile(
                $instance,
                $conversation,
                $stored['path'],
                $stored['mime_type'],
                $incoming->messageId,
            );

            // Payment-proof flow owns the reply (vision verification of bank transfer).
            if (($proofResult['handled'] ?? false) === true) {
                return $this->chatbotService->appendDirectExchange(
                    $user,
                    $instance,
                    $userFacing,
                    $proofResult['customer_message'],
                    $conversation->id,
                    $attachmentOptions,
                );
            }
        }

        // Stickers and other images: let the chatbot see the file via OpenAI vision.
        return $this->chatbotService->sendMessage(
            $user,
            $instance,
            $userFacing,
            $conversation->id,
            $attachmentOptions,
        );
    }

    /**
     * Download, store, and transcribe WhatsApp voice notes, then reply (AI or unclear fallback).
     *
     * @return array{conversation: mixed, user_message?: mixed, assistant_message: mixed|null}
     */
    private function handleAudioMessage(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
        ?int $conversationId,
    ): array {
        $user = $instance->user;
        if ($user === null) {
            throw new \RuntimeException('Chatbot owner account is missing.');
        }

        $prepared = $this->voiceInboundService->prepare($incoming, $instance);
        $options = [
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_message_id' => $incoming->messageId,
            'message_type' => $prepared['message_type'],
            'attachment_disk' => $prepared['attachment_disk'],
            'attachment_path' => $prepared['attachment_path'],
            'attachment_mime' => $prepared['attachment_mime'],
            'metadata' => $prepared['metadata'],
        ];

        if ($prepared['unclear']) {
            return $this->chatbotService->appendDirectExchange(
                $user,
                $instance,
                $prepared['message_text'],
                $this->voiceInboundService->unclearReplyText(),
                $conversationId,
                $options,
            );
        }

        return $this->chatbotService->sendMessage(
            $user,
            $instance,
            $prepared['message_text'],
            $conversationId,
            $options,
        );
    }

    public function parseIncomingMessage(Request $request): ?GreenApiIncomingMessage
    {
        $chatId = $this->extractChatId($request);
        if ($chatId === null) {
            return null;
        }

        $messageId = $this->extractMessageId($request);
        $type = strtolower(trim((string) $request->input('messageData.typeMessage', $request->input('typeMessage', 'textMessage'))));

        $text = null;
        foreach ([
            'messageData.textMessageData.textMessage',
            'messageData.extendedTextMessageData.text',
            'messageData.reactionMessageData.text',
            'message',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $text = trim($value);
                break;
            }
        }

        $caption = null;
        foreach ([
            'messageData.imageMessageData.caption',
            'messageData.fileMessageData.caption',
            'messageData.stickerMessageData.caption',
            'messageData.caption',
            'messageData.imageMessage.caption',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $caption = trim($value);
                break;
            }
        }

        $downloadUrl = null;
        foreach ([
            'messageData.fileMessageData.downloadUrl',
            'messageData.stickerMessageData.downloadUrl',
            'messageData.imageMessageData.downloadUrl',
            'messageData.audioMessageData.downloadUrl',
            'messageData.pttMessageData.downloadUrl',
            'messageData.downloadUrl',
            'downloadUrl',
            'messageData.fileMessageData.urlFile',
            'messageData.stickerMessageData.urlFile',
            'messageData.audioMessageData.urlFile',
            'messageData.pttMessageData.urlFile',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '' && str_starts_with(trim($value), 'http')) {
                $downloadUrl = trim($value);
                break;
            }
        }

        $mimeType = null;
        foreach ([
            'messageData.fileMessageData.mimeType',
            'messageData.stickerMessageData.mimeType',
            'messageData.imageMessageData.mimeType',
            'messageData.audioMessageData.mimeType',
            'messageData.pttMessageData.mimeType',
            'messageData.mimeType',
            'mimeType',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $mimeType = trim($value);
                break;
            }
        }

        $fileName = null;
        foreach ([
            'messageData.fileMessageData.fileName',
            'messageData.stickerMessageData.fileName',
            'messageData.imageMessageData.fileName',
            'messageData.audioMessageData.fileName',
            'messageData.pttMessageData.fileName',
            'messageData.fileName',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $fileName = trim($value);
                break;
            }
        }

        $quotedText = null;
        foreach ([
            'messageData.quotedMessage.textMessage',
            'messageData.quotedMessage.extendedTextMessageData.text',
            'messageData.quotedMessage.caption',
            'messageData.quotedMessage.text',
        ] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $quotedText = trim($value);
                break;
            }
        }

        $senderName = null;
        foreach (['senderData.senderName', 'senderData.chatName', 'senderName'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $senderName = trim($value);
                break;
            }
        }

        $isStickerType = in_array($type, ['stickermessage', 'stickermessagedata'], true);
        $isReactionType = in_array($type, ['reactionmessage', 'reactionmessagedata'], true);

        if ($downloadUrl !== null && ($type === 'textmessage' || $type === '')) {
            $guessMime = strtolower((string) $mimeType);
            $type = match (true) {
                str_starts_with($guessMime, 'audio/') || $guessMime === 'application/ogg' => 'audioMessage',
                $guessMime === 'application/pdf' => 'documentMessage',
                default => 'imageMessage',
            };
        }

        if ($text === null && $caption === null && $downloadUrl === null && ! $isStickerType && ! $isReactionType) {
            return null;
        }

        return new GreenApiIncomingMessage(
            type: $type !== '' ? $type : 'textMessage',
            chatId: $chatId,
            messageId: $messageId,
            text: $text,
            caption: $caption,
            downloadUrl: $downloadUrl,
            mimeType: $mimeType,
            fileName: $fileName,
            senderName: $senderName,
            timestamp: $this->extractMessageTimestamp($request),
            raw: $request->all(),
            quotedText: $quotedText,
        );
    }

    /**
     * Green API unix timestamp (seconds). Values in milliseconds are normalized.
     */
    private function extractMessageTimestamp(Request $request): ?int
    {
        foreach (['timestamp', 'messageData.timestamp', 'time'] as $path) {
            $value = $request->input($path);
            if (! is_numeric($value)) {
                continue;
            }

            $unix = (int) $value;
            if ($unix > 1_000_000_000_000) {
                $unix = (int) floor($unix / 1000);
            }

            if ($unix > 1_000_000_000) {
                return $unix;
            }
        }

        return null;
    }

    /**
     * After the bot is switched on, queued/replayed WhatsApp messages from before
     * that moment must not get an auto-reply. Live messages after activation do.
     */
    public function isInboundFromBeforeActivation(ChatbotInstance $instance, GreenApiIncomingMessage $incoming): bool
    {
        $activatedAt = $instance->bot_activated_at;
        if ($activatedAt === null || $incoming->timestamp === null || $incoming->timestamp <= 0) {
            return false;
        }

        $cutoff = $activatedAt->getTimestamp() - 90;

        return $incoming->timestamp < $cutoff;
    }

    /**
     * @return array{api_url:string,id_instance:string,api_token:string}|null
     */
    public function parseInstanceCredentials(ChatbotInstance $instance): ?array
    {
        $url = trim((string) ($instance->greenapi_url ?? ''));
        if ($url === '') {
            return null;
        }

        if (! preg_match('~^(https://[^/\s]+)/waInstance(\d+)/sendMessage/([^/\s?]+)~i', $url, $matches)) {
            return null;
        }

        return [
            'api_url' => $matches[1],
            'id_instance' => $matches[2],
            'api_token' => $matches[3],
        ];
    }

    /**
     * Phone ".." takeover needs Green API outgoingMessageWebhook (messages typed on the phone).
     */
    public function ensureOutgoingPhoneWebhook(ChatbotInstance $instance): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $cacheKey = 'greenapi_outgoing_phone_webhook:'.$instance->id;
        if (Cache::get($cacheKey)) {
            return;
        }

        $failKey = $cacheKey.':fail';
        if (Cache::has($failKey)) {
            return;
        }

        $creds = $this->parseInstanceCredentials($instance);
        if ($creds === null) {
            return;
        }

        $base = $creds['api_url'].'/waInstance'.$creds['id_instance'];
        $token = $creds['api_token'];

        try {
            $current = Http::timeout(8)
                ->acceptJson()
                ->get($base.'/getSettings/'.$token);

            if ($current->successful()) {
                $settings = $current->json();
                $flag = strtolower(trim((string) (is_array($settings) ? ($settings['outgoingMessageWebhook'] ?? '') : '')));
                if (in_array($flag, ['yes', 'true', '1'], true)) {
                    Cache::put($cacheKey, true, now()->addDays(7));

                    return;
                }
            }

            $set = Http::timeout(8)
                ->acceptJson()
                ->post($base.'/setSettings/'.$token, [
                    'outgoingMessageWebhook' => 'yes',
                ]);

            if ($set->successful()) {
                Cache::put($cacheKey, true, now()->addDays(7));
                Log::info('Enabled Green API outgoingMessageWebhook for phone .. toggle', [
                    'instance_id' => $instance->id,
                ]);

                return;
            }

            Cache::put($failKey, true, now()->addMinutes(10));
            Log::warning('Could not enable Green API outgoingMessageWebhook', [
                'instance_id' => $instance->id,
                'status' => $set->status(),
            ]);
        } catch (Throwable $e) {
            Cache::put($failKey, true, now()->addMinutes(10));
            Log::warning('Could not enable Green API outgoingMessageWebhook', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull queued Green API notifications (HTTP API queue) and process them.
     * Useful as a live backfill when webhook delivery is delayed or missed.
     *
     * @return array{drained:int,processed:int}
     */
    public function drainIncomingNotifications(ChatbotInstance $instance, int $max = 10): array
    {
        $creds = $this->parseInstanceCredentials($instance);
        if ($creds === null) {
            return ['drained' => 0, 'processed' => 0];
        }

        $this->ensureOutgoingPhoneWebhook($instance);

        $drained = 0;
        $processed = 0;
        $base = $creds['api_url'].'/waInstance'.$creds['id_instance'];

        for ($i = 0; $i < $max; $i++) {
            try {
                $response = Http::timeout(8)
                    ->acceptJson()
                    ->get($base.'/receiveNotification/'.$creds['api_token'], [
                        'receiveTimeout' => 1,
                    ]);
            } catch (Throwable $e) {
                Log::warning('Green API receiveNotification failed', [
                    'instance_id' => $instance->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            if (! $response->successful()) {
                break;
            }

            $payload = $response->json();
            if (! is_array($payload) || empty($payload['receiptId'])) {
                break;
            }

            $receiptId = $payload['receiptId'];
            $body = $payload['body'] ?? null;
            $drained++;

            if (is_array($body) && in_array((string) ($body['typeWebhook'] ?? ''), [
                'incomingMessageReceived',
                'outgoingMessageReceived',
            ], true)) {
                try {
                    $request = Request::create('/', 'POST', $body);
                    $result = $this->handleWebhook($instance, $request);
                    if (($result['ok'] ?? false) === true && empty($result['ignored'])) {
                        $processed++;
                    }
                } catch (Throwable $e) {
                    Log::warning('Green API queued notification processing failed', [
                        'instance_id' => $instance->id,
                        'receipt_id' => $receiptId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                Http::timeout(8)
                    ->acceptJson()
                    ->delete($base.'/deleteNotification/'.$creds['api_token'].'/'.$receiptId);
            } catch (Throwable $e) {
                Log::warning('Green API deleteNotification failed', [
                    'instance_id' => $instance->id,
                    'receipt_id' => $receiptId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['drained' => $drained, 'processed' => $processed];
    }

    /**
     * @return array{status: int, body: mixed}
     */
    public function sendMessage(string $sendUrl, string $chatId, string $message, ?int $typingTimeMs = null): array
    {
        $payload = [
            'chatId' => $chatId,
            'message' => $message,
        ];

        $typingTime = $this->normalizeTypingTimeMs(
            $typingTimeMs ?? $this->typingTimeForText($message)
        );
        if ($typingTime !== null) {
            $payload['typingTime'] = $typingTime;
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->post($sendUrl, $payload);

        if (! $response->successful()) {
            Log::warning('Green API sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * @return array{status: int, body: mixed}
     */
    public function sendFileByUrl(
        string $sendUrl,
        string $chatId,
        string $fileUrl,
        string $fileName,
        ?string $caption = null,
    ): array {
        $url = $this->fileUrlFromSendUrl($sendUrl);
        if ($url === null) {
            Log::warning('Green API sendFileByUrl skipped — send URL has no sendMessage path', [
                'chat_id' => $chatId,
            ]);

            return ['status' => 0, 'body' => null];
        }

        $payload = [
            'chatId' => $chatId,
            'urlFile' => $fileUrl,
            'fileName' => $fileName,
        ];
        $caption = trim((string) $caption);
        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $response = Http::timeout(60)
            ->acceptJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('Green API sendFileByUrl failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    public function fileUrlFromSendUrl(string $sendUrl): ?string
    {
        $sendUrl = trim($sendUrl);
        if ($sendUrl === '' || ! str_contains($sendUrl, '/sendMessage/')) {
            return null;
        }

        return str_ireplace('/sendMessage/', '/sendFileByUrl/', $sendUrl);
    }

    /**
     * Best-effort WhatsApp "typing…" presence (Green API sendTyping).
     *
     * @return array{status: int, body: mixed}|null
     */
    public function sendTyping(string $sendUrl, string $chatId, ?int $typingTimeMs = null): ?array
    {
        $typingUrl = $this->typingUrlFromSendUrl($sendUrl);
        if ($typingUrl === null) {
            return null;
        }

        $payload = ['chatId' => $chatId];
        $typingTime = $this->normalizeTypingTimeMs($typingTimeMs ?? $this->thinkingTypingTimeMs());
        if ($typingTime !== null) {
            $payload['typingTime'] = $typingTime;
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->post($typingUrl, $payload);

            return [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        } catch (Throwable $e) {
            Log::debug('Green API sendTyping failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Keep WhatsApp "typing…" visible for most of $seconds, with brief natural pauses.
     * Green API pulses max at 20s, so we renew before they expire instead of flickering.
     */
    public function sustainTypingPresence(string $sendUrl, string $chatId, float $seconds): void
    {
        $settings = $this->settingsService->all();
        if (! ($settings['typing_delay_enabled'] ?? true)) {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }

            return;
        }

        $seconds = max(0.0, $seconds);
        if ($seconds < 0.35) {
            return;
        }

        $deadline = microtime(true) + $seconds;
        $pulseCount = 0;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.2) {
                break;
            }

            // Pulse length follows the remaining wait — never pad a short ask to 4s+.
            $pulseSeconds = min(18.0, max(1.0, $remaining + 0.4));
            $this->sendTyping($sendUrl, $chatId, (int) round($pulseSeconds * 1000));
            $pulseCount++;

            // Hold typing for most of the remaining wait, leave a little headroom to renew.
            $activeSeconds = min($remaining - 0.12, max(0.25, $pulseSeconds - 0.35));
            if ($activeSeconds > 0.2) {
                usleep((int) round($activeSeconds * 1_000_000));
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.35) {
                break;
            }

            // Occasional short pause mid-typing (realistic), not every pulse.
            if ($pulseCount % 2 === 0 && $remaining > 1.2) {
                $pause = 0.25 + (mt_rand(0, 450) / 1000);
                usleep((int) round(min($pause, $remaining - 0.2) * 1_000_000));
            }
        }
    }

    public function typingUrlFromSendUrl(string $sendUrl): ?string
    {
        $sendUrl = trim($sendUrl);
        if ($sendUrl === '' || ! str_contains($sendUrl, '/sendMessage/')) {
            return null;
        }

        return str_ireplace('/sendMessage/', '/sendTyping/', $sendUrl);
    }

    /**
     * Typing duration for an outbound WhatsApp message.
     * Green API only accepts 1000–20000 ms; scales with length (about 1–12s).
     * Kaman POS uses the human sales-agent buckets (1.2–8s) with random variation.
     */
    public function typingTimeForText(string $text, ?ChatbotInstance $instance = null): ?int
    {
        $settings = $this->settingsService->all();
        if (! ($settings['typing_delay_enabled'] ?? true)) {
            return null;
        }

        if ($instance?->hasKamanWhatsappIntegration()) {
            return $this->normalizeTypingTimeMs(KamanHumanDelay::millisecondsForText($text));
        }

        return $this->normalizeTypingTimeMs(CampaignHumanDelay::millisecondsForText($text));
    }

    /**
     * Split a Kaman sales reply into 1–2 WhatsApp bubbles and send with human typing delays.
     *
     * @return array{status: int, body: mixed}
     */
    public function sendKamanWhatsAppReply(
        ChatbotInstance $instance,
        string $sendUrl,
        string $chatId,
        ChatbotMessage $assistantMessage,
        string $reply,
    ): array {
        $bubbles = KamanHumanDelay::splitBubbles($reply);
        if ($bubbles === []) {
            $bubbles = [trim($reply)];
        }

        $first = array_shift($bubbles) ?? trim($reply);
        if ($first !== (string) $assistantMessage->message) {
            $assistantMessage->forceFill(['message' => $first])->save();
        }

        $sendResult = $this->sendMessage(
            $sendUrl,
            $chatId,
            $first,
            $this->typingTimeForText($first, $instance),
        );

        $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
        $assistantMessage->forceFill([
            'delivery_status' => $ok ? 'sent' : 'failed',
        ])->save();

        $conversation = $assistantMessage->conversation;
        $triggerUserId = (int) ($conversation?->messages()
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('id') ?? 0);

        foreach ($bubbles as $index => $bubble) {
            if ($conversation === null || trim($bubble) === '') {
                continue;
            }

            $follow = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $bubble,
                'delivery_status' => 'pending',
                'metadata' => [
                    'kaman_bubble' => $index + 2,
                    'kaman_followup' => true,
                ],
            ]);

            SendDelayedKamanWhatsAppJob::dispatch(
                (int) $instance->id,
                (int) $follow->id,
                $chatId,
                $triggerUserId,
            )->delay(now()->addMilliseconds((int) round(KamanHumanDelay::secondsBetweenBubbles() * 1000)));
        }

        if ($conversation !== null) {
            $conversation->recordAssistantActivity();
            app(KamanLeadScorer::class)->refresh($conversation->fresh() ?? $conversation);
        }

        return $sendResult;
    }

    /**
     * Long typing pulse while waiting / generating — keeps the indicator visible.
     */
    public function thinkingTypingTimeMs(): ?int
    {
        $settings = $this->settingsService->all();
        if (! ($settings['typing_delay_enabled'] ?? true)) {
            return null;
        }

        return $this->normalizeTypingTimeMs(18000);
    }

    /**
     * Clamp to Green API sendTyping / sendMessage typingTime limits.
     */
    public function normalizeTypingTimeMs(?int $ms): ?int
    {
        if ($ms === null || $ms <= 0) {
            return null;
        }

        return max(1000, min(20000, $ms));
    }

    private function touchWhatsAppContact(ChatbotConversation $conversation, GreenApiIncomingMessage $incoming): void
    {
        $updates = [];
        if ($incoming->senderName && ! $conversation->contact_name) {
            $updates['contact_name'] = $incoming->senderName;
        } elseif ($incoming->senderName && $conversation->contact_name !== $incoming->senderName) {
            $updates['contact_name'] = $incoming->senderName;
        }

        if (! $conversation->contact_phone) {
            $updates['contact_phone'] = $this->phoneFromChatId($incoming->chatId);
        }

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['last_webhook'] = $this->safeWebhookMetadata($incoming);
        $updates['metadata'] = $meta;

        if ($updates !== []) {
            $conversation->forceFill($updates)->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeWebhookMetadata(GreenApiIncomingMessage $incoming): array
    {
        return array_filter([
            'type' => $incoming->type,
            'chat_id' => $incoming->chatId,
            'message_id' => $incoming->messageId,
            'sender_name' => $incoming->senderName,
            'mime_type' => $incoming->mimeType,
            'file_name' => $incoming->fileName,
            'received_at' => now()->toIso8601String(),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private function phoneFromChatId(string $chatId): ?string
    {
        $phone = preg_replace('/@.*$/', '', $chatId);
        $phone = is_string($phone) ? trim($phone) : '';

        return $phone !== '' ? $phone : null;
    }

    /**
     * Empty allowlist = unrestricted. Otherwise only listed numbers get auto-replies.
     */
    public function isSenderAllowedToReceiveReply(ChatbotInstance $instance, string $chatId): bool
    {
        // Personal allowlists never apply to WhatsApp groups.
        if (str_contains(strtolower($chatId), '@g.us')) {
            return ! $instance->hasReplyPhoneAllowlist();
        }

        if (! $instance->hasReplyPhoneAllowlist()) {
            return true;
        }

        $phone = $this->phoneFromChatId($chatId);
        if ($phone === null) {
            return false;
        }

        $normalizedSender = $this->normalizePhoneDigits($phone);
        if ($normalizedSender === '') {
            return false;
        }

        foreach ($instance->allowedReplyPhones() as $allowed) {
            if ($this->normalizePhoneDigits($allowed) === $normalizedSender) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ignore list always wins: listed personal numbers never get auto-replies,
     * even when the bot is globally active. Groups are never matched by phone.
     */
    public function isSenderIgnoredFromReply(ChatbotInstance $instance, string $chatId): bool
    {
        if (! $instance->hasReplyPhoneIgnoreList()) {
            return false;
        }

        if (str_contains(strtolower($chatId), '@g.us')) {
            return false;
        }

        $phone = $this->phoneFromChatId($chatId);
        if ($phone === null) {
            return false;
        }

        $normalizedSender = $this->normalizePhoneDigits($phone);
        if ($normalizedSender === '') {
            return false;
        }

        foreach ($instance->ignoredReplyPhones() as $ignored) {
            if ($this->normalizePhoneDigits($ignored) === $normalizedSender) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize Israeli / international WhatsApp numbers for comparison.
     * Examples: 0533046830, +972533046830, 972533046830@c.us → 972533046830
     */
    public function normalizePhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && ! str_starts_with($digits, '00')) {
            $digits = '972'.substr($digits, 1);
        }

        return $digits;
    }

    private function messageExistsInDatabase(ChatbotInstance $instance, string $messageId): bool
    {
        return ChatbotMessage::query()
            ->where('external_message_id', $messageId)
            ->whereHas('conversation', function ($q) use ($instance): void {
                $q->where('instance_id', $instance->id)
                    ->where('channel', ChatbotConversation::CHANNEL_WHATSAPP);
            })
            ->exists();
    }

    private function conversationCacheKey(ChatbotInstance $instance, string $chatId): string
    {
        return 'chatbot_greenapi_conversation:'.$instance->id.':'.sha1($chatId);
    }

    private function processedMessageCacheKey(ChatbotInstance $instance, string $messageId): string
    {
        return 'chatbot_greenapi_processed:'.$instance->id.':'.sha1($messageId);
    }

    private function conversationIdForChat(ChatbotInstance $instance, string $chatId): ?int
    {
        $cached = Cache::get($this->conversationCacheKey($instance, $chatId));

        return is_numeric($cached) ? (int) $cached : null;
    }

    private function rememberConversationForChat(ChatbotInstance $instance, string $chatId, int $conversationId): void
    {
        Cache::put(
            $this->conversationCacheKey($instance, $chatId),
            $conversationId,
            self::CONVERSATION_CACHE_TTL_SECONDS,
        );
    }

    private function isMessageAlreadyProcessed(ChatbotInstance $instance, string $messageId): bool
    {
        return Cache::has($this->processedMessageCacheKey($instance, $messageId));
    }

    private function markMessageAsProcessed(ChatbotInstance $instance, string $messageId): void
    {
        Cache::put(
            $this->processedMessageCacheKey($instance, $messageId),
            true,
            self::PROCESSED_MESSAGE_TTL_SECONDS,
        );
    }

    /**
     * Phone command: send ".." in a WhatsApp chat to pause the bot there,
     * send ".." again to resume. Only that conversation is affected.
     * Green API type outgoingMessageReceived = typed on the phone, not API/bot.
     *
     * @return array<string, mixed>|null
     */
    private function handleStaffDotToggleIfNeeded(ChatbotInstance $instance, Request $request): ?array
    {
        if (! $this->isOutgoingPhoneMessageWebhook($request)) {
            return null;
        }

        $incoming = $this->parseIncomingMessage($request);
        if ($incoming === null || $incoming->chatId === '') {
            return null;
        }

        if (! $this->isStaffDotCommand((string) ($incoming->text ?? $incoming->caption ?? ''))) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored outbound phone message (not a .. toggle).',
            ];
        }

        if ($incoming->messageId !== null && $this->isMessageAlreadyProcessed($instance, $incoming->messageId)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate .. toggle.',
            ];
        }

        if ($incoming->messageId !== null) {
            $this->markMessageAsProcessed($instance, $incoming->messageId);
        }

        $debounceKey = 'phone_dot_toggle:'.$instance->id.':'.sha1($incoming->chatId);
        if (! app()->runningUnitTests() && Cache::has($debounceKey)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate .. toggle.',
            ];
        }
        if (! app()->runningUnitTests()) {
            Cache::put($debounceKey, true, 3);
        }

        $campaignSilence = $this->routeStaffDotToCampaignIfNeeded($instance, $incoming);
        if ($campaignSilence !== null) {
            return $campaignSilence;
        }

        $conversation = $this->findOrCreateWhatsAppConversation($instance, $incoming);
        $this->rememberConversationForChat($instance, $incoming->chatId, (int) $conversation->id);

        $wasActive = $conversation->allowsAutomaticReply();
        $nextMode = $wasActive
            ? ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER
            : ChatbotConversation::BOT_MODE_ACTIVE;

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $version = ((int) ($meta['kaman_conversation_version'] ?? 0)) + 1;
        $meta['kaman_conversation_version'] = $version;
        $meta['kaman_burst_open'] = false;
        $meta['phone_dot_control'] = $wasActive ? 'stopped' : 'resumed';
        $meta['phone_dot_control_at'] = now()->toIso8601String();

        $updates = [
            'bot_mode' => $nextMode,
            'metadata' => $meta,
        ];
        if ($wasActive) {
            $updates['attention_status'] = ChatbotConversation::ATTENTION_NEEDS;
            if ($instance->user_id) {
                $updates['assigned_user_id'] = $instance->user_id;
            }
        }

        $conversation->forceFill($updates)->save();
        $this->kamanBurstService->invalidatePendingOutbound(
            $conversation,
            $version,
            'phone_dot_toggle',
        );

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
                'bot_mode' => $nextMode,
                'greenapi_chat_id' => $incoming->chatId,
            ],
        ]);

        Log::info('WhatsApp phone .. toggled conversation bot', [
            'instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'chat_id' => $incoming->chatId,
            'bot_mode' => $nextMode,
        ]);

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'phone_dot_toggle' => true,
            'bot_mode' => $nextMode,
            'bot_stopped' => $wasActive,
            'reply' => null,
        ];
    }

    public function isOutgoingPhoneMessageWebhook(Request $request): bool
    {
        $typeWebhook = strtolower(trim((string) $request->input('typeWebhook', '')));

        if ($typeWebhook === 'outgoingmessagereceived') {
            return true;
        }

        if ($typeWebhook === 'outgoingapimessagereceived') {
            return false;
        }

        // Some Green API accounts echo phone-sent messages as incoming + fromMe.
        return $typeWebhook === 'incomingmessagereceived' && $this->webhookIsFromMe($request);
    }

    private function webhookIsFromMe(Request $request): bool
    {
        foreach (['messageData.fromMe', 'senderData.fromMe'] as $path) {
            $fromMe = $request->input($path);
            if (is_bool($fromMe) && $fromMe) {
                return true;
            }
            if ($fromMe === 1 || $fromMe === '1' || $fromMe === 'true') {
                return true;
            }
        }

        return false;
    }

    public function isStaffDotCommand(string $text): bool
    {
        return trim($text) === '..';
    }

    private function isIncomingMessageWebhook(Request $request): bool
    {
        $typeWebhook = strtolower(trim((string) $request->input('typeWebhook', '')));

        if ($typeWebhook !== '' && $typeWebhook !== 'incomingmessagereceived') {
            return false;
        }

        return ! $this->webhookIsFromMe($request);
    }

    public function isInboundCustomerWebhook(Request $request): bool
    {
        return $this->isIncomingMessageWebhook($request);
    }

    /**
     * Extract Green API idInstance from a sendMessage URL.
     */
    public function greenApiInstanceIdFromUrl(?string $sendUrl): ?string
    {
        $url = trim((string) $sendUrl);
        if ($url === '') {
            return null;
        }

        if (! preg_match('#/waInstance(\d+)/#i', $url, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Phone ".." in a campaign lead chat must mute that campaign conversation,
     * not a newly created inbox thread with the raw WhatsApp chat id.
     *
     * @return array<string, mixed>|null
     */
    private function routeStaffDotToCampaignIfNeeded(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
    ): ?array {
        if (! $instance->hasMalanIntegration()) {
            return null;
        }

        $campaign = $this->findCampaignForStaffDot($instance, $incoming->chatId);
        if ($campaign === null) {
            return null;
        }

        if ($incoming->messageId !== null) {
            $this->markMessageAsProcessed($instance, $incoming->messageId);
        }

        return app(\App\Services\Malan\Campaigns\MalanCampaignWebhookService::class)
            ->silenceConversationForStaffDot($campaign, $incoming);
    }

    private function findCampaignForStaffDot(ChatbotInstance $instance, string $chatId): ?\App\Models\Malan\MalanCampaign
    {
        try {
            $route = app(\App\Services\Malan\Campaigns\MalanCampaignService::class)
                ->findActiveCampaignRoute($instance, $chatId);
            if ($route !== null) {
                return $route['campaign'];
            }
        } catch (Throwable $e) {
            Log::warning('Campaign .. silence route lookup failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        }

        $conversation = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->whereNotNull('campaign_id')
            ->where(function ($q) use ($chatId): void {
                $q->where('external_chat_id', $chatId)
                    ->orWhere('external_chat_id', 'like', '%:'.$chatId)
                    ->orWhere('metadata->whatsapp_chat_id', $chatId);
            })
            ->orderByDesc('id')
            ->first();

        return $conversation?->campaign;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routeInboundToCampaignIfNeeded(
        ChatbotInstance $instance,
        GreenApiIncomingMessage $incoming,
        Request $request,
    ): ?array {
        if (! $instance->hasMalanIntegration()) {
            return null;
        }

        try {
            $route = app(\App\Services\Malan\Campaigns\MalanCampaignService::class)
                ->findActiveCampaignRoute($instance, $incoming->chatId);
        } catch (Throwable $e) {
            Log::warning('Campaign route lookup failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($route === null) {
            return null;
        }

        /** @var \App\Models\Malan\MalanCampaign $campaign */
        $campaign = $route['campaign'];

        if ($incoming->messageId !== null) {
            if ($this->isMessageAlreadyProcessed($instance, $incoming->messageId)
                || $this->messageExistsInDatabase($instance, $incoming->messageId)) {
                $this->markMessageAsProcessed($instance, $incoming->messageId);

                return [
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'Ignored duplicate campaign inbound.',
                    'campaign_id' => $campaign->id,
                ];
            }
            $this->markMessageAsProcessed($instance, $incoming->messageId);
        }

        return app(\App\Services\Malan\Campaigns\MalanCampaignWebhookService::class)
            ->handleParsedInbound($campaign, $incoming, $request);
    }

    private function looksLikeOwnAssistantEcho(ChatbotInstance $instance, GreenApiIncomingMessage $incoming): bool
    {
        $text = $this->normalizeComparableText($incoming->customerFacingText() ?? '');
        if ($text === '') {
            return false;
        }

        if ($incoming->messageId !== null) {
            $knownOutbound = ChatbotMessage::query()
                ->whereHas('conversation', function ($q) use ($instance): void {
                    $q->where('instance_id', $instance->id);
                })
                ->where('role', 'assistant')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->where('metadata->greenapi_id_message', $incoming->messageId)
                ->exists();
            if ($knownOutbound) {
                return true;
            }
        }

        $conversation = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('channel', ChatbotConversation::CHANNEL_WHATSAPP)
            ->where(function ($q) use ($incoming): void {
                $q->where('external_chat_id', $incoming->chatId)
                    ->orWhere('external_chat_id', 'like', '%:'.$incoming->chatId)
                    ->orWhere('contact_phone', preg_replace('/\D+/', '', explode('@', $incoming->chatId)[0] ?? ''));
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($conversation as $conv) {
            $recent = ChatbotMessage::query()
                ->where('conversation_id', $conv->id)
                ->where('role', 'assistant')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->orderByDesc('id')
                ->limit(6)
                ->get(['message']);

            foreach ($recent as $message) {
                $assistantText = $this->normalizeComparableText((string) $message->message);
                if ($assistantText !== '' && $assistantText === $text) {
                    return true;
                }
                similar_text($assistantText, $text, $percent);
                if ($percent >= 92) {
                    return true;
                }
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

    private function extractChatId(Request $request): ?string
    {
        foreach (['senderData.chatId', 'chatId', 'messageData.chatId'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractMessageId(Request $request): ?string
    {
        foreach (['idMessage', 'messageData.idMessage', 'messageData.message.idMessage'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
