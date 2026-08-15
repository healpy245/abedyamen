<?php

declare(strict_types=1);

namespace App\Services\Voice\Streaming;

use App\Enums\Voice\VoiceProfile;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\AiChatbot\VoiceFastReplyComposer;
use App\Services\Malan\NumberDictationService;
use App\Services\Voice\TextToSpeechService;
use Generator;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Voice-only turn pipeline: stream OpenAI → phrase chunks → TTS audio events.
 * Isolated from WhatsApp/text chat paths.
 */
class VoiceStreamingTurnService
{
    public function __construct(
        protected AiChatbotService $chatbotService,
        protected ChatbotToolDefinitions $toolDefinitions,
        protected ChatbotToolExecutor $toolExecutor,
        protected VoiceFastReplyComposer $fastReplyComposer,
        protected NumberDictationService $numberDictationService,
        protected TextToSpeechService $textToSpeechService,
        protected OpenAiVoiceStreamClient $streamClient,
        protected VoiceResponseChunker $chunker,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function run(
        User $user,
        ChatbotInstance $instance,
        string $message,
        ?int $conversationId,
        VoiceProfile $profile,
        string $locale,
        string $channel = 'test',
    ): Generator {
        $tracker = VoiceLatencyTracker::create();
        yield ['type' => 'turn_start', 'turn_id' => $tracker->turnId, 'timing' => $tracker->snapshot()];

        $tracker->mark('llm_request_started');

        // Persist user turn via existing service path helpers (conversation create + message).
        $send = $this->chatbotService->sendMessage($user, $instance, $message, $conversationId, [
            'voice_mode' => true,
            'channel' => $channel,
            'skip_ai' => true,
        ]);

        /** @var ChatbotConversation $conversation */
        $conversation = $send['conversation'];
        yield [
            'type' => 'conversation',
            'conversation_id' => $conversation->id,
            'turn_id' => $tracker->turnId,
        ];

        // Digit dictation short-circuit (acks / complete lookup) — already fastest path.
        if ($instance->hasMalanIntegration()) {
            $dictation = $this->numberDictationService->ingest($conversation, $instance, $message);
            if (in_array($dictation['status'], ['incomplete', 'reset'], true) && is_string($dictation['reply'])) {
                yield from $this->emitSpokenReply(
                    $conversation,
                    $dictation['reply'],
                    $profile,
                    $locale,
                    $tracker,
                    toolCalls: [],
                    replySource: ChatbotMessage::REPLY_SOURCE_AI,
                );

                return;
            }

            if ($dictation['status'] === 'complete' && is_string($dictation['digits'])) {
                $kind = ($dictation['kind'] === 'identity') ? 'identity' : 'phone';
                $tracker->mark('tool_started');
                $toolStarted = microtime(true);
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
                $tracker->set('malan_tool', (int) round((microtime(true) - $toolStarted) * 1000));
                $tracker->mark('tool_finished');

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
                $reply = $this->fastReplyComposer->fromToolCalls($toolCalls)
                    ?: ('تمام، سجّلت الرقم. لحظة أفحصلك الحساب…');

                yield from $this->emitSpokenReply(
                    $conversation,
                    $reply,
                    $profile,
                    $locale,
                    $tracker,
                    $toolCalls,
                    ChatbotMessage::REPLY_SOURCE_AI,
                );

                return;
            }
        }

        $apiKey = (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY'));
        if ($apiKey === '') {
            yield ['type' => 'error', 'message' => 'Missing OpenAI API key.', 'turn_id' => $tracker->turnId];

            return;
        }

        $messages = $this->buildVoiceMessages($conversation, $instance, $channel);
        $tools = $this->toolDefinitions->forInstance($instance, $channel, true);
        $model = trim((string) config('voice.phone.model', 'gpt-4o-mini')) ?: 'gpt-4o-mini';
        $payload = [
            'model' => $model,
            'temperature' => (float) config('voice.phone.temperature', 0.35),
            'max_tokens' => (int) config('voice.phone.max_tokens', 70),
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $sequence = 0;
        $phraseBuffer = '';
        $firstPhraseSent = false;
        $toolCallsLog = [];
        $collectedContent = '';
        $streamToolCalls = [];
        $firstTokenMs = 0;

        try {
            foreach ($this->streamClient->events($apiKey, $payload) as $event) {
                $type = (string) ($event['type'] ?? '');

                if ($type === 'meta') {
                    $firstTokenMs = (int) ($event['first_token_ms'] ?? 0);
                    $tracker->set('llm_first_token', $firstTokenMs);
                    yield ['type' => 'timing', 'turn_id' => $tracker->turnId, 'key' => 'llm_first_token', 'ms' => $firstTokenMs];
                    continue;
                }

                if ($type === 'content') {
                    $delta = (string) ($event['text'] ?? '');
                    $collectedContent .= $delta;
                    $fed = $this->chunker->feed($phraseBuffer, $delta, ! $firstPhraseSent);
                    $phraseBuffer = $fed['buffer'];
                    if (is_string($fed['phrase']) && $fed['phrase'] !== '') {
                        $sequence++;
                        if (! $firstPhraseSent) {
                            $tracker->mark('llm_first_phrase');
                            $tracker->set('llm_first_phrase', $tracker->durationMs('llm_request_started', 'llm_first_phrase'));
                            $firstPhraseSent = true;
                            yield ['type' => 'timing', 'turn_id' => $tracker->turnId, 'key' => 'llm_first_phrase', 'ms' => $tracker->snapshot()['llm_first_phrase'] ?? 0];
                        }
                        yield [
                            'type' => 'assistant_phrase',
                            'turn_id' => $tracker->turnId,
                            'sequence' => $sequence,
                            'text' => $fed['phrase'],
                        ];
                        yield from $this->emitAudioForPhrase($fed['phrase'], $sequence, $profile, $locale, $tracker);
                    }
                    continue;
                }

                if ($type === 'done') {
                    $streamToolCalls = is_array($event['tool_calls'] ?? null) ? $event['tool_calls'] : [];
                    if ($collectedContent === '' && is_string($event['content'] ?? null)) {
                        $collectedContent = trim((string) $event['content']);
                    }
                    if ($firstTokenMs === 0) {
                        $firstTokenMs = (int) ($event['first_token_ms'] ?? 0);
                        $tracker->set('llm_first_token', $firstTokenMs);
                    }
                }
            }
        } catch (Throwable $e) {
            yield ['type' => 'error', 'message' => $e->getMessage(), 'turn_id' => $tracker->turnId];

            return;
        }

        // Tool calls: discard any accidental spoken phrases (should be none) and wait for verified data.
        if ($streamToolCalls !== []) {
            $tracker->mark('tool_started');
            $toolStarted = microtime(true);
            $messages[] = [
                'role' => 'assistant',
                'content' => $collectedContent !== '' ? $collectedContent : null,
                'tool_calls' => $streamToolCalls,
            ];

            foreach ($streamToolCalls as $toolCall) {
                $name = (string) ($toolCall['function']['name'] ?? '');
                $rawArgs = (string) ($toolCall['function']['arguments'] ?? '{}');
                $args = json_decode($rawArgs, true);
                if (! is_array($args)) {
                    $args = [];
                }
                $result = $this->toolExecutor->execute($instance, $conversation, $name, $args, $channel);
                $toolCallsLog[] = [
                    'name' => $name,
                    'arguments' => $args,
                    'result' => $result,
                ];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? ('call_'.count($toolCallsLog))),
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }
            $tracker->set('malan_tool', (int) round((microtime(true) - $toolStarted) * 1000));
            $tracker->mark('tool_finished');
            yield ['type' => 'timing', 'turn_id' => $tracker->turnId, 'key' => 'malan_tool', 'ms' => $tracker->snapshot()['malan_tool'] ?? 0];

            if (filter_var(config('voice.phone.fast_tool_replies', true), FILTER_VALIDATE_BOOLEAN)) {
                $fast = $this->fastReplyComposer->fromToolCalls($toolCallsLog);
                if (is_string($fast) && $fast !== '') {
                    $tracker->set('reply_composer', 1);
                    yield from $this->emitSpokenReply(
                        $conversation,
                        $fast,
                        $profile,
                        $locale,
                        $tracker,
                        $toolCallsLog,
                        ChatbotMessage::REPLY_SOURCE_AI,
                    );

                    return;
                }
            }

            $secondPayload = [
                'model' => $model,
                'temperature' => (float) config('voice.phone.temperature', 0.35),
                'max_tokens' => (int) config('voice.phone.max_tokens', 70),
                'messages' => $messages,
            ];
            $phraseBuffer = '';
            $firstPhraseSent = false;
            $sequence = 0;
            $finalText = '';
            try {
                foreach ($this->streamClient->events($apiKey, $secondPayload) as $event) {
                    if (($event['type'] ?? null) === 'content') {
                        $delta = (string) $event['text'];
                        $finalText .= $delta;
                        $fed = $this->chunker->feed($phraseBuffer, $delta, ! $firstPhraseSent);
                        $phraseBuffer = $fed['buffer'];
                        if (is_string($fed['phrase']) && $fed['phrase'] !== '') {
                            $sequence++;
                            $firstPhraseSent = true;
                            yield [
                                'type' => 'assistant_phrase',
                                'turn_id' => $tracker->turnId,
                                'sequence' => $sequence,
                                'text' => $fed['phrase'],
                            ];
                            yield from $this->emitAudioForPhrase($fed['phrase'], $sequence, $profile, $locale, $tracker);
                        }
                    }
                    if (($event['type'] ?? null) === 'done') {
                        if ($finalText === '' && is_string($event['content'] ?? null)) {
                            $finalText = (string) $event['content'];
                        }
                    }
                }
            } catch (Throwable $e) {
                yield ['type' => 'error', 'message' => $e->getMessage(), 'turn_id' => $tracker->turnId];

                return;
            }

            $tail = trim($phraseBuffer);
            if ($tail !== '') {
                $sequence++;
                yield [
                    'type' => 'assistant_phrase',
                    'turn_id' => $tracker->turnId,
                    'sequence' => $sequence,
                    'text' => $tail,
                ];
                yield from $this->emitAudioForPhrase($tail, $sequence, $profile, $locale, $tracker);
            }

            $finalText = trim($finalText);
            if ($finalText === '') {
                $finalText = 'ولا يهمك، صار تأخير بسيط. خليني أأكدلك المعلومة وأرجع عليك.';
            }
            $this->persistAssistantMessage($conversation, $finalText, $toolCallsLog);
            $tracker->flushLog();
            yield [
                'type' => 'assistant_done',
                'turn_id' => $tracker->turnId,
                'text' => $finalText,
                'timing' => $tracker->snapshot(),
            ];

            return;
        }

        // Flush remaining buffer from progressive text stream.
        $tail = trim($phraseBuffer);
        if ($tail !== '') {
            $sequence++;
            if (! $firstPhraseSent) {
                $tracker->mark('llm_first_phrase');
                $tracker->set('llm_first_phrase', $tracker->durationMs('llm_request_started', 'llm_first_phrase'));
            }
            yield [
                'type' => 'assistant_phrase',
                'turn_id' => $tracker->turnId,
                'sequence' => $sequence,
                'text' => $tail,
            ];
            yield from $this->emitAudioForPhrase($tail, $sequence, $profile, $locale, $tracker);
        }

        $finalText = trim($collectedContent);
        if ($finalText === '') {
            $finalText = 'ما سمعتك منيح، ممكن تعيد؟';
            if ($sequence === 0) {
                yield from $this->emitSpokenReply(
                    $conversation,
                    $finalText,
                    $profile,
                    $locale,
                    $tracker,
                    [],
                    ChatbotMessage::REPLY_SOURCE_AI,
                );

                return;
            }
        }

        $this->persistAssistantMessage($conversation, $finalText, $toolCallsLog);
        $tracker->mark('assistant_done');
        $tracker->flushLog();
        yield [
            'type' => 'assistant_done',
            'turn_id' => $tracker->turnId,
            'text' => $finalText,
            'timing' => $tracker->snapshot(),
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function emitAudioForPhrase(
        string $phrase,
        int $sequence,
        VoiceProfile $profile,
        string $locale,
        VoiceLatencyTracker $tracker,
    ): Generator {
        $ttsStarted = microtime(true);
        try {
            $audio = $this->textToSpeechService->synthesize($phrase, $profile, $locale);
            if ($sequence === 1) {
                $tracker->set('tts_first_byte', (int) round((microtime(true) - $ttsStarted) * 1000));
                $tracker->mark('audio_first_ready');
                $tracker->set('time_to_first_audio', $tracker->durationMs('turn_start', 'audio_first_ready'));
                yield [
                    'type' => 'timing',
                    'turn_id' => $tracker->turnId,
                    'key' => 'time_to_first_audio',
                    'ms' => $tracker->snapshot()['time_to_first_audio'] ?? 0,
                ];
            }
            yield [
                'type' => 'audio_chunk',
                'turn_id' => $tracker->turnId,
                'sequence' => $sequence,
                'content_type' => $this->detectAudioContentType($audio),
                'audio_base64' => base64_encode($audio),
            ];
        } catch (Throwable $e) {
            Log::warning('Voice stream TTS failed', ['error' => $e->getMessage(), 'sequence' => $sequence]);
            yield [
                'type' => 'tts_error',
                'turn_id' => $tracker->turnId,
                'sequence' => $sequence,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @return Generator<int, array<string, mixed>>
     */
    private function emitSpokenReply(
        ChatbotConversation $conversation,
        string $text,
        VoiceProfile $profile,
        string $locale,
        VoiceLatencyTracker $tracker,
        array $toolCalls,
        string $replySource,
    ): Generator {
        $phrases = $this->chunker->split($text);
        if ($phrases === []) {
            $phrases = [$text];
        }

        $sequence = 0;
        foreach ($phrases as $phrase) {
            $sequence++;
            if ($sequence === 1) {
                $tracker->mark('llm_first_phrase');
                $tracker->set('llm_first_phrase', $tracker->durationMs('turn_start', 'llm_first_phrase'));
            }
            yield [
                'type' => 'assistant_phrase',
                'turn_id' => $tracker->turnId,
                'sequence' => $sequence,
                'text' => $phrase,
            ];
            yield from $this->emitAudioForPhrase($phrase, $sequence, $profile, $locale, $tracker);
        }

        $this->persistAssistantMessage($conversation, $text, $toolCalls, $replySource);
        $tracker->flushLog();
        yield [
            'type' => 'assistant_done',
            'turn_id' => $tracker->turnId,
            'text' => $text,
            'timing' => $tracker->snapshot(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     */
    private function persistAssistantMessage(
        ChatbotConversation $conversation,
        string $text,
        array $toolCalls,
        string $replySource = ChatbotMessage::REPLY_SOURCE_AI,
    ): void {
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => $replySource,
            'message' => $text,
            'metadata' => array_filter([
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
                'voice_stream' => true,
            ]),
        ]);
        $conversation->recordAssistantActivity();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVoiceMessages(ChatbotConversation $conversation, ChatbotInstance $instance, string $channel): array
    {
        $method = new ReflectionMethod($this->chatbotService, 'buildMessages');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $messages */
        $messages = $method->invoke($this->chatbotService, $conversation, $instance, true, $channel, null, null);

        return $messages;
    }

    private function detectAudioContentType(string $audio): string
    {
        if (str_starts_with($audio, 'RIFF') && str_contains(substr($audio, 0, 16), 'WAVE')) {
            return 'audio/wav';
        }
        if (str_starts_with($audio, 'ID3') || (strlen($audio) > 1 && (ord($audio[0]) & 0xFF) === 0xFF)) {
            return 'audio/mpeg';
        }

        return 'audio/wav';
    }
}
