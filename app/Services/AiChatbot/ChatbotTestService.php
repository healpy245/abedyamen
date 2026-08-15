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
    public function run(User $user, ChatbotInstance $instance, array $input): array
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
        $conversation = $this->resolveTestConversation($user, $instance, $input, $channel);
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
                ];
            }

            throw new RuntimeException('Test message is required.');
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
    ): ChatbotConversation {
        $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;
        $wantsReset = ! empty($input['reset']) || $conversationId === null;

        if ($wantsReset) {
            return $this->startFreshTestConversation($user, $instance, $input, $simulatedChannel);
        }

        $conversation = ChatbotConversation::query()
            ->where('id', $conversationId)
            ->where('instance_id', $instance->id)
            ->where('channel', ChatbotConversation::CHANNEL_TEST)
            ->first();

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
    ): ChatbotConversation {
        $phone = trim((string) ($input['phone'] ?? '0533046830'));
        if ($phone === '') {
            $phone = '0533046830';
        }

        $externalChatId = $this->externalChatIdForPhone($phone);
        $attributes = [
            'user_id' => $user->id,
            'title' => 'Simulation — '.now()->format('Y-m-d H:i'),
            'contact_phone' => $phone,
            'contact_name' => $input['customer_name'] ?? 'Test customer',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            'metadata' => [
                'simulation' => true,
                'simulated_channel' => $simulatedChannel,
            ],
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
