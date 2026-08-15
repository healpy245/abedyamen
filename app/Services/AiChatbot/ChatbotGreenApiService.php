<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
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
        $shouldAutoReply = $senderAllowed
            && $instance->isBotGloballyActive()
            && $conversation->allowsAutomaticReply();

        if (! $senderAllowed) {
            Log::info('Green API auto-reply skipped: sender not in allowlist', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'allowed' => $instance->allowedReplyPhones(),
            ]);
        }

        try {
            if (! $shouldAutoReply) {
                $result = $this->storeWithoutAiReply($instance, $incoming, $conversation);
            } elseif ($incoming->isImage() || $incoming->isPdf()) {
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
            ];
        }

        $reply = (string) $assistantMessage->message;
        $sendResult = $this->sendMessage($sendUrl, $incoming->chatId, $reply);

        if ($assistantMessage instanceof ChatbotMessage) {
            $assistantMessage->forceFill([
                'delivery_status' => $sendResult['status'] >= 200 && $sendResult['status'] < 300 ? 'sent' : 'failed',
            ])->save();
        }

        return [
            'ok' => true,
            'chatId' => $incoming->chatId,
            'incoming' => $incoming->customerFacingText() ?? ('['.$incoming->type.']'),
            'reply' => $reply,
            'green_api_status' => $sendResult['status'],
        ];
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

        if ($incoming->isImage() || $incoming->isPdf() || $incoming->isAudio()) {
            // Store media only — never run AI/payment-proof from the no-reply path.
            if ($incoming->downloadUrl) {
                try {
                    $stored = $this->mediaDownloader->downloadToPrivateStorage(
                        $incoming->downloadUrl,
                        $incoming->mimeType,
                    );

                    $messageType = match (true) {
                        $incoming->isAudio() => 'audio',
                        $incoming->isPdf() => 'pdf',
                        default => 'image',
                    };

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
                    $incoming->isImage() => 'image',
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

        $userFacing = $incoming->customerFacingText() ?? '[أرسل الزبون صورة/ملف عبر WhatsApp]';

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
        ];

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

        // Otherwise let the chatbot analyze/transcribe the image via OpenAI vision.
        return $this->chatbotService->sendMessage(
            $user,
            $instance,
            $userFacing,
            $conversation->id,
            $attachmentOptions,
        );
    }

    /**
     * Download and store WhatsApp voice notes / audio, then let the chatbot reply to the text placeholder.
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

        $userFacing = $incoming->customerFacingText() ?? '[رسالة صوتية عبر WhatsApp]';
        $options = [
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_message_id' => $incoming->messageId,
            'message_type' => 'audio',
        ];

        if ($incoming->downloadUrl === null || $incoming->downloadUrl === '') {
            return $this->chatbotService->sendMessage(
                $user,
                $instance,
                $userFacing,
                $conversationId,
                $options,
            );
        }

        try {
            $stored = $this->mediaDownloader->downloadToPrivateStorage(
                $incoming->downloadUrl,
                $incoming->mimeType ?: 'audio/ogg',
            );
            $options['attachment_disk'] = $stored['disk'] ?? config('malan.media.disk', 'local');
            $options['attachment_path'] = $stored['path'];
            $options['attachment_mime'] = $stored['mime_type'];
        } catch (Throwable $e) {
            Log::warning('Green API audio download failed; storing text placeholder only', [
                'instance_id' => $instance->id,
                'chat_id' => $incoming->chatId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->chatbotService->sendMessage(
            $user,
            $instance,
            $userFacing,
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
            'messageData.imageMessageData.downloadUrl',
            'messageData.audioMessageData.downloadUrl',
            'messageData.pttMessageData.downloadUrl',
            'messageData.downloadUrl',
            'downloadUrl',
            'messageData.fileMessageData.urlFile',
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

        $senderName = null;
        foreach (['senderData.senderName', 'senderData.chatName', 'senderName'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                $senderName = trim($value);
                break;
            }
        }

        if ($downloadUrl !== null && ($type === 'textmessage' || $type === '')) {
            $guessMime = strtolower((string) $mimeType);
            $type = match (true) {
                str_starts_with($guessMime, 'audio/') || $guessMime === 'application/ogg' => 'audioMessage',
                $guessMime === 'application/pdf' => 'documentMessage',
                default => 'imageMessage',
            };
        }

        if ($text === null && $caption === null && $downloadUrl === null) {
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
            raw: $request->all(),
        );
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

        if (! preg_match('#^(https://[^/\s]+)/waInstance(\d+)/sendMessage/([^/\s?#]+)#i', $url, $matches)) {
            return null;
        }

        return [
            'api_url' => $matches[1],
            'id_instance' => $matches[2],
            'api_token' => $matches[3],
        ];
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

            if (is_array($body) && ($body['typeWebhook'] ?? '') === 'incomingMessageReceived') {
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
    public function sendMessage(string $sendUrl, string $chatId, string $message): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->post($sendUrl, [
                'chatId' => $chatId,
                'message' => $message,
            ]);

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

    private function isIncomingMessageWebhook(Request $request): bool
    {
        $typeWebhook = strtolower(trim((string) $request->input('typeWebhook', '')));

        if ($typeWebhook !== '' && $typeWebhook !== 'incomingmessagereceived') {
            return false;
        }

        $fromMe = $request->input('messageData.fromMe');
        if (is_bool($fromMe) && $fromMe) {
            return false;
        }

        return true;
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
