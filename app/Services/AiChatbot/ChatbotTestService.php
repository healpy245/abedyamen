<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotConversationInstruction;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Isolated chatbot testing sandbox — never sends WhatsApp.
 * Malan lookups run for real so the sandbox can verify flows; payment charge/reactivation stay blocked when dry_run is used.
 */
class ChatbotTestService
{
    public function __construct(
        protected AiChatbotService $chatbotService,
        protected ChatbotImageUploadService $imageUploadService,
    ) {}

    /**
     * @param  array{
     *     message?:string|null,
     *     channel?:string,
     *     phone?:string|null,
     *     customer_name?:string|null,
     *     identity_number?:string|null,
     *     conversation_id?:int|null,
     *     reset?:bool,
     *     voice_mode?:bool
     * }  $input
     * @return array<string, mixed>
     */
    public function run(User $user, ChatbotInstance $instance, array $input, ?\App\Models\Malan\MalanCampaign $campaign = null): array
    {
        $channel = (string) ($input['channel'] ?? ChatbotConversation::CHANNEL_TEST);
        if (! in_array($channel, [
            ChatbotConversation::CHANNEL_WEB,
            ChatbotConversation::CHANNEL_WHATSAPP,
            ChatbotConversation::CHANNEL_VOICE,
            ChatbotConversation::CHANNEL_TEST,
        ], true)) {
            $channel = ChatbotConversation::CHANNEL_TEST;
        }

        $voiceMode = (bool) ($input['voice_mode'] ?? false);
        $conversation = $this->resolveTestConversation($user, $instance, $input, $channel, $campaign);
        $conversationId = (int) $conversation->id;

        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            if (! empty($input['reset'])) {
                return [
                    'ok' => true,
                    'simulation' => true,
                    'conversation_id' => $conversationId,
                    'assistant_response' => null,
                    'user_message' => null,
                    'duration_ms' => null,
                    'messages' => [],
                    'tool_calls' => [],
                    'campaign_id' => $campaign?->id,
                ];
            }

            throw new RuntimeException('Test message is required.');
        }

        $demo = app(KamanPosDemoVideoService::class);
        if ($instance->hasKamanWhatsappIntegration()
            && $demo->customerAskedForVisual($message)
            && $demo->isReady($instance)
        ) {
            return $this->recordPosDemoTestReply($instance, $conversation, $message, $demo, $campaign);
        }

        $visa = app(KamanVisaDeviceFact::class);
        if ($instance->hasKamanWhatsappIntegration() && $visa->customerAsked($message)) {
            return $this->recordVisaDeviceTestReply($instance, $conversation, $message, $campaign);
        }

        $origin = app(KamanOriginFact::class);
        if ($instance->hasKamanWhatsappIntegration() && $origin->customerAsked($message)) {
            return $this->recordOriginFactTestReply($instance, $conversation, $message, $campaign);
        }

        if ($instance->isDimaBot()) {
            return $this->recordDimaTestReply($instance, $conversation, $message);
        }

        if ($instance->isInviteBot()) {
            return $this->recordInviteTestReply($instance, $conversation, $message);
        }

        if ($instance->isAppsBot()) {
            return $this->recordAppsTestReply($instance, $conversation, $message);
        }

        $closer = app(KamanConversationCloser::class);
        $hasPriorAssistant = $conversation->messages()
            ->where('role', 'assistant')
            ->exists();
        $cannedReply = $instance->hasKamanWhatsappIntegration() && $hasPriorAssistant
            ? $closer->cannedReplyFor($conversation, $message)
            : null;
        if ($cannedReply !== null) {
            return $this->recordThanksClosingTestReply($instance, $conversation, $message, $campaign, $cannedReply);
        }

