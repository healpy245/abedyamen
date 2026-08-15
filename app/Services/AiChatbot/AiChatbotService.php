<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanConversationMemoryService;
use App\Services\Malan\NumberDictationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AiChatbotService
{
    private const MAX_TOOL_ITERATIONS = 3;

    private const MAX_VOICE_TOOL_ITERATIONS = 2;

    public function __construct(
        protected AiChatbotSettingsService $settingsService,
        protected ChatbotToolDefinitions $toolDefinitions,
        protected ChatbotToolExecutor $toolExecutor,
        protected MalanConversationContextService $contextService,
        protected MalanConversationMemoryService $memoryService,
        protected ChatbotInstructionService $instructionService,
        protected NumberDictationService $numberDictationService,
    ) {}

    /**
     * @param  array{
     *     voice_mode?:bool,
     *     channel?:string,
     *     external_message_id?:string|null,
     *     message_type?:string,
     *     attachment_disk?:string|null,
     *     attachment_path?:string|null,
     *     attachment_mime?:string|null,
     *     skip_ai?:bool,
     *     dry_run?:bool,
     *     conversation_attributes?:array<string, mixed>
     * }  $options
     * @return array{conversation:ChatbotConversation,user_message:ChatbotMessage,assistant_message:?ChatbotMessage,tool_calls?:list<array<string,mixed>>,duration_ms?:int}
     */
    public function sendMessage(User $user, ChatbotInstance $instance, string $message, ?int $conversationId = null, array $options = []): array
    {
        $voiceMode = (bool) ($options['voice_mode'] ?? false);
        $channel = (string) ($options['channel'] ?? ($voiceMode ? 'voice' : ChatbotConversation::CHANNEL_WEB));
        if (! in_array($channel, [
            ChatbotConversation::CHANNEL_WEB,
            ChatbotConversation::CHANNEL_WHATSAPP,
            ChatbotConversation::CHANNEL_VOICE,
            ChatbotConversation::CHANNEL_TEST,
        ], true)) {
            $channel = ChatbotConversation::CHANNEL_WEB;
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $skipAi = (bool) ($options['skip_ai'] ?? false);

        $conversation = $this->findOrCreateConversation(
            $user,
            $instance,
            $conversationId,
            $channel,
            is_array($options['conversation_attributes'] ?? null) ? $options['conversation_attributes'] : [],
        );

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'external_message_id' => $options['external_message_id'] ?? null,
            'message_type' => (string) ($options['message_type'] ?? 'text'),
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $message,
            'attachment_disk' => $options['attachment_disk'] ?? null,
            'attachment_path' => $options['attachment_path'] ?? null,
            'attachment_mime' => $options['attachment_mime'] ?? null,
            'metadata' => array_filter([
                'channel' => $channel,
            ]),
        ]);

        if ($conversation->title === null) {
            $trimmed = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
            $conversation->title = mb_substr($trimmed, 0, 60) ?: 'New chat';
        }

        $conversation->forceFill([
            'channel' => $conversation->channel ?: $channel,
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);

        if ($channel === ChatbotConversation::CHANNEL_WHATSAPP) {
            $conversation->unread_count = (int) $conversation->unread_count + 1;
        }

        $conversation->save();

        if ($instance->hasMalanIntegration() && ! $dryRun) {
            $this->memoryService->observeUserMessage($conversation, $instance, $message);
        }

        if ($skipAi) {
            return [
                'conversation' => $conversation->fresh(),
                'user_message' => $userMessage,
                'assistant_message' => null,
            ];
        }

        $started = microtime(true);

        // Digit-by-digit phone/ID dictation: acknowledge mid-way without waiting on OpenAI.
        if ($instance->hasMalanIntegration() && ! $dryRun) {
            $dictation = $this->numberDictationService->ingest($conversation, $instance, $message);

            if (in_array($dictation['status'], ['incomplete', 'reset'], true) && is_string($dictation['reply']) && $dictation['reply'] !== '') {
                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'sender_type' => 'ai',
                    'message_type' => 'text',
                    'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                    'message' => $dictation['reply'],
                    'metadata' => [
                        'digit_dictation' => [
                            'status' => $dictation['status'],
                            'digits' => $dictation['digits'],
                            'kind' => $dictation['kind'],
                            'remaining' => $dictation['remaining'],
                        ],
                    ],
                ]);
                $conversation->recordAssistantActivity();

                return [
                    'conversation' => $conversation->fresh(),
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                    'tool_calls' => [],
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'instruction_ids' => [],
                    'active_prompt_sections' => [],
                ];
            }

            if ($dictation['status'] === 'complete' && is_string($dictation['digits']) && $dictation['digits'] !== '') {
                $kind = ($dictation['kind'] === 'identity') ? 'identity' : 'phone';
                $toolResult = $this->toolExecutor->execute(
                    $instance,
                    $conversation,
                    'lookup_malan_customer',
                    [
                        'lookup_type' => $kind,
                        'value' => $dictation['digits'],
                        'reason' => 'account_status',
                        'force_refresh' => true,
                    ],
                    $channel,
                );

                $toolCalls = [[
                    'name' => 'lookup_malan_customer',
                    'arguments' => [
                        'lookup_type' => $kind,
                        'value' => $dictation['digits'],
                        'reason' => 'account_status',
                        'force_refresh' => true,
                    ],
                    'result' => $toolResult,
                ]];

                $fastReply = app(VoiceFastReplyComposer::class)->fromToolCalls($toolCalls);
                if (! is_string($fastReply) || $fastReply === '') {
                    $label = $kind === 'phone' ? 'رقم التلفون' : 'رقم الهوية';
                    $fastReply = "تمام، سجّلت {$label} ". $dictation['digits'].'. لحظة أفحصلك الحساب…';
                }

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'sender_type' => 'ai',
                    'message_type' => 'text',
                    'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                    'message' => $fastReply,
                    'metadata' => [
                        'digit_dictation' => [
                            'status' => 'complete',
                            'digits' => $dictation['digits'],
                            'kind' => $kind,
                        ],
                        'tool_calls' => $toolCalls,
                    ],
                ]);
                $conversation->recordAssistantActivity();

                return [
                    'conversation' => $conversation->fresh(),
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                    'tool_calls' => $toolCalls,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'instruction_ids' => [],
                    'active_prompt_sections' => [],
                ];
            }
        }

        $generation = $this->generateAssistantReplyWithMeta(
            $conversation,
            $instance,
            $channel,
            $voiceMode,
            null,
            $dryRun,
        );

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => $generation['reply_source'],
            'message' => $generation['text'],
            'metadata' => array_filter([
                'instruction_ids' => $generation['instruction_ids'] !== [] ? $generation['instruction_ids'] : null,
                'tool_calls' => $generation['tool_calls'] !== [] ? $generation['tool_calls'] : null,
                'dry_run' => $dryRun ?: null,
            ]),
        ]);

        $conversation->recordAssistantActivity();

        if ($instance->hasMalanIntegration() && ! $dryRun) {
            $this->memoryService->observeAssistantMessage($conversation, $instance, $generation['text']);
        }

        return [
            'conversation' => $conversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'tool_calls' => $generation['tool_calls'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'instruction_ids' => $generation['instruction_ids'],
            'active_prompt_sections' => $generation['active_prompt_sections'],
        ];
    }

    /**
     * Generate an assistant reply for an existing conversation without creating another user message.
     *
     * @return array{conversation:ChatbotConversation,assistant_message:ChatbotMessage}
     */
    public function generateAssistantReplyForConversation(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $channel = 'web',
        bool $voiceMode = false,
        ?string $ephemeralUserContent = null,
    ): array {
        $generation = $this->generateAssistantReplyWithMeta(
            $conversation,
            $instance,
            $channel,
            $voiceMode,
            $ephemeralUserContent,
            false,
        );

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => $generation['reply_source'],
            'message' => $generation['text'],
            'metadata' => array_filter([
                'instruction_ids' => $generation['instruction_ids'] !== [] ? $generation['instruction_ids'] : null,
            ]),
        ]);

        $conversation->recordAssistantActivity();

        if ($instance->hasMalanIntegration()) {
            $this->memoryService->observeAssistantMessage($conversation, $instance, $generation['text']);
        }

        return [
            'conversation' => $conversation->fresh(),
            'assistant_message' => $assistantMessage,
        ];
    }

    /**
     * Persist a fixed assistant reply (e.g. payment-proof outcome) without calling OpenAI.
     *
     * @param  array{external_message_id?:string|null,message_type?:string,channel?:string}  $options
     * @return array{conversation:ChatbotConversation,user_message:ChatbotMessage,assistant_message:ChatbotMessage}
     */
    public function appendDirectExchange(
        User $user,
        ChatbotInstance $instance,
        string $userMessageText,
        string $assistantMessageText,
        ?int $conversationId = null,
        array $options = [],
    ): array {
        $channel = (string) ($options['channel'] ?? ChatbotConversation::CHANNEL_WEB);
        $conversation = $this->findOrCreateConversation($user, $instance, $conversationId, $channel);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'external_message_id' => $options['external_message_id'] ?? null,
            'message_type' => (string) ($options['message_type'] ?? 'text'),
            'reply_source' => ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => $userMessageText,
            'attachment_disk' => $options['attachment_disk'] ?? null,
            'attachment_path' => $options['attachment_path'] ?? null,
            'attachment_mime' => $options['attachment_mime'] ?? null,
        ]);

        if ($conversation->title === null) {
            $trimmed = trim(preg_replace('/\s+/', ' ', $userMessageText) ?? $userMessageText);
            $conversation->title = mb_substr($trimmed, 0, 60) ?: 'New chat';
        }

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ]);
        if ($channel === ChatbotConversation::CHANNEL_WHATSAPP) {
            $conversation->unread_count = (int) $conversation->unread_count + 1;
        }
        $conversation->save();

        $assistantMessage = null;
        if (trim($assistantMessageText) !== '') {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'system',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_SYSTEM,
                'message' => $assistantMessageText,
            ]);
            $conversation->recordAssistantActivity();
        }

        return [
            'conversation' => $conversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ];
    }

    /**
     * Store a customer message without generating an AI reply (paused / takeover / inactive).
     *
     * @param  array<string, mixed>  $options
     * @return array{conversation:ChatbotConversation,user_message:ChatbotMessage,assistant_message:?ChatbotMessage}
     */
    public function storeIncomingWithoutReply(
        User $user,
        ChatbotInstance $instance,
        string $message,
        ?int $conversationId = null,
        array $options = [],
        ?string $optionalSystemReply = null,
    ): array {
        $result = $this->sendMessage($user, $instance, $message, $conversationId, array_merge($options, [
            'skip_ai' => true,
        ]));

        if ($optionalSystemReply === null || trim($optionalSystemReply) === '') {
            return $result;
        }

        $conversation = $result['conversation'];
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'system',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_SYSTEM,
            'message' => $optionalSystemReply,
            'metadata' => ['disabled_notice' => true],
        ]);
        $conversation->recordAssistantActivity();

        return [
            'conversation' => $conversation->fresh(),
            'user_message' => $result['user_message'],
            'assistant_message' => $assistantMessage,
        ];
    }

    /**
     * Manual staff reply — never triggers AI.
     *
     * @return array{conversation:ChatbotConversation,assistant_message:ChatbotMessage}
     */
    public function appendHumanReply(
        User $staff,
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $message,
    ): array {
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'human',
            'sent_by_user_id' => $staff->id,
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_HUMAN,
            'message' => $message,
            'delivery_status' => $conversation->isWhatsApp() ? 'pending' : 'local',
        ]);

        $conversation->recordAssistantActivity();

        return [
            'conversation' => $conversation->fresh(),
            'assistant_message' => $assistantMessage,
        ];
    }

    public function resolveConversation(User $user, ChatbotInstance $instance, ?int $conversationId = null): ChatbotConversation
    {
        return $this->findOrCreateConversation($user, $instance, $conversationId);
    }

    /**
     * @return array{
     *     text:string,
     *     reply_source:string,
     *     instruction_ids:list<int>,
     *     tool_calls:list<array<string,mixed>>,
     *     active_prompt_sections:list<string>
     * }
     */
    protected function generateAssistantReplyWithMeta(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $channel,
        bool $voiceMode,
        ?string $ephemeralUserContent = null,
        bool $dryRun = false,
    ): array {
        $settings = $this->settingsService->all();
        $apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (! $apiKey) {
            throw new RuntimeException('Missing OpenAI API key. Set services.openai.api_key or OPENAI_API_KEY.');
        }

        $defaultModel = (string) ($settings['chatbot_model'] ?? 'gpt-4o-mini');
        $voiceModel = trim((string) config('voice.phone.model', ''));
        $model = ($voiceMode && $voiceModel !== '') ? $voiceModel : $defaultModel;
        $temperature = $voiceMode
            ? (float) config('voice.phone.temperature', 0.4)
            : (float) ($settings['temperature'] ?? 0.7);
        $maxTokens = $voiceMode
            ? (int) config('voice.phone.max_tokens', 90)
            : (int) ($settings['max_tokens'] ?? 2000);

        $instructionBundle = $this->instructionService->resolveForPrompt($conversation);
        $messages = $this->buildMessages(
            $conversation,
            $instance,
            $voiceMode,
            $channel,
            $ephemeralUserContent,
            $instructionBundle['section'],
        );

        $tools = $this->toolDefinitions->forInstance($instance, $channel, $voiceMode);
        $toolCallsLog = [];
        $timing = ['openai_ms' => 0, 'tools_ms' => 0];

        $iterations = 0;
        $maxIterations = $voiceMode ? self::MAX_VOICE_TOOL_ITERATIONS : self::MAX_TOOL_ITERATIONS;
        while ($iterations < $maxIterations) {
            $iterations++;
            $payload = [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ];

            if ($tools !== []) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $openaiStarted = microtime(true);
            $data = $this->callOpenAi($apiKey, $payload);
            $timing['openai_ms'] += (int) round((microtime(true) - $openaiStarted) * 1000);
            $choiceMessage = $data['choices'][0]['message'] ?? null;
            if (! is_array($choiceMessage)) {
                throw new RuntimeException('The AI provider did not return a usable response.');
            }

            $toolCalls = $choiceMessage['tool_calls'] ?? null;
            if (! is_array($toolCalls) || $toolCalls === []) {
                $assistantText = is_string($choiceMessage['content'] ?? null)
                    ? trim((string) $choiceMessage['content'])
                    : '';

                if ($assistantText === '') {
                    throw new RuntimeException('The AI provider did not return a usable response.');
                }

                $appliedIds = [];
                if (! $dryRun && $instructionBundle['ids'] !== []) {
                    $appliedIds = $this->instructionService->consumeAfterReply($conversation, $instructionBundle['ids']);
                }

                $compiler = app(PromptCompiler::class);

                Log::info('AiChatbot reply timing', [
                    'instance_id' => $instance->id,
                    'conversation_id' => $conversation->id,
                    'voice_mode' => $voiceMode,
                    'openai_ms' => $timing['openai_ms'],
                    'tools_ms' => $timing['tools_ms'],
                    'tool_rounds' => count($toolCallsLog),
                    'fast_tool_reply' => false,
                ]);

                return [
                    'text' => $assistantText,
                    'reply_source' => $appliedIds !== []
                        ? ChatbotMessage::REPLY_SOURCE_AI_INSTRUCTED
                        : ChatbotMessage::REPLY_SOURCE_AI,
                    'instruction_ids' => $appliedIds,
                    'tool_calls' => $toolCallsLog,
                    'active_prompt_sections' => $compiler->activeSectionNames($instance->prompt_sections),
                ];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $choiceMessage['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $toolCall) {
                if (! is_array($toolCall)) {
                    continue;
                }

                $toolCallId = (string) ($toolCall['id'] ?? '');
                $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $toolName = (string) ($function['name'] ?? '');
                $rawArgs = (string) ($function['arguments'] ?? '{}');
                $decoded = json_decode($rawArgs, true);
                $arguments = is_array($decoded) ? $decoded : [];

                $toolStarted = microtime(true);
                if ($dryRun) {
                    $mutating = in_array($toolName, [
                        'charge_malan_saved_payment_method',
                        'create_malan_one_time_payment_link',
                        'request_malan_service_reactivation',
                    ], true);
                    $toolResult = $mutating
                        ? [
                            'success' => false,
                            'dry_run' => true,
                            'message' => 'Simulation only — payment/reactivation tool not executed.',
                        ]
                        : $this->toolExecutor->execute(
                            $instance,
                            $conversation,
                            $toolName,
                            $arguments,
                            $channel,
                        );
                } else {
                    $toolResult = $this->toolExecutor->execute(
                        $instance,
                        $conversation,
                        $toolName,
                        $arguments,
                        $channel,
                    );
                }
                $timing['tools_ms'] += (int) round((microtime(true) - $toolStarted) * 1000);

                $toolCallsLog[] = [
                    'name' => $toolName,
                    'arguments' => $arguments,
                    'result' => $toolResult,
                ];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId !== '' ? $toolCallId : ('call_'.$iterations),
                    'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }

            // After tools, compose a short reply and skip a second OpenAI round-trip (~1–2s).
            if ($voiceMode && filter_var(config('voice.phone.fast_tool_replies', true), FILTER_VALIDATE_BOOLEAN)) {
                $fastReply = app(VoiceFastReplyComposer::class)->fromToolCalls($toolCallsLog);
                if (is_string($fastReply) && $fastReply !== '') {
                    $appliedIds = [];
                    if (! $dryRun && $instructionBundle['ids'] !== []) {
                        $appliedIds = $this->instructionService->consumeAfterReply($conversation, $instructionBundle['ids']);
                    }

                    Log::info('AiChatbot reply timing', [
                        'instance_id' => $instance->id,
                        'conversation_id' => $conversation->id,
                        'voice_mode' => $voiceMode,
                        'openai_ms' => $timing['openai_ms'],
                        'tools_ms' => $timing['tools_ms'],
                        'tool_rounds' => count($toolCallsLog),
                        'fast_tool_reply' => true,
                    ]);

                    return [
                        'text' => $fastReply,
                        'reply_source' => $appliedIds !== []
                            ? ChatbotMessage::REPLY_SOURCE_AI_INSTRUCTED
                            : ChatbotMessage::REPLY_SOURCE_AI,
                        'instruction_ids' => $appliedIds,
                        'tool_calls' => $toolCallsLog,
                        'active_prompt_sections' => app(PromptCompiler::class)->activeSectionNames($instance->prompt_sections),
                    ];
                }
            }
        }

        $openaiStarted = microtime(true);
        $final = $this->callOpenAi($apiKey, [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'messages' => array_merge($messages, [[
                'role' => 'system',
                'content' => 'Tool loop limit reached. Reply to the customer naturally without calling tools. Do not invent account status, debt amounts, payment success, or report success.',
            ]]),
        ]);
        $timing['openai_ms'] += (int) round((microtime(true) - $openaiStarted) * 1000);

        $assistantText = is_string($final['choices'][0]['message']['content'] ?? null)
            ? trim((string) $final['choices'][0]['message']['content'])
            : '';

        if ($assistantText === '') {
            $assistantText = 'ولا يهمك، صار تأخير بسيط بالفحص. رح أحوّل الموضوع لموظف يتابع معك بأقرب وقت.';
        }

        $appliedIds = [];
        if (! $dryRun && $instructionBundle['ids'] !== []) {
            $appliedIds = $this->instructionService->consumeAfterReply($conversation, $instructionBundle['ids']);
        }

        Log::info('AiChatbot reply timing', [
            'instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'voice_mode' => $voiceMode,
            'openai_ms' => $timing['openai_ms'],
            'tools_ms' => $timing['tools_ms'],
            'tool_rounds' => count($toolCallsLog),
            'fast_tool_reply' => false,
            'tool_loop_exhausted' => true,
        ]);

        return [
            'text' => $assistantText,
            'reply_source' => $appliedIds !== []
                ? ChatbotMessage::REPLY_SOURCE_AI_INSTRUCTED
                : ChatbotMessage::REPLY_SOURCE_AI,
            'instruction_ids' => $appliedIds,
            'tool_calls' => $toolCallsLog,
            'active_prompt_sections' => app(PromptCompiler::class)->activeSectionNames($instance->prompt_sections),
        ];
    }

    /**
     * @deprecated Prefer generateAssistantReplyWithMeta
     */
    protected function generateAssistantReply(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $channel,
        bool $voiceMode,
        ?string $ephemeralUserContent = null,
    ): string {
        return $this->generateAssistantReplyWithMeta(
            $conversation,
            $instance,
            $channel,
            $voiceMode,
            $ephemeralUserContent,
            false,
        )['text'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildMessages(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        bool $voiceMode,
        string $channel,
        ?string $ephemeralUserContent = null,
        ?string $instructionSection = null,
    ): array {
        $rawMessages = $conversation->messages()->orderBy('id')->get();
        $visionEligibleIds = $rawMessages
            ->filter(function (ChatbotMessage $msg) {
                return $msg->role === 'user'
                    && is_string($msg->attachment_path)
                    && $msg->attachment_path !== ''
                    && is_string($msg->attachment_mime)
                    && str_starts_with(strtolower($msg->attachment_mime), 'image/');
            })
            ->sortByDesc('id')
            ->take(2)
            ->pluck('id')
            ->all();

        $history = $rawMessages
            ->map(function (ChatbotMessage $msg) use ($visionEligibleIds) {
                return [
                    'role' => $msg->role,
                    'content' => $this->openAiContentForMessage(
                        $msg,
                        in_array($msg->id, $visionEligibleIds, true),
                    ),
                ];
            })
            ->all();

        if ($ephemeralUserContent !== null && $ephemeralUserContent !== '') {
            $history[] = [
                'role' => 'user',
                'content' => $ephemeralUserContent,
            ];
        }

        if ($voiceMode) {
            $limit = max(4, (int) config('voice.phone.history_limit', 8));
            if (count($history) > $limit) {
                $history = array_values(array_slice($history, -$limit));
            }
        }

        $systemPrompt = trim((string) ($instance->system_prompt ?? ''));

        if ($voiceMode) {
            $voicePrompt = trim((string) __('voice.phone.agent_system_prompt'));
            $systemPrompt = trim($systemPrompt."\n\n".$voicePrompt."\n\n".
                '# Voice pronunciation override (mandatory on calls)'."\n".
                '- Never output Hebrew letters (א-ת) in spoken replies.'."\n".
                '- Say bank reference as Arabic: "رقم الإيصال" or "أسمختا" — not מספר אסמכתה.'."\n".
                '- Keep fillers minimal: avoid elongated إييي / آآآ / ممم; speak directly.'."\n".
                '- Prefer one short clear sentence over hesitation + long explanation.'."\n".
                '- Before raising any task/report: read a short draft, ask for customer approval and optional simple note, then call the tool only after explicit approval.');
        }

        $context = $instance->hasMalanIntegration()
            ? $this->memoryService->rememberForConversation($conversation, $instance)
            : $this->contextService->getActive($conversation);
        $summary = $this->contextService->toPromptSummary($context);
        if ($summary !== null) {
            $systemPrompt = trim($systemPrompt."\n\n".'# Verified server-side conversation context (authoritative — scoped to this conversation only)'."\n".
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n".
                'This memory belongs only to conversation #'.$conversation->id.'. Never mix with other chats. '.
                'Do not ask again for phone/identity. Do not invent a different customer_id. '.
                'If pending_flow is awaiting_bank_transfer_proof, treat the next image as bank transfer proof. '.
                'When the customer chooses bank transfer or visa, call set_malan_payment_method_preference.');
        }

        if (
            in_array($channel, [ChatbotConversation::CHANNEL_WHATSAPP, ChatbotConversation::CHANNEL_TEST], true)
            && $instance->hasMalanIntegration()
        ) {
            $whatsappPhone = null;
            $normalizer = new \App\Services\Malan\MalanPhoneNormalizer;
            foreach ([
                is_string($conversation->contact_phone) ? $conversation->contact_phone : null,
                is_string($conversation->external_chat_id)
                    ? preg_replace('/@.*$/', '', $conversation->external_chat_id)
                    : null,
            ] as $candidate) {
                if (! is_string($candidate) || trim($candidate) === '') {
                    continue;
                }
                $normalized = $normalizer->normalize($candidate);
                if (($normalized['valid'] ?? false) === true) {
                    $whatsappPhone = $normalized['normalized'];
                    break;
                }
            }

            if (is_string($whatsappPhone) && $whatsappPhone !== '') {
                $systemPrompt = trim($systemPrompt."\n\n".
                    '# Chat contact phone (authoritative for this chat)'."\n".
                    'whatsapp_chat_phone='.$whatsappPhone."\n".
                    'If the customer says to check "الرقم الي بحكي منه" / "هالرقم" / this WhatsApp number, call lookup_malan_customer with lookup_type=phone and value=whatsapp_chat_phone (server resolves it). Do not invent another number.');
            }
        }

        if ($instructionSection !== null && trim($instructionSection) !== '') {
            $systemPrompt = trim($systemPrompt."\n\n".$instructionSection);
        }

        $systemPrompt = trim($systemPrompt."\n\n".'Current channel: '.$channel.'. Never expose JSON, tool names, or HTTP errors to the customer.');

        if ($systemPrompt !== '') {
            array_unshift($history, [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        return $history;
    }

    /**
     * Build OpenAI message content. Image attachments are sent as vision inputs so the model can read/transcribe them.
     *
     * @return string|list<array<string, mixed>>
     */
    protected function openAiContentForMessage(ChatbotMessage $msg, bool $includeVision = true): string|array
    {
        $text = (string) $msg->message;

        if ($msg->role !== 'user' || ! $includeVision) {
            return $text;
        }

        $path = is_string($msg->attachment_path) ? trim($msg->attachment_path) : '';
        $mime = is_string($msg->attachment_mime) ? strtolower(trim($msg->attachment_mime)) : '';
        if ($path === '' || ! str_starts_with($mime, 'image/')) {
            return $text;
        }

        $disk = is_string($msg->attachment_disk) && $msg->attachment_disk !== ''
            ? $msg->attachment_disk
            : (string) config('malan.media.disk', 'local');

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return $text !== '' ? $text : '[صورة مرفقة غير متاحة للقراءة]';
            }

            $binary = Storage::disk($disk)->get($path);
            if (! is_string($binary) || $binary === '') {
                return $text !== '' ? $text : '[صورة مرفقة فارغة]';
            }

            // Keep payloads bounded for chat history.
            if (strlen($binary) > 4 * 1024 * 1024) {
                return $text !== '' ? $text : '[صورة كبيرة جدًا للقراءة التلقائية]';
            }

            $dataUrl = 'data:'.$mime.';base64,'.base64_encode($binary);
            $caption = $text !== '' ? $text : 'الزبون أرسل صورة عبر WhatsApp. اقرأ النص الظاهر فيها (OCR) وحلّل المحتوى، ورد بما تفهمه منها.';

            return [
                [
                    'type' => 'text',
                    'text' => $caption,
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $dataUrl,
                        'detail' => 'high',
                    ],
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to attach WhatsApp image for OpenAI vision', [
                'message_id' => $msg->id,
                'error' => $e->getMessage(),
            ]);

            return $text !== '' ? $text : '[تعذر قراءة الصورة المرفقة]';
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function callOpenAi(string $apiKey, array $payload): array
    {
        $http = Http::timeout(45)
            ->withToken($apiKey)
            ->acceptJson();

        $verifySsl = config('services.openai.verify_ssl', true);
        if (! $verifySsl) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (Throwable $e) {
            $errorText = (string) $e->getMessage();

            Log::warning('AiChatbot OpenAI request failed', [
                'error' => $errorText,
            ]);

            if (str_contains($errorText, 'SSL certificate problem')) {
                throw new RuntimeException(
                    'Unable to reach the AI provider due to local SSL certificate verification (cURL error 60). For local development only, set OPENAI_VERIFY_SSL=false in .env, then run php artisan optimize:clear.'
                );
            }

            throw new RuntimeException('Unable to reach the AI provider. Please try again later.');
        }

        if (! $response->successful()) {
            $body = $response->json();
            $errorMessage = is_array($body)
                ? ($body['error']['message'] ?? $body['message'] ?? $response->body())
                : $response->body();

            $errorMessage = is_string($errorMessage) ? $errorMessage : json_encode($errorMessage);

            Log::warning('AiChatbot OpenAI error response', [
                'status' => $response->status(),
                'message' => $errorMessage,
            ]);

            throw new RuntimeException($errorMessage ?: 'The AI provider returned an error.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('The AI provider returned an invalid response.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function findOrCreateConversation(
        User $user,
        ChatbotInstance $instance,
        ?int $conversationId = null,
        string $channel = ChatbotConversation::CHANNEL_WEB,
        array $attributes = [],
    ): ChatbotConversation {
        if ($conversationId === null) {
            return ChatbotConversation::create(array_merge([
                'user_id' => $user->id,
                'instance_id' => $instance->id,
                'title' => null,
                'channel' => $channel,
                'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
                'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
            ], $attributes));
        }

        $conversation = ChatbotConversation::where('id', $conversationId)
            ->where('instance_id', $instance->id)
            ->first();

        // Preserve legacy owner-scoped lookup for web chat; WhatsApp conversations
        // are owned by the instance owner but staff may operate them.
        if ($conversation === null) {
            throw new RuntimeException('Conversation not found.');
        }

        if (
            $conversation->channel !== ChatbotConversation::CHANNEL_WHATSAPP
            && $conversation->channel !== ChatbotConversation::CHANNEL_TEST
            && (int) $conversation->user_id !== (int) $user->id
            && ! ($user->is_admin ?? false)
        ) {
            throw new RuntimeException('Conversation not found.');
        }

        return $conversation;
    }
}
