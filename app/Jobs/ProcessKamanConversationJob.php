<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\KamanConversationBurstService;
use App\Services\AiChatbot\KamanConversationCloser;
use App\Services\AiChatbot\KamanHumanDelay;
use App\Services\AiChatbot\KamanLeadScorer;
use App\Services\AiChatbot\KamanPosDemoVideoService;
use App\Services\AiChatbot\KamanOriginFact;
use App\Services\AiChatbot\KamanVisaDeviceFact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After the Kaman listening window: one AI reply for the full customer burst.
 * If the customer sends more lines before send, this version is dropped.
 */
class ProcessKamanConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    private const LOCK_WAIT_SECONDS = 45;

    public function __construct(
        public int $instanceId,
        public int $conversationId,
        public int $expectedVersion,
        public string $chatId,
    ) {}

    public function handle(
        AiChatbotService $chatbotService,
        ChatbotGreenApiService $greenApi,
        KamanConversationBurstService $burstService,
    ): void {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        $lock = Cache::lock('kaman-burst-process-'.$this->conversationId, 120);
        $acquired = $this->waitForLock($lock, $burstService);
        if (! $acquired) {
            $this->requeueIfStillLatest($burstService);

            return;
        }

        $scheduled = [];
        try {
            $scheduled = $this->generateAndPrepare($chatbotService, $burstService);
        } finally {
            optional($lock)->release();
        }

        if ($scheduled === []) {
            return;
        }

        $this->deliverWithTypingDelays($greenApi, $burstService, $scheduled);
    }

    private function waitForLock(mixed $lock, KamanConversationBurstService $burstService): bool
    {
        $deadline = microtime(true) + (app()->runningUnitTests() ? 1 : self::LOCK_WAIT_SECONDS);

        while (microtime(true) < $deadline) {
            $conversation = ChatbotConversation::query()->find($this->conversationId);
            if ($conversation === null) {
                return false;
            }
            if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
                return false;
            }
            if ($lock->get()) {
                return true;
            }
            usleep(app()->runningUnitTests() ? 50_000 : 300_000);
        }

        return false;
    }

    private function requeueIfStillLatest(KamanConversationBurstService $burstService): void
    {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null || $burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        self::dispatch(
            $this->instanceId,
            $this->conversationId,
            $this->expectedVersion,
            $this->chatId,
        )->delay(now()->addSeconds(2));
    }

    /**
     * @return list<array{message_id:int,delay_seconds:float,send_file?:bool}>
     */
    private function generateAndPrepare(
        AiChatbotService $chatbotService,
        KamanConversationBurstService $burstService,
    ): array {
        $instance = ChatbotInstance::query()->find($this->instanceId);
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($instance === null || $conversation === null) {
            return [];
        }

        if (! $instance->isBotGloballyActive() || ! $conversation->allowsAutomaticReply()) {
            return [];
        }

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return [];
        }

        $user = $instance->user;
        if ($user === null) {
            return [];
        }

        $burst = $burstService->collectUnprocessedBurst($conversation);
        if ($burst === []) {
            $burstService->closeBurst($conversation, $this->expectedVersion);

            return [];
        }

        $burstService->setResponseState($conversation, KamanConversationBurstService::STATE_GENERATING);

        $burstText = implode("\n", array_map(
            static fn (ChatbotMessage $message): string => trim((string) $message->message),
            $burst,
        ));
        $demo = app(KamanPosDemoVideoService::class);
        if ($demo->customerAskedForVisual($burstText) && $demo->isReady($instance)) {
            $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
            $videoMessage = $demo->createOutboundMessage(
                $conversation,
                $instance,
                $triggerUserMessageId,
                $this->expectedVersion,
                $this->chatId,
            );

            return [[
                'message_id' => (int) $videoMessage->id,
                'delay_seconds' => KamanHumanDelay::secondsForText($demo->caption()),
                'send_file' => true,
            ]];
        }

        $visa = app(KamanVisaDeviceFact::class);
        if ($visa->customerAsked($burstText)) {
            $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => KamanVisaDeviceFact::REPLY,
                'delivery_status' => 'pending',
                'metadata' => [
                    'kaman_visa_device' => true,
                ],
            ]);

            return $this->prepareOutboundBubbles(
                $conversation,
                $assistant,
                $triggerUserMessageId,
            );
        }

        $origin = app(KamanOriginFact::class);
        if ($origin->customerAsked($burstText)) {
            $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => KamanOriginFact::REPLY,
                'delivery_status' => 'pending',
                'metadata' => [
                    'kaman_origin' => true,
                ],
            ]);

            return $this->prepareOutboundBubbles(
                $conversation,
                $assistant,
                $triggerUserMessageId,
            );
        }

        $closer = app(KamanConversationCloser::class);
        $hasPriorAssistant = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('delivery_status', 'sent')
            ->exists();
        if ($hasPriorAssistant && $closer->isThanksClosing($burstText)) {
            $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $closer->closingReplyFor($burstText),
                'delivery_status' => 'pending',
                'metadata' => [
                    'kaman_conversation_close' => true,
                ],
            ]);

            return $this->prepareOutboundBubbles(
                $conversation,
                $assistant,
                $triggerUserMessageId,
            );
        }

        $ephemeral = $burstService->buildBurstEphemeralPrompt($burst);

        try {
            $result = $chatbotService->generateAssistantReplyForConversation(
                $conversation->fresh() ?? $conversation,
                $instance,
                ChatbotConversation::CHANNEL_WHATSAPP,
                false,
                $ephemeral,
            );
        } catch (Throwable $e) {
            Log::error('Kaman burst AI failed', [
                'instance_id' => $this->instanceId,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
            $burstService->setResponseState($conversation, KamanConversationBurstService::STATE_WAITING_FOR_CUSTOMER);

            return [];
        }

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            $assistant = $result['assistant_message'] ?? null;
            if ($assistant instanceof ChatbotMessage) {
                $meta = is_array($assistant->metadata) ? $assistant->metadata : [];
                $assistant->forceFill([
                    'delivery_status' => 'failed',
                    'metadata' => array_merge($meta, [
                        'kaman_delivery' => KamanConversationBurstService::STATE_CANCELLED,
                        'kaman_response_state' => KamanConversationBurstService::STATE_STALE,
                        'kaman_cancel_reason' => 'version_changed_after_generate',
                    ]),
                ])->save();
            }

            return [];
        }

        $assistantMessage = $result['assistant_message'] ?? null;
        if (! $assistantMessage instanceof ChatbotMessage) {
            return [];
        }

        $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
        $scheduled = $this->prepareOutboundBubbles(
            $conversation,
            $assistantMessage,
            $triggerUserMessageId,
        );

        if ($scheduled === []) {
            return [];
        }

        return $scheduled;
    }

    /**
     * @return list<array{message_id:int,delay_seconds:float,send_file?:bool}>
     */
    private function prepareOutboundBubbles(
        ChatbotConversation $conversation,
        ChatbotMessage $firstMessage,
        int $triggerUserMessageId,
    ): array {
        $fullReply = (string) $firstMessage->message;
        $bubbles = KamanHumanDelay::splitBubbles($fullReply);
        if ($bubbles === []) {
            $bubbles = [trim($fullReply)];
        }

        $scheduled = [];
        $offsetSeconds = 0.0;

        foreach ($bubbles as $index => $bubble) {
            if ($index > 0) {
                $offsetSeconds += KamanHumanDelay::secondsBetweenBubbles();
            }

            $typingSeconds = KamanHumanDelay::secondsForText($bubble);
            $delaySeconds = $offsetSeconds + $typingSeconds;
            $offsetSeconds = $delaySeconds;

            $baseMeta = [
                'kaman_delivery' => 'queued',
                'kaman_response_state' => KamanConversationBurstService::STATE_PENDING_SEND,
                'kaman_generated_for_version' => $this->expectedVersion,
                'trigger_user_message_id' => $triggerUserMessageId,
                'greenapi_chat_id' => $this->chatId,
            ];

            if ($index === 0) {
                $meta = is_array($firstMessage->metadata) ? $firstMessage->metadata : [];
                $firstMessage->forceFill([
                    'message' => $bubble,
                    'delivery_status' => 'pending',
                    'metadata' => array_merge($meta, $baseMeta),
                ])->save();
                $message = $firstMessage;
            } else {
                $message = $conversation->messages()->create([
                    'role' => 'assistant',
                    'sender_type' => 'ai',
                    'message_type' => 'text',
                    'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                    'message' => $bubble,
                    'delivery_status' => 'pending',
                    'metadata' => array_merge($baseMeta, [
                        'kaman_bubble' => $index + 1,
                        'kaman_followup' => true,
                    ]),
                ]);
            }

            $scheduled[] = [
                'message_id' => (int) $message->id,
                'delay_seconds' => $delaySeconds,
            ];
        }

        return $scheduled;
    }

    /**
     * @param  list<array{message_id:int,delay_seconds:float,send_file?:bool}>  $scheduled
     */
    private function deliverWithTypingDelays(
        ChatbotGreenApiService $greenApi,
        KamanConversationBurstService $burstService,
        array $scheduled,
    ): void {
        $instance = ChatbotInstance::query()->find($this->instanceId);
        $sendUrl = trim((string) ($instance?->greenapi_url ?? ''));
        $maxDelay = 0.0;
        foreach ($scheduled as $row) {
            $maxDelay = max($maxDelay, (float) ($row['delay_seconds'] ?? 0));
        }
        @set_time_limit(max(60, (int) ceil($maxDelay) + 45));

        $started = microtime(true);
        $sentAny = false;

        foreach ($scheduled as $row) {
            $messageId = (int) ($row['message_id'] ?? 0);
            $delaySeconds = (float) ($row['delay_seconds'] ?? 0);
            if ($messageId <= 0) {
                continue;
            }

            $conversation = ChatbotConversation::query()->find($this->conversationId);
            if ($conversation === null
                || $burstService->currentVersion($conversation) !== $this->expectedVersion
                || ! $conversation->allowsAutomaticReply()) {
                $this->markStale($messageId, 'bot_stopped_before_send');

                continue;
            }

            $remaining = $delaySeconds - (microtime(true) - $started);
            if (! app()->runningUnitTests() && $remaining > 0.2 && $sendUrl !== '') {
                try {
                    $greenApi->sustainTypingPresence($sendUrl, $this->chatId, $remaining);
                } catch (Throwable) {
                    $still = $delaySeconds - (microtime(true) - $started);
                    if ($still > 0 && ! app()->runningUnitTests()) {
                        usleep((int) round($still * 1_000_000));
                    }
                }
            } elseif ($remaining > 0 && ! app()->runningUnitTests()) {
                usleep((int) round($remaining * 1_000_000));
            }

            $conversation = ChatbotConversation::query()->find($this->conversationId);
            if ($conversation === null
                || $burstService->currentVersion($conversation) !== $this->expectedVersion
                || ! $conversation->allowsAutomaticReply()) {
                $this->markStale($messageId, 'bot_stopped_before_send');

                continue;
            }

            $message = ChatbotMessage::query()->find($messageId);
            if ($message === null || in_array((string) $message->delivery_status, ['sent', 'failed'], true)) {
                continue;
            }

            if ($sendUrl === '') {
                $this->markStale($messageId, 'missing_greenapi_url');

                continue;
            }

            try {
                $meta = is_array($message->metadata) ? $message->metadata : [];
                $sendFile = (bool) ($row['send_file'] ?? false) || (($meta['kaman_pos_demo'] ?? false) === true);
                if ($sendFile) {
                    $demo = app(KamanPosDemoVideoService::class);
                    if ($instance === null) {
                        $this->markStale($messageId, 'missing_instance');

                        continue;
                    }
                    $fileUrl = $demo->publicUrl($instance);
                    $fileName = (string) ($meta['pos_demo_file_name'] ?? $demo->fileName($instance));
                    if ($fileUrl === null || $fileUrl === '') {
                        $this->markStale($messageId, 'pos_demo_url_missing');

                        continue;
                    }
                    $sendResult = $greenApi->sendFileByUrl(
                        $sendUrl,
                        $this->chatId,
                        $fileUrl,
                        $fileName,
                        (string) $message->message,
                    );
                } else {
                    $sendResult = $greenApi->sendMessage($sendUrl, $this->chatId, (string) $message->message, 1000);
                }
            } catch (Throwable $e) {
                Log::warning('Kaman burst send failed', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
                $this->markStale($messageId, 'send_failed');

                continue;
            }

            $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
            $body = $sendResult['body'] ?? null;
            $idMessage = is_array($body) ? ($body['idMessage'] ?? null) : null;
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $message->forceFill([
                'delivery_status' => $ok ? 'sent' : 'failed',
                'metadata' => array_merge($meta, [
                    'kaman_delivery' => $ok ? KamanConversationBurstService::STATE_SENT : 'failed',
                    'kaman_response_state' => $ok ? KamanConversationBurstService::STATE_SENT : 'failed',
                    'greenapi_chat_id' => $this->chatId,
                    'greenapi_id_message' => $idMessage,
                    'greenapi_status' => $sendResult['status'] ?? null,
                ]),
            ])->save();

            if ($ok) {
                $sentAny = true;
            }
        }

        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        if ($sentAny && $burstService->currentVersion($conversation) === $this->expectedVersion) {
            $burst = $burstService->collectUnprocessedBurst($conversation);
            $burstService->markBurstProcessed($burst, $this->expectedVersion);
            $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);
            $conversation->recordAssistantActivity();
            app(KamanLeadScorer::class)->refresh($conversation->fresh() ?? $conversation);
        }
    }

    private function markStale(int $messageId, string $reason): void
    {
        $message = ChatbotMessage::query()->find($messageId);
        if ($message === null) {
            return;
        }
        $meta = is_array($message->metadata) ? $message->metadata : [];
        if (($meta['kaman_delivery'] ?? null) === KamanConversationBurstService::STATE_SENT) {
            return;
        }

        $message->forceFill([
            'delivery_status' => 'failed',
            'metadata' => array_merge($meta, [
                'kaman_delivery' => KamanConversationBurstService::STATE_CANCELLED,
                'kaman_response_state' => KamanConversationBurstService::STATE_STALE,
                'kaman_cancel_reason' => $reason,
            ]),
        ])->save();
    }
}