        try {
            $result = $this->chatbotService->sendMessage(
                $user,
                $instance,
                $message,
                $conversationId,
                [
                    'channel' => ChatbotConversation::CHANNEL_TEST,
                    'voice_mode' => $voiceMode,
                    // Allow real Malan lookups in the sandbox; WhatsApp is never sent for CHANNEL_TEST.
                    'dry_run' => false,
                    'campaign_lead_bot' => $campaign !== null,
                    'conversation_attributes' => [
                        'channel' => ChatbotConversation::CHANNEL_TEST,
                    ],
                ],
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'simulation' => true,
                'error' => $e->getMessage(),
                'conversation_id' => $conversationId,
                'duration_ms' => null,
                'assistant_response' => null,
                'user_message' => $message,
                'messages' => $this->messageHistory($instance, $conversationId),
                'tool_calls' => [],
                'campaign_id' => $campaign?->id,
            ];
        }

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => $result['conversation']->id,
            'user_message' => $message,
            'assistant_response' => $result['assistant_message']?->message,
            'duration_ms' => $result['duration_ms'] ?? null,
            'messages' => $this->messageHistory($instance, (int) $result['conversation']->id),
            'tool_calls' => $this->sanitizeToolCalls($result['tool_calls'] ?? []),
            'campaign_id' => $campaign?->id,
        ];
    }

    /**
     * @param  array{
     *     caption?:string|null,
     *     channel?:string,
     *     phone?:string|null,
     *     customer_name?:string|null,
     *     conversation_id?:int|null,
     *     reset?:bool
     * }  $input
     * @return array<string, mixed>
     */
    public function uploadImage(User $user, ChatbotInstance $instance, UploadedFile $file, array $input): array
    {
        $channel = (string) ($input['channel'] ?? ChatbotConversation::CHANNEL_TEST);
        if (! in_array($channel, [
            ChatbotConversation::CHANNEL_WEB,
            ChatbotConversation::CHANNEL_WHATSAPP,
            ChatbotConversation::CHANNEL_VOICE,
            ChatbotConversation::CHANNEL_TEST,
        ], true)) {
            $channel = ChatbotConversation::CHANNEL_TEST;
        }

        $conversation = $this->resolveTestConversation($user, $instance, $input, $channel);
        $caption = isset($input['caption']) ? trim((string) $input['caption']) : '';

        try {
            $result = $this->imageUploadService->handle(
                $user,
                $instance,
                $file,
                (int) $conversation->id,
                $caption !== '' ? $caption : null,
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'simulation' => true,
                'error' => $e->getMessage(),
                'conversation_id' => (int) $conversation->id,
                'assistant_response' => null,
                'user_message' => $caption !== '' ? $caption : null,
                'messages' => $this->messageHistory($instance, (int) $conversation->id),
                'tool_calls' => [],
            ];
        }

        $conversationId = (int) $result['conversation']->id;

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => $conversationId,
            'user_message' => $result['user_message']->message,
            'assistant_response' => $result['assistant_message']?->message,
            'duration_ms' => null,
            'messages' => $this->messageHistory($instance, $conversationId),
            'tool_calls' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveTestConversation(
        User $user,
        ChatbotInstance $instance,
        array $input,
        string $simulatedChannel,
        ?\App\Models\Malan\MalanCampaign $campaign = null,
    ): ChatbotConversation {
        $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;
        $wantsReset = ! empty($input['reset']) || $conversationId === null;

        if ($wantsReset) {
            return $this->startFreshTestConversation($user, $instance, $input, $simulatedChannel, $campaign);
        }

        $query = ChatbotConversation::query()
            ->where('id', $conversationId)
            ->where('instance_id', $instance->id)
            ->where('channel', ChatbotConversation::CHANNEL_TEST);

        if ($campaign !== null) {
            $query->where('campaign_id', $campaign->id);
        } else {
            $query->whereNull('campaign_id');
        }

        $conversation = $query->first();

        if ($conversation === null) {
            throw new RuntimeException('Test conversation not found.');
        }

        return $conversation;
    }

    /**
     * Reuse the fixed sandbox external_chat_id so "this WhatsApp number" lookups stay stable,
     * instead of inserting a second row that violates the unique index.
     *
     * @param  array<string, mixed>  $input
     */
    private function startFreshTestConversation(
        User $user,
        ChatbotInstance $instance,
        array $input,
        string $simulatedChannel,
        ?\App\Models\Malan\MalanCampaign $campaign = null,
    ): ChatbotConversation {
        $phone = trim((string) ($input['phone'] ?? '0533046830'));
        if ($phone === '') {
            $phone = '0533046830';
        }

        $baseExternal = $this->externalChatIdForPhone($phone);
        $externalChatId = $campaign !== null
            ? 'campaign:'.$campaign->id.':test:'.$baseExternal
            : $baseExternal;

        $attributes = [
            'user_id' => $user->id,
            'campaign_id' => $campaign?->id,
            'title' => ($campaign ? 'Campaign test — ' : 'Simulation — ').now()->format('Y-m-d H:i'),
            'contact_phone' => $phone,
            'contact_name' => $input['customer_name'] ?? 'Test customer',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            'metadata' => array_filter([
                'simulation' => true,
                'simulated_channel' => $simulatedChannel,
                'campaign_id' => $campaign?->id,
                'campaign_lead_bot' => $campaign !== null,
                'whatsapp_chat_id' => $baseExternal,
            ]),
        ];

        $conversation = ChatbotConversation::query()
            ->forExternalChat($instance->id, ChatbotConversation::CHANNEL_TEST, $externalChatId)
            ->first();

        if ($conversation === null) {
            try {
                return ChatbotConversation::query()->create([
                    'instance_id' => $instance->id,
                    'channel' => ChatbotConversation::CHANNEL_TEST,
                    'external_chat_id' => $externalChatId,
                    ...$attributes,
                ]);
            } catch (QueryException $e) {
                // Concurrent reset: fall through and reuse the row that won the insert race.
                if (! $this->isDuplicateExternalChatConstraint($e)) {
                    throw $e;
                }

                $conversation = ChatbotConversation::query()
                    ->forExternalChat($instance->id, ChatbotConversation::CHANNEL_TEST, $externalChatId)
                    ->first();

                if ($conversation === null) {
                    throw $e;
                }
            }
        }

        $this->wipeTestConversationHistory($conversation);
        $conversation->forceFill($attributes)->save();

        return $conversation->refresh();
    }

    private function wipeTestConversationHistory(ChatbotConversation $conversation): void
    {
        $id = (int) $conversation->id;

        ChatbotMessage::query()->where('conversation_id', $id)->delete();
        ChatbotToolExecution::query()->where('conversation_id', $id)->delete();
        ChatbotConversationInstruction::query()->where('conversation_id', $id)->delete();
        ChatbotConversationContext::query()->where('conversation_id', $id)->delete();
    }

    private function externalChatIdForPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '972'.substr($digits, 1);
        }
        if ($digits === '') {
            $digits = '972533046830';
        }

        return $digits.'@c.us';
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function recordInviteTestReply(ChatbotInstance $instance, ChatbotConversation $conversation, string $userMessage): array
    {
        $variant = InvitePrompt::variantOf($instance);
        $openingMessage = $conversation->messages()->where('role', 'assistant')->orderBy('id')->first();
        if ($openingMessage === null) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => InvitePrompt::greetingFor($conversation->contact_name, $variant),
                'delivery_status' => 'sent',
            ]);
        }

        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $caption = '';
        $justInvited = false;
        if (! InvitePrompt::delivered($conversation) && ! InvitePrompt::wantsOut($userMessage)) {
            $poster = InvitePrompt::posterAttachment($variant);
            $caption = InvitePrompt::invitationFor(
                $conversation->contact_name,
                (string) data_get($instance->integration_settings, 'opening_message'),
                $variant,
            );
            $conversation->messages()->create(array_merge([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => is_array($poster) ? 'image' : 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $caption,
                'delivery_status' => 'sent',
            ], $poster ?? []));
            InvitePrompt::markDelivered($conversation);
            $justInvited = true;
        }

        $turns = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
        $decision = InvitePrompt::nextReply($turns, $conversation->contact_name, InvitePrompt::read($conversation), $variant);
        if ($justInvited && (trim($decision['text']) === '' || InvitePrompt::invitationIsEnough($decision, $variant))) {
            InvitePrompt::remember($conversation, InvitePrompt::OPEN, 'انبعتت الدعوة', false);
            $conversation->recordAssistantActivity();

            return [
                'ok' => true,
                'simulation' => true,
                'conversation_id' => (int) $conversation->id,
                'user_message' => $userMessage,
                'assistant_response' => $caption,
                'duration_ms' => null,
                'messages' => $this->messageHistory($instance, (int) $conversation->id),
                'tool_calls' => [],
                'campaign_id' => null,
            ];
        }
        if (trim($decision['text']) === '') {
            return [
                'ok' => false,
                'simulation' => true,
                'error' => 'ما في رد من النموذج.',
                'conversation_id' => (int) $conversation->id,
            ];
        }

        InvitePrompt::remember($conversation, $decision['stance'], $decision['note'], $decision['close'], (bool) ($decision['plea'] ?? false));
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $decision['text'],
            'delivery_status' => 'sent',
        ]);
        if ($decision['close']) {
            $conversation->forceFill(['bot_mode' => ChatbotConversation::BOT_MODE_PAUSED])->save();
        }
        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => $decision['text'],
            'duration_ms' => null,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => null,
        ];
    }

    private function recordDimaTestReply(ChatbotInstance $instance, ChatbotConversation $conversation, string $userMessage): array
    {
        if (! $conversation->messages()->where('role', 'assistant')->exists()) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => DimaEngine::openingFor($conversation->contact_name),
                'delivery_status' => 'sent',
            ]);
        }

        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $turns = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
        $decision = DimaEngine::nextReply($turns, $conversation->contact_name, DimaEngine::read($conversation));
        if (trim($decision['text']) === '') {
            return [
                'ok' => false,
                'simulation' => true,
                'error' => 'ما في رد من النموذج.',
                'conversation_id' => (int) $conversation->id,
            ];
        }

        DimaEngine::remember($conversation, $decision['stance'], $decision['note'], $decision['close'], $decision['memory'] ?? [], $decision['stage'] ?? null, is_array($decision['identity'] ?? null) ? $decision['identity'] : null);
        foreach (is_array($decision['clips'] ?? null) ? $decision['clips'] : [] as $clip) {
            if (! is_array($clip) || trim((string) ($clip['path'] ?? '')) === '') {
                continue;
            }
            $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'video',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => '',
                'delivery_status' => 'sent',
                'attachment_disk' => (string) ($clip['disk'] ?? 'public'),
                'attachment_path' => (string) $clip['path'],
                'attachment_mime' => 'video/mp4',
                'metadata' => ['dima_impact_clip' => (string) ($clip['id'] ?? '')],
            ]);
        }
        if (($decision['video'] ?? false) === true) {
            $already = $conversation->messages()
                ->where('role', 'assistant')
                ->where('message_type', 'video')
                ->where('metadata->dima_no_vote_video', true)
                ->exists();
            if (! $already) {
                $conversation->messages()->create([
                    'role' => 'assistant',
                    'sender_type' => 'ai',
                    'message_type' => 'video',
                    'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                    'message' => '',
                    'delivery_status' => 'sent',
                    'attachment_disk' => DimaPrompt::NO_VOTE_VIDEO_DISK,
                    'attachment_path' => DimaPrompt::NO_VOTE_VIDEO_PATH,
                    'attachment_mime' => 'video/mp4',
                    'metadata' => ['dima_no_vote_video' => true],
                ]);
            }
        }
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $decision['text'],
            'delivery_status' => 'sent',
        ]);
        if ($decision['close']) {
            $conversation->forceFill(['bot_mode' => ChatbotConversation::BOT_MODE_PAUSED])->save();
        }
        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => $decision['text'],
            'duration_ms' => null,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => null,
        ];
    }

    private function recordAppsTestReply(ChatbotInstance $instance, ChatbotConversation $conversation, string $userMessage): array
    {
        if (! $conversation->messages()->where('role', 'assistant')->exists()) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => AppsPrompt::greetingFor($conversation->contact_name),
                'delivery_status' => 'sent',
            ]);
        }

        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $turns = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
        $memoryVersion = AppsPrompt::memoryVersion($conversation);
        $decision = AppsPrompt::nextReply(
            $turns,
            $conversation->contact_name,
            AppsPrompt::links($instance),
            AppsPrompt::memoryOf($conversation),
            $instance,
        );
        AppsPrompt::storeMemory($conversation, $decision['memory'] ?? [], $memoryVersion);
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $decision['text'],
            'delivery_status' => 'sent',
        ]);
        if ($decision['pause']) {
            $conversation->forceFill(['bot_mode' => ChatbotConversation::BOT_MODE_PAUSED])->save();
        }
        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => $decision['text'],
            'duration_ms' => null,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => null,
        ];
    }

    private function recordPosDemoTestReply(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $userMessage,
        KamanPosDemoVideoService $demo,
        ?\App\Models\Malan\MalanCampaign $campaign = null,
    ): array {
        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $stored = $demo->settings($instance);
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'video',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $demo->caption(),
            'delivery_status' => 'sent',
            'attachment_disk' => (string) ($stored['disk'] ?? KamanPosDemoVideoService::DISK),
            'attachment_path' => (string) ($stored['path'] ?? ''),
            'attachment_mime' => $demo->mime($instance),
            'metadata' => [
                'kaman_pos_demo' => true,
                'simulation' => true,
            ],
        ]);

        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => $demo->caption(),
            'duration_ms' => 0,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => $campaign?->id,
            'pos_demo_video' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordVisaDeviceTestReply(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $userMessage,
        ?\App\Models\Malan\MalanCampaign $campaign = null,
    ): array {
        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => KamanVisaDeviceFact::REPLY,
            'delivery_status' => 'sent',
            'metadata' => [
                'kaman_visa_device' => true,
                'simulation' => true,
            ],
        ]);

        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => KamanVisaDeviceFact::REPLY,
            'duration_ms' => 0,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => $campaign?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordOriginFactTestReply(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $userMessage,
        ?\App\Models\Malan\MalanCampaign $campaign = null,
    ): array {
        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => KamanOriginFact::REPLY,
            'delivery_status' => 'sent',
            'metadata' => [
                'kaman_origin' => true,
                'simulation' => true,
            ],
        ]);

        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => KamanOriginFact::REPLY,
            'duration_ms' => 0,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => $campaign?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordThanksClosingTestReply(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $userMessage,
        ?\App\Models\Malan\MalanCampaign $campaign,
        string $reply,
    ): array {
        $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessage,
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $reply,
            'delivery_status' => 'sent',
            'metadata' => [
                'kaman_conversation_close' => true,
                'simulation' => true,
            ],
        ]);

        $conversation->recordAssistantActivity();

        return [
            'ok' => true,
            'simulation' => true,
            'conversation_id' => (int) $conversation->id,
            'user_message' => $userMessage,
            'assistant_response' => $reply,
            'duration_ms' => 0,
            'messages' => $this->messageHistory($instance, (int) $conversation->id),
            'tool_calls' => [],
            'campaign_id' => $campaign?->id,
        ];
    }

    private function isDuplicateExternalChatConstraint(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'ai_chatbot_conversations_instance_channel_external_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, '23000'));
    }

    /**
     * @return list<array{id:int,role:string,message:string,created_at:?string,attachment_url:?string,is_image:bool,is_pdf:bool}>
     */
    private function messageHistory(ChatbotInstance $instance, int $conversationId): array
    {
        return ChatbotMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->get()
            ->map(function (ChatbotMessage $m) use ($instance): array {
                $attachmentUrl = null;
                if ($m->hasAttachment()) {
                    $attachmentUrl = route('ai-chatbot.instances.messages.attachment', [
                        'instance' => $instance,
                        'message' => $m,
                    ]);
                }

                return [
                    'id' => (int) $m->id,
                    'role' => (string) $m->role,
                    'message' => (string) $m->message,
                    'created_at' => optional($m->created_at)?->toIso8601String(),
                    'attachment_url' => $attachmentUrl,
                    'is_image' => $m->isImageAttachment(),
                    'is_pdf' => $m->isPdfAttachment(),
                    'is_video' => $m->isVideoAttachment(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return list<array<string, mixed>>
     */
    private function sanitizeToolCalls(array $calls): array
    {
        return array_map(static function (array $call): array {
            $result = is_array($call['result'] ?? null) ? $call['result'] : [];
            unset($result['raw'], $result['debug']);

            return [
                'name' => $call['name'] ?? null,
                'arguments' => $call['arguments'] ?? [],
                'result' => $result,
                'dry_run' => true,
            ];
        }, $calls);
    }
}
