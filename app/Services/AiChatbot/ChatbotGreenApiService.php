<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Jobs\ProcessKamanConversationJob;
use App\Jobs\ProcessMansourConversationJob;
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
        protected MansourConversationBurstService $mansourBurstService,
        protected WhapiCloudClient $whapiClient,
    ) {}

    public function webhookUrl(ChatbotInstance $instance): string
    {
        $token = $this->ensureWebhookToken($instance);

        return route('ai-chatbot.greenapi.webhook', ['token' => $token]);
    }

    public function webhookUrlForLine(ChatbotInstance $instance, string $lineId): string
    {
        $token = $this->ensureWebhookToken($instance);

        return route('ai-chatbot.greenapi.line-webhook', [
            'token' => $token,
            'line' => $lineId,
        ]);
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

    /**
     * @return string|null error translation key
     */
    public function assignWebhookToken(ChatbotInstance $instance, ?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $token = WhapiCloudClient::parseWebhookToken($input);
        if ($token === null) {
            return 'chatbot.webhook_token_invalid';
        }

        if ($token === $instance->greenapi_webhook_token) {
            return null;
        }

        $taken = ChatbotInstance::query()
            ->where('greenapi_webhook_token', $token)
            ->where('id', '!=', $instance->id)
            ->exists();
        if ($taken) {
            return 'chatbot.webhook_token_taken';
        }

        $instance->greenapi_webhook_token = $token;

        return null;
    }

    public function findByWebhookToken(string $token): ?ChatbotInstance
    {
        return ChatbotInstance::query()
            ->where('greenapi_webhook_token', $token)
            ->first();
    }

    /**
     * @return array<int, Request>|null
     */
    public function expandTransportWebhook(Request $request): ?array
    {
        return $this->whapiClient->expand($request);
    }

    public function prepareTransportWebhook(Request $request): Request
    {
        return $this->whapiClient->asGreenRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(ChatbotInstance $instance, Request $request): array
    {
        $expanded = $this->whapiClient->expand($request);
        if ($expanded !== null) {
            $results = [];
            foreach ($expanded as $part) {
                $results[] = $this->handleWebhook($instance, $part);
            }

            return [
                'ok' => true,
                'batch' => count($results),
                'results' => $results,
            ];
        }

        $request = $this->whapiClient->asGreenRequest($request);

        if (! $this->whapiClient->usesWhapi()) {
            $this->ensureOutgoingPhoneWebhook($instance);
        }

        if ($dotToggle = $this->handleStaffDotToggleIfNeeded($instance, $request)) {
            return $dotToggle;
        }

        if ($instance->hasMansourIntegration() && $request->input('typeWebhook') === 'stateInstanceChanged') {
            $line = trim((string) $request->input('_mansour_line', ''));
            if ($line === '') {
                $line = (string) $request->input('instanceData.idInstance', '');
            }
            $state = (string) $request->input('stateInstance', '');
            if ($line !== '' && $state !== '') {
                app(MansourLines::class)->stateChanged($instance, $line, $state);
            }

            return ['ok' => true, 'line' => $line, 'state' => $state];
        }

        if (! $this->isIncomingMessageWebhook($request)) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored non-incoming webhook event.',
            ];
        }

        $sendUrl = trim((string) ($instance->greenapi_url ?? ''));
        if ($sendUrl === '' && $instance->hasMansourIntegration()) {
            foreach (app(MansourLines::class)->lines($instance) as $configuredLine) {
                if ($configuredLine['status'] !== MansourLines::BLOCKED && $configuredLine['url'] !== '') {
                    $sendUrl = $configuredLine['url'];
                    break;
                }
            }
        }
        if (! $this->whapiClient->transportReady($sendUrl)) {
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

        $inboundLine = null;
        if ($instance->hasMansourIntegration()) {
            $explicitLine = trim((string) $request->input('_mansour_line', ''));
            if ($explicitLine === '') {
                $explicitLine = trim((string) $request->input('instanceData.idInstance', ''));
            }
            $inboundLine = app(MansourLines::class)->inboundLineId($instance, $explicitLine);
        }

        if ($instance->hasMansourIntegration() && ($inboundLine === null || ! $this->mansourTriggeredSender($instance, $incoming->chatId, $inboundLine))) {
            if ($incoming->messageId !== null) {
                $this->markMessageAsProcessed($instance, $incoming->messageId);
            }

            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored WhatsApp sender that was not triggered.',
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

        if ($instance->hasMansourIntegration()) {
            $lines = app(MansourLines::class);
            $conversation = $lines->conversationOnLine($instance, $incoming->chatId, (string) $inboundLine);
            if ($conversation === null) {
                $phone = $this->phoneFromChatId($incoming->chatId);
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
                    'metadata' => array_merge($this->safeWebhookMetadata($incoming), [
                        'mansour_line' => (string) $inboundLine,
                    ]),
                ]);
            } else {
                $this->touchWhatsAppContact($conversation, $incoming);
                $currentLine = trim((string) data_get($conversation->metadata, 'mansour_line', ''));
                if ($currentLine === '') {
                    $lines->useLine($conversation, (string) $inboundLine);
                    $conversation->refresh();
                }
            }
            $ownLine = $lines->lineById($instance, (string) $inboundLine);
            $sendUrl = $lines->urlFor($instance, $conversation, true);
        } else {
            $conversation = $this->findOrCreateWhatsAppConversation($instance, $incoming);
            $this->rememberConversationForChat($instance, $incoming->chatId, (int) $conversation->id);
            $ownLine = null;
        }
        $conversationId = (int) $conversation->id;

        $senderAllowed = $this->isSenderAllowedToReceiveReply($instance, $incoming->chatId, $inboundLine);
        $senderIgnored = $this->isSenderIgnoredFromReply($instance, $incoming->chatId);
        $lineOn = ! $instance->hasMansourIntegration() || ($ownLine !== null && app(MansourLines::class)->isOn($ownLine));
        $shouldAutoReply = $senderAllowed
            && ! $senderIgnored
            && $instance->isBotGloballyActive()
            && $conversation->allowsAutomaticReply()
            && $lineOn
            && (! $instance->hasMansourIntegration() || $sendUrl !== '');

        if ($request->boolean('_sync_hold')) {
            $shouldAutoReply = false;
        }

        if ($shouldAutoReply && $instance->hasKamanWhatsappIntegration() && ! $incoming->isImage() && ! $incoming->isPdf()) {
            return $this->enqueueKamanSalesBurst($instance, $incoming, $conversation, $sendUrl, $user);
        }

        if ($shouldAutoReply && $instance->hasMansourIntegration() && ! $incoming->isPdf() && ! $incoming->isSticker()
            && (! $incoming->isImage() || $instance->isAppsBot())) {
            return $this->enqueueMansourBurst($instance, $incoming, $conversation, $sendUrl, $user);
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

                $previousAssistant = $conversation->messages()->where('role', 'assistant')->orderByDesc('id')->value('message');
                $customerAlreadySpoke = $conversation->messages()->where('role', 'user')->exists();
                if ($instance->hasMansourIntegration()
                    && MansourPrompt::shouldSendContinuation($text, is_string($previousAssistant) ? $previousAssistant : null, $customerAlreadySpoke)) {
                    $listedName = $instance->mansourContacts()->where('chat_id', $incoming->chatId)->value('name');
                    $result = $this->chatbotService->appendDirectExchange(
                        $user,
                        $instance,
                        $text,
                        MansourPrompt::continuationFor(is_string($listedName) ? $listedName : $conversation->contact_name),
                        $conversationId,
                        [
                            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                            'external_message_id' => $incoming->messageId,
                            'message_type' => 'text',
                        ],
                    );
                } else {
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
                if ($instance->hasMansourIntegration()
                    && MansourPrompt::voteGroup($text) === 'certain'
                    && isset($result['conversation'])) {
                    $result['conversation']->forceFill([
                        'bot_mode' => ChatbotConversation::BOT_MODE_PAUSED,
                    ])->save();
                }
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
        if ($instance->hasMansourIntegration() && ! $incoming->isAudio()) {
            $cleaned = MansourPrompt::withoutVoiceComplaint($reply);
            if ($cleaned !== $reply) {
                $assistantMessage->forceFill(['message' => $cleaned])->save();
                $reply = $cleaned;
            }
        }
        $sendResult = $instance->hasKamanWhatsappIntegration()
            ? $this->sendKamanWhatsAppReply($instance, $sendUrl, $incoming->chatId, $assistantMessage, $reply)
            : $this->sendMessage(
                $sendUrl,
                $incoming->chatId,
                $reply,
                $this->typingTimeForText($reply, $instance),
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
     * Store the line, wait for split bubbles, then one reply.
     * A newer line during the wait drops the unsent draft so it can be regenerated.
     *
     * @return array<string, mixed>
     */
    private function enqueueMansourBurst(
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

        if ($incoming->isImage()) {
            $storeOptions['message_type'] = 'image';
            $text = $text !== null && trim($text) !== ''
                ? $text
                : '[أرسل الزبون صورة/ملف عبر WhatsApp]';
            if (is_string($incoming->downloadUrl) && $incoming->downloadUrl !== '') {
                try {
                    $stored = $this->mediaDownloader->downloadToPrivateStorage(
                        $incoming->downloadUrl,
                        $incoming->mimeType,
                    );
                    $storeOptions['attachment_disk'] = $stored['disk'] ?? config('malan.media.disk', 'local');
                    $storeOptions['attachment_path'] = $stored['path'];
                    $storeOptions['attachment_mime'] = $stored['mime_type'];
                } catch (Throwable $e) {
                    Log::warning('Apps screenshot download failed', [
                        'instance_id' => $instance->id,
                        'chat_id' => $incoming->chatId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($incoming->isAudio()) {
            $prepared = $this->voiceInboundService->prepare($incoming, $instance);
            $text = (string) ($prepared['message_text'] ?? '');
            $storeOptions['message_type'] = $prepared['message_type'] ?? 'audio';
            $storeOptions['attachment_disk'] = $prepared['attachment_disk'] ?? null;
            $storeOptions['attachment_path'] = $prepared['attachment_path'] ?? null;
            $storeOptions['attachment_mime'] = $prepared['attachment_mime'] ?? null;
            $storeOptions['metadata'] = $prepared['metadata'] ?? [];
            $heard = (string) ($prepared['transcript'] ?: $text);
            if (($prepared['unclear'] ?? false) || MansourPrompt::voiceTranscriptIsBroken($heard)) {
                $result = $this->chatbotService->appendDirectExchange(
                    $user,
                    $instance,
                    $text !== '' ? $text : $heard,
                    MansourPrompt::UNCLEAR_VOICE_REPLY,
                    (int) $conversation->id,
                    $storeOptions,
                );
                $assistantMessage = $result['assistant_message'] ?? null;
                $reply = $assistantMessage instanceof ChatbotMessage ? (string) $assistantMessage->message : MansourPrompt::UNCLEAR_VOICE_REPLY;
                $sendResult = $this->sendMessage($sendUrl, $incoming->chatId, $reply, $this->typingTimeForText($reply, $instance));
                if ($assistantMessage instanceof ChatbotMessage) {
                    $assistantMessage->forceFill([
                        'delivery_status' => $sendResult['status'] >= 200 && $sendResult['status'] < 300 ? 'sent' : 'failed',
                    ])->save();
                }

                return [
                    'ok' => true,
                    'chatId' => $incoming->chatId,
                    'reply' => $reply,
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
        $conversation = $result['conversation'] ?? $conversation->fresh() ?? $conversation;
        $registration = $this->mansourBurstService->registerInboundMessage($conversation);
        $version = (int) $registration['version'];
        $waitSeconds = (int) $registration['wait_seconds'];

        $this->sendTyping($sendUrl, $incoming->chatId, $this->thinkingTypingTimeMs());
        $this->scheduleMansourProcess(
            (int) $instance->id,
            (int) $conversation->id,
            $version,
            $incoming->chatId,
            $waitSeconds,
        );

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'incoming' => $text,
            'listening' => true,
            'instance_id' => (int) $instance->id,
            'conversation_id' => (int) $conversation->id,
            'conversation_version' => $version,
            'listen_seconds' => $waitSeconds,
            'reply' => null,
        ];
    }

    private function scheduleMansourProcess(
        int $instanceId,
        int $conversationId,
        int $version,
        string $chatId,
        int $waitSeconds,
    ): void {
        $waitSeconds = max(1, min(MansourListeningWindow::MAX_BURST_SECONDS, $waitSeconds));

        $held = ChatbotInstance::query()->find($instanceId);
        if ($held instanceof ChatbotInstance && $held->outboundHeld()) {
            return;
        }

        if (! app()->runningUnitTests() && config('queue.default') !== 'sync') {
            ProcessMansourConversationJob::dispatch($instanceId, $conversationId, $version, $chatId)
                ->delay(now()->addSeconds($waitSeconds));
        }

        if (app()->runningUnitTests()) {
            return;
        }

        dispatch(function () use ($instanceId, $conversationId, $version, $chatId, $waitSeconds): void {
            try {
                @set_time_limit($waitSeconds + 120);
                if ($waitSeconds > 0) {
                    usleep($waitSeconds * 1_000_000);
                }
                $job = new ProcessMansourConversationJob($instanceId, $conversationId, $version, $chatId);
                $job->handle(
                    app(AiChatbotService::class),
                    app(ChatbotGreenApiService::class),
                    app(MansourConversationBurstService::class),
                );
            } catch (Throwable $e) {
                Log::debug('Mansour listening-window afterResponse failed', [
                    'conversation_id' => $conversationId,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
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
        $sendUrl = $instance->hasMansourIntegration()
            ? app(MansourLines::class)->urlFor($instance, $conversation)
            : trim((string) ($instance->greenapi_url ?? ''));
        if (! $this->whapiClient->transportReady($sendUrl)) {
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

        $unclearReply = $this->voiceInboundService->unclearReplyText();
        if ($instance->hasMansourIntegration()) {
            $heard = (string) ($prepared['transcript'] ?: $prepared['message_text']);
            if ($prepared['unclear'] || MansourPrompt::voiceTranscriptIsBroken($heard)) {
                $unclearReply = MansourPrompt::UNCLEAR_VOICE_REPLY;
                $prepared['unclear'] = true;
            }
        }

        if ($prepared['unclear']) {
            return $this->chatbotService->appendDirectExchange(
                $user,
                $instance,
                $prepared['message_text'],
                $unclearReply,
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
        return $this->credentialsFromSendUrl((string) ($instance->greenapi_url ?? ''));
    }

    /**
     * @return array{api_url:string,id_instance:string,api_token:string}|null
     */
    private function credentialsFromSendUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('~^(https://[^/\s]+)/waInstance(\d+)/sendMessage/([^/\s?]+)~i', $url, $matches)) {
            return null;
        }

        return [
            'api_url' => $matches[1],
            'id_instance' => $matches[2],
            'api_token' => $matches[3],
        ];
    }

    /**
     * WhatsApp name and photo for one sendMessage URL.
     *
     * @return array{id: string, name: string, phone: string, avatar: string}
     */
    public function lineProfile(string $url, string $fallbackName = ''): array
    {
        $url = trim($url);
        $id = (string) ($this->greenApiInstanceIdFromUrl($url) ?? '');
        if ($url === '') {
            return ['id' => '', 'name' => $fallbackName, 'phone' => '', 'avatar' => ''];
        }

        $cached = Cache::remember('greenapi.wa-profile.'.sha1($url), now()->addMinutes(15), function () use ($url): array {
            $profile = app(MansourLines::class)->profileFor($url);

            return is_array($profile) ? $profile : ['name' => '', 'phone' => '', 'avatar' => ''];
        });

        $name = trim((string) ($cached['name'] ?? ''));

        return [
            'id' => $id,
            'name' => $name !== '' ? $name : $fallbackName,
            'phone' => trim((string) ($cached['phone'] ?? '')),
            'avatar' => trim((string) ($cached['avatar'] ?? '')),
        ];
    }

    /**
     * WhatsApp name and photo for each Green API line on this bot.
     *
     * @return list<array{id: string, name: string, phone: string, avatar: string}>
     */
    public function connectedProfiles(ChatbotInstance $instance): array
    {
        $lines = app(MansourLines::class)->lines($instance);
        $profiles = [];

        foreach ($lines as $line) {
            $url = trim((string) ($line['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $profile = $this->lineProfile($url, trim((string) ($line['name'] ?? '')));
            if ($profile['phone'] === '' && trim((string) ($line['phone'] ?? '')) !== '') {
                $profile['phone'] = trim((string) $line['phone']);
            }
            if ($profile['avatar'] === '' && trim((string) ($line['avatar'] ?? '')) !== '') {
                $profile['avatar'] = trim((string) $line['avatar']);
            }
            if ($profile['id'] === '') {
                $profile['id'] = (string) ($line['id'] ?? '');
            }
            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * Phone ".." takeover needs Green API outgoingMessageWebhook (messages typed on the phone).
     */
    public function ensureOutgoingPhoneWebhook(ChatbotInstance $instance): void
    {
        if ($this->whapiClient->usesWhapi() || app()->runningUnitTests()) {
            return;
        }

        $cacheKey = 'greenapi_bot_webhooks:'.$instance->id;
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
                $settings = is_array($settings) ? $settings : [];
                $outgoing = strtolower(trim((string) ($settings['outgoingMessageWebhook'] ?? '')));
                $incoming = strtolower(trim((string) ($settings['incomingWebhook'] ?? '')));
                $on = static fn (string $flag): bool => in_array($flag, ['yes', 'true', '1'], true);
                if ($on($outgoing) && $on($incoming)) {
                    Cache::put($cacheKey, true, now()->addDays(7));

                    return;
                }
            }

            $set = Http::timeout(8)
                ->acceptJson()
                ->post($base.'/setSettings/'.$token, [
                    'webhookUrl' => $this->webhookUrlForLine($instance, $creds['id_instance']),
                    'incomingWebhook' => 'yes',
                    'outgoingMessageWebhook' => 'yes',
                    'stateWebhook' => 'yes',
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
        if ($this->whapiClient->usesWhapi() || $instance->outboundHeld()) {
            return ['drained' => 0, 'processed' => 0, 'lines' => 0];
        }

        $targets = $this->notificationTargets($instance);
        if ($targets === []) {
            return ['drained' => 0, 'processed' => 0, 'lines' => 0];
        }

        $perLine = max(1, (int) ceil($max / count($targets)));
        $drained = 0;
        $processed = 0;

        foreach ($targets as $target) {
            $result = $this->drainNotificationLine($instance, $target, $perLine);
            $drained += $result['drained'];
            $processed += $result['processed'];
        }

        return ['drained' => $drained, 'processed' => $processed, 'lines' => count($targets)];
    }

    /**
     * Every Green API line on this bot, plus the primary URL when it is not already listed.
     *
     * @return list<array{base: string, token: string, line: string}>
     */
    private function notificationTargets(ChatbotInstance $instance): array
    {
        $targets = [];
        $push = function (?array $creds, string $lineId) use (&$targets): void {
            if ($creds === null || $lineId === '') {
                return;
            }
            $targets[$creds['id_instance']] = [
                'base' => $creds['api_url'].'/waInstance'.$creds['id_instance'],
                'token' => $creds['api_token'],
                'line' => $lineId,
            ];
        };

        if ($instance->hasMansourIntegration()) {
            $lines = app(MansourLines::class);
            foreach ($lines->lines($instance) as $line) {
                $push($this->credentialsFromSendUrl($line['url']), $line['id']);
            }
        }

        $primary = $this->parseInstanceCredentials($instance);
        if ($primary !== null && ! isset($targets[$primary['id_instance']])) {
            $lineId = $instance->hasMansourIntegration()
                ? (string) (app(MansourLines::class)->primaryId($instance) ?? $primary['id_instance'])
                : $primary['id_instance'];
            $push($primary, $lineId);
        }

        return array_values($targets);
    }

    /**
     * @param  array{base: string, token: string, line: string}  $target
     * @return array{drained: int, processed: int}
     */
    private function drainNotificationLine(ChatbotInstance $instance, array $target, int $max): array
    {
        $drained = 0;
        $processed = 0;

        for ($i = 0; $i < $max; $i++) {
            try {
                $response = Http::connectTimeout(4)
                    ->timeout(12)
                    ->acceptJson()
                    ->get($target['base'].'/receiveNotification/'.$target['token'], [
                        'receiveTimeout' => 2,
                    ]);
            } catch (Throwable $e) {
                Log::warning('Green API receiveNotification failed', [
                    'instance_id' => $instance->id,
                    'line' => $target['line'],
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
            $delete = true;

            if (is_array($body) && in_array((string) ($body['typeWebhook'] ?? ''), [
                'incomingMessageReceived',
                'outgoingMessageReceived',
            ], true)) {
                if ($instance->hasMansourIntegration() && $target['line'] !== '') {
                    $body['_mansour_line'] = $target['line'];
                }
                try {
                    $result = $this->handleWebhook($instance, Request::create('/', 'POST', $body));
                    if (($result['ok'] ?? false) === true && empty($result['ignored'])) {
                        $processed++;
                    }
                    if (($result['ok'] ?? false) !== true) {
                        $delete = false;
                    }
                } catch (Throwable $e) {
                    $delete = false;
                    Log::warning('Green API queued notification processing failed', [
                        'instance_id' => $instance->id,
                        'line' => $target['line'],
                        'receipt_id' => $receiptId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $delete) {
                break;
            }

            try {
                Http::connectTimeout(4)
                    ->timeout(12)
                    ->acceptJson()
                    ->delete($target['base'].'/deleteNotification/'.$target['token'].'/'.$receiptId);
            } catch (Throwable $e) {
                Log::warning('Green API deleteNotification failed', [
                    'instance_id' => $instance->id,
                    'line' => $target['line'],
                    'receipt_id' => $receiptId,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return ['drained' => $drained, 'processed' => $processed];
    }

    /**
     * Incoming Green API messages from triggered chats that the CRM never stored.
     *
     * @return list<array{key: string, chat_id: string, line: string, name: string, phone: string, preview: string, count: int, items: list<array<string, mixed>>}>
     */
    public function missingIncoming(ChatbotInstance $instance, int $minutes = 720): array
    {
        if ($this->whapiClient->usesWhapi() || $instance->outboundHeld()) {
            return [];
        }

        $minutes = max(5, min(1440, $minutes));
        $groups = [];

        foreach ($this->notificationTargets($instance) as $target) {
            try {
                $response = Http::connectTimeout(4)
                    ->timeout(20)
                    ->acceptJson()
                    ->get($target['base'].'/lastIncomingMessages/'.$target['token'], [
                        'minutes' => $minutes,
                    ]);
            } catch (Throwable $e) {
                Log::warning('Green API lastIncomingMessages failed', [
                    'instance_id' => $instance->id,
                    'line' => $target['line'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $rows = $response->successful() ? $response->json() : null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }
            if (count($rows) > 80) {
                $rows = array_slice($rows, 0, 80);
            }

            foreach ($rows as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $chatId = trim((string) ($item['chatId'] ?? ''));
                $messageId = trim((string) ($item['idMessage'] ?? ''));
                if ($chatId === '' || $messageId === '' || str_contains(strtolower($chatId), '@g.us')) {
                    continue;
                }
                if (strtolower((string) ($item['type'] ?? 'incoming')) === 'outgoing') {
                    continue;
                }
                if ($this->messageExistsInDatabase($instance, $messageId)) {
                    continue;
                }
                if (! $this->mansourTriggeredSender($instance, $chatId, $target['line'])) {
                    continue;
                }

                $key = substr(sha1($target['line'].'|'.$chatId), 0, 16);
                if (! isset($groups[$key])) {
                    $phone = $this->phoneFromChatId($chatId) ?? '';
                    $groups[$key] = [
                        'key' => $key,
                        'chat_id' => $chatId,
                        'line' => $target['line'],
                        'name' => trim((string) ($item['senderName'] ?? '')) ?: ($phone !== '' ? $phone : $chatId),
                        'phone' => $phone,
                        'preview' => '',
                        'count' => 0,
                        'items' => [],
                    ];
                }
                $text = trim((string) ($item['textMessage'] ?? ''));
                if ($text === '') {
                    $text = trim((string) ($item['caption'] ?? ''));
                }
                if ($text === '') {
                    $text = '['.(string) ($item['typeMessage'] ?? 'message').']';
                }
                $groups[$key]['items'][] = $item;
                $groups[$key]['count']++;
                $groups[$key]['preview'] = mb_substr($text, 0, 160);
                $sender = trim((string) ($item['senderName'] ?? ''));
                if ($sender !== '') {
                    $groups[$key]['name'] = $sender;
                }
            }
        }

        $chats = array_values($groups);
        usort($chats, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($chats, 0, 40);
    }

    /**
     * Store one missed chat. Continue lets the bot answer. Skip only saves the lines.
     *
     * @param  array<string, mixed>  $chat
     * @return array{stored: int}
     */
    public function applyMissingChat(ChatbotInstance $instance, array $chat, string $action): array
    {
        $items = is_array($chat['items'] ?? null) ? $chat['items'] : [];
        usort($items, static function (array $a, array $b): int {
            return ((int) ($a['timestamp'] ?? 0)) <=> ((int) ($b['timestamp'] ?? 0));
        });
        $line = (string) ($chat['line'] ?? '');
        $stored = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $payload = $this->journalAsWebhook($item, $line);
            if ($action === 'skip') {
                $payload['_sync_hold'] = 1;
            }
            try {
                $result = $this->handleWebhook($instance, Request::create('/', 'POST', $payload));
            } catch (Throwable $e) {
                Log::warning('Green API sync apply failed', [
                    'instance_id' => $instance->id,
                    'chat_id' => $chat['chat_id'] ?? '',
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (($result['ok'] ?? false) === true && empty($result['ignored'])) {
                $stored++;
            }
        }

        return ['stored' => $stored];
    }

    /**
     * Copy recent Green API incoming messages that never reached the webhook.
     *
     * @return array{imported: int, seen: int, lines: int}
     */
    public function importRecentIncoming(ChatbotInstance $instance, int $minutes = 240): array
    {
        if ($this->whapiClient->usesWhapi() || $instance->outboundHeld()) {
            return ['imported' => 0, 'seen' => 0, 'lines' => 0];
        }

        $minutes = max(5, min(1440, $minutes));
        $targets = $this->notificationTargets($instance);
        $seen = 0;
        $imported = 0;
        $reasons = [];

        foreach ($targets as $target) {
            try {
                $response = Http::connectTimeout(4)
                    ->timeout(20)
                    ->acceptJson()
                    ->get($target['base'].'/lastIncomingMessages/'.$target['token'], [
                        'minutes' => $minutes,
                    ]);
            } catch (Throwable $e) {
                Log::warning('Green API lastIncomingMessages failed', [
                    'instance_id' => $instance->id,
                    'line' => $target['line'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $rows = $response->successful() ? $response->json() : null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }

            if (count($rows) > 60) {
                $rows = array_slice($rows, 0, 60);
            }
            $rows = array_reverse($rows);

            foreach ($rows as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $chatId = trim((string) ($item['chatId'] ?? ''));
                if ($chatId === '' || str_contains(strtolower($chatId), '@g.us')) {
                    continue;
                }
                if (strtolower((string) ($item['type'] ?? 'incoming')) === 'outgoing') {
                    continue;
                }
                $seen++;
                try {
                    $result = $this->handleWebhook(
                        $instance,
                        Request::create('/', 'POST', $this->journalAsWebhook($item, $target['line'])),
                    );
                    if (($result['ok'] ?? false) === true && empty($result['ignored'])) {
                        $imported++;
                    } elseif (! empty($result['ignored'])) {
                        $reason = (string) ($result['reason'] ?? 'ignored');
                        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    } else {
                        $reasons['failed'] = ($reasons['failed'] ?? 0) + 1;
                    }
                } catch (Throwable $e) {
                    Log::warning('Green API journal message was not stored', [
                        'instance_id' => $instance->id,
                        'line' => $target['line'],
                        'chat_id' => $chatId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'imported' => $imported,
            'seen' => $seen,
            'lines' => count($targets),
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function journalAsWebhook(array $item, string $line): array
    {
        $text = is_string($item['textMessage'] ?? null) ? $item['textMessage'] : '';
        $caption = is_string($item['caption'] ?? null) ? $item['caption'] : '';
        $download = is_string($item['downloadUrl'] ?? null) ? $item['downloadUrl'] : '';

        return [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => $item['timestamp'] ?? null,
            'idMessage' => (string) ($item['idMessage'] ?? ''),
            'senderName' => (string) ($item['senderName'] ?? ''),
            'senderData' => [
                'chatId' => (string) ($item['chatId'] ?? ''),
                'senderName' => (string) ($item['senderName'] ?? ''),
            ],
            'messageData' => [
                'typeMessage' => (string) ($item['typeMessage'] ?? 'textMessage'),
                'idMessage' => (string) ($item['idMessage'] ?? ''),
                'textMessageData' => ['textMessage' => $text],
                'caption' => $caption,
                'downloadUrl' => $download,
            ],
            '_mansour_line' => $line,
        ];
    }

    /**
     * Reply again where a triggered chat has customer lines and no sent answer after them.
     *
     * @return array{resumed: int}
     */
    public function resumeUnansweredApps(ChatbotInstance $instance, int $limit = 40): array
    {
        if (! $instance->isAppsBot() || $instance->outboundHeld() || ! $instance->isBotGloballyActive()) {
            return ['resumed' => 0];
        }

        $limit = max(1, min(40, $limit));
        $burst = app(MansourConversationBurstService::class);
        $rows = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('channel', ChatbotConversation::CHANNEL_WHATSAPP)
            ->where('last_message_at', '>=', now()->subHours(8))
            ->orderByDesc('last_message_at')
            ->limit(120)
            ->get();

        $resumed = 0;
        foreach ($rows as $conversation) {
            if ($resumed >= $limit || ! $conversation->allowsAutomaticReply()) {
                continue;
            }
            $chatId = trim((string) $conversation->external_chat_id);
            if ($chatId === '' || str_contains(strtolower($chatId), '@g.us')) {
                continue;
            }
            if ($burst->collectUnprocessedBurst($conversation) === []) {
                continue;
            }
            $activatedAt = $instance->bot_activated_at;
            if ($activatedAt !== null) {
                $contact = $instance->mansourContacts()->where('chat_id', $chatId)->first(['triggered_at']);
                if ($contact === null || $contact->triggered_at === null || $contact->triggered_at->lt($activatedAt)) {
                    continue;
                }
            }

            $version = $burst->currentVersion($conversation);
            if ($version <= 0) {
                $version = (int) $burst->registerInboundMessage($conversation)['version'];
            }
            $this->scheduleMansourProcess(
                (int) $instance->id,
                (int) $conversation->id,
                $version,
                $chatId,
                1,
            );
            $resumed++;
        }

        return ['resumed' => $resumed];
    }

    /**
     * A held line must not reach WhatsApp, even if the bot switch is turned back on.
     */
    private function sendUrlHeld(string $sendUrl): bool
    {
        if (! preg_match('~/waInstance(\d+)/~i', $sendUrl, $matches)) {
            return false;
        }

        $needle = 'waInstance'.$matches[1];
        $instances = ChatbotInstance::query()->where('integration_type', 'mansour')->get();
        foreach ($instances as $instance) {
            if (! $instance->outboundHeld()) {
                continue;
            }
            if (str_contains((string) $instance->greenapi_url, $needle)) {
                return true;
            }
            $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
            foreach ($settings['greenapi_lines'] ?? [] as $line) {
                if (is_array($line) && str_contains((string) ($line['url'] ?? ''), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{status: int, body: mixed}
     */
    public function whapiTransportReady(?string $greenApiUrl): bool
    {
        return $this->whapiClient->transportReady($greenApiUrl);
    }

    public function sendMessage(string $sendUrl, string $chatId, string $message, ?int $typingTimeMs = null): array
    {
        if ($this->sendUrlHeld($sendUrl)) {
            return ['status' => 0, 'body' => 'outbound_hold'];
        }

        if ($this->whapiClient->usesWhapi()) {
            return $this->whapiClient->sendText($chatId, $message, $typingTimeMs);
        }

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

        try {
            $response = Http::connectTimeout(8)
                ->timeout(25)
                ->acceptJson()
                ->post($sendUrl, $payload);
        } catch (Throwable $e) {
            Log::warning('Green API sendMessage failed', [
                'status' => 0,
                'body' => $e->getMessage(),
                'chat_id' => $chatId,
            ]);

            return ['status' => 0, 'body' => 'request_failed'];
        }

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
        bool $asDocument = false,
    ): array {
        if ($this->sendUrlHeld($sendUrl)) {
            return ['status' => 0, 'body' => 'outbound_hold'];
        }

        if ($this->whapiClient->usesWhapi()) {
            return $this->whapiClient->sendFileByUrl($chatId, $fileUrl, $fileName, $caption, $asDocument);
        }

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

    /**
     * Send a local image. Green API uploads the bytes, so the file does not need a public URL.
     *
     * @return array{status: int, body: mixed}
     */
    public function sendLocalFile(
        string $sendUrl,
        string $chatId,
        string $absolutePath,
        string $fileName,
        ?string $caption = null,
    ): array {
        if ($this->sendUrlHeld($sendUrl)) {
            return ['status' => 0, 'body' => 'outbound_hold'];
        }

        if (! is_file($absolutePath)) {
            return ['status' => 0, 'body' => 'missing_file'];
        }

        $bytes = file_get_contents($absolutePath);
        if (! is_string($bytes) || $bytes === '') {
            return ['status' => 0, 'body' => 'missing_file'];
        }

        $caption = trim((string) $caption);
        if ($this->whapiClient->usesWhapi()) {
            $media = 'data:image/jpeg;base64,'.base64_encode($bytes);

            return $this->whapiClient->sendFileByUrl($chatId, $media, $fileName, $caption !== '' ? $caption : null, false);
        }

        $url = $this->uploadUrlFromSendUrl($sendUrl);
        if ($url === null) {
            Log::warning('Green API sendFileByUpload skipped — send URL has no sendMessage path', [
                'chat_id' => $chatId,
            ]);

            return ['status' => 0, 'body' => null];
        }

        $fields = [
            'chatId' => $chatId,
            'fileName' => $fileName,
        ];
        if ($caption !== '') {
            $fields['caption'] = $caption;
        }

        $response = Http::timeout(90)
            ->attach('file', $bytes, $fileName, ['Content-Type' => 'image/jpeg'])
            ->post($url, $fields);

        if (! $response->successful()) {
            Log::warning('Green API sendFileByUpload failed', [
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

    public function uploadUrlFromSendUrl(string $sendUrl): ?string
    {
        $sendUrl = trim($sendUrl);
        if ($sendUrl === '' || ! str_contains($sendUrl, '/sendMessage/')) {
            return null;
        }

        return str_ireplace('/sendMessage/', '/sendFileByUpload/', $sendUrl);
    }

    /**
     * Best-effort WhatsApp "typing…" presence (Green API sendTyping).
     *
     * @return array{status: int, body: mixed}|null
     */
    public function sendTyping(string $sendUrl, string $chatId, ?int $typingTimeMs = null): ?array
    {
        if ($this->sendUrlHeld($sendUrl)) {
            return null;
        }

        if ($this->whapiClient->usesWhapi()) {
            return $this->whapiClient->sendTyping($chatId, $typingTimeMs);
        }

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

        if ($instance?->hasMansourIntegration()) {
            return $this->normalizeTypingTimeMs(MansourHumanDelay::millisecondsForText($text, $instance->isDimaBot()));
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
    public function isSenderAllowedToReceiveReply(ChatbotInstance $instance, string $chatId, ?string $lineId = null): bool
    {
        if ($instance->hasMansourIntegration()) {
            return $this->mansourTriggeredSender($instance, $chatId, $lineId);
        }

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
     * منصور replies only to numbers that were included in a sent trigger.
     */
    public function mansourTriggeredSender(ChatbotInstance $instance, string $chatId, ?string $lineId = null): bool
    {
        if (str_contains(strtolower($chatId), '@g.us')) {
            return false;
        }

        $lines = app(MansourLines::class);
        $lineId = $lines->inboundLineId($instance, $lineId);
        if ($lineId === null) {
            return false;
        }

        $phone = $this->phoneFromChatId($chatId);
        if ($phone === null) {
            return false;
        }

        $digits = $this->normalizePhoneDigits($phone);
        if (strlen($digits) < 8) {
            return false;
        }

        $contact = $instance->mansourContacts()
            ->whereNotNull('triggered_at')
            ->where(function ($query) use ($digits, $chatId): void {
                $query->where('phone_normalized', $digits)
                    ->orWhere('chat_id', $chatId);
            })
            ->first();
        if ($contact === null) {
            return false;
        }
        $contact->loadMissing('conversation');

        return $contact->sentOnLine($lineId, $lines->primaryId($instance));
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
