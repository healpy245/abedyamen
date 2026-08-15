<?php

namespace App\Http\Controllers\AiChatbot;

use App\Enums\Voice\VoiceInteractionMode;
use App\Enums\Voice\VoiceProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\SendVoiceCallerMessageRequest;
use App\Http\Requests\Voice\StartVoiceCallRequest;
use App\Http\Requests\Voice\SynthesizeSpeechRequest;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\Voice\SpeechTextSanitizer;
use App\Services\Voice\TextToSpeechService;
use App\Services\Voice\VoiceCallService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VoiceCallController extends Controller
{
    public function __construct(
        protected VoiceCallService $voiceCallService,
        protected AiChatbotInstanceService $instanceService,
        protected TextToSpeechService $textToSpeechService,
        protected SpeechTextSanitizer $speechTextSanitizer,
    ) {}

    public function index(Request $request, ChatbotInstance $instance)
    {
        $conversationId = $request->query('conversation');

        if ($conversationId) {
            $conversation = ChatbotConversation::query()
                ->where('id', $conversationId)
                ->where('user_id', $request->user()->id)
                ->where('instance_id', $instance->id)
                ->first();

            if ($conversation) {
                return redirect()->route('ai-chatbot.instances.conversations.show', [
                    'instance' => $instance,
                    'conversation' => $conversation,
                ]);
            }
        }

        $params = ['instance' => $instance];

        return redirect()->route('ai-chatbot.instances.show', $params);
    }

    public function show(Request $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->authorizeCallForInstance($voiceCall, $instance, $user);

        $layout = $this->instanceService->layoutData($user, $instance);

        return view('ai-chatbot.voice.index', array_merge($layout, [
            'activeCall' => $voiceCall,
            'messages' => $voiceCall->messages()->orderBy('id')->get(),
        ]));
    }

    public function start(StartVoiceCallRequest $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $validated = $request->validated();

        $call = $this->voiceCallService->startSimulatedCall(
            $user,
            $instance,
            $validated['caller_number'] ?? null,
            $request->interactionMode(),
            $request->voiceProfile(),
            $request->conversationId(),
        );

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'voice_call' => $this->serializeCall($call),
                'message_url' => route('ai-chatbot.instances.voice.message', [
                    'instance' => $instance,
                    'voiceCall' => $call,
                ]),
                'end_url' => route('ai-chatbot.instances.voice.end', [
                    'instance' => $instance,
                    'voiceCall' => $call,
                ]),
            ]);
        }

        $conversation = $call->chatbot_conversation_id
            ? ChatbotConversation::query()->find($call->chatbot_conversation_id)
            : null;

        if ($conversation) {
            return redirect()->route('ai-chatbot.instances.conversations.show', [
                'instance' => $instance,
                'conversation' => $conversation,
            ]);
        }

        return redirect()->route('ai-chatbot.instances.show', $instance);
    }

    public function synthesize(SynthesizeSpeechRequest $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        if ($this->textToSpeechService->usesBrowserFallback()) {
            return response()->json([
                'use_browser' => true,
            ]);
        }

        $text = $this->speechTextSanitizer->sanitize($request->validated('text'));

        if ($text === '') {
            return $this->errorResponse(__('voice.errors.tts_empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $audio = $this->textToSpeechService->synthesize(
                $text,
                $request->voiceProfile(),
                $request->locale(),
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'browser_tts') {
                return response()->json(['use_browser' => true]);
            }

            Log::warning('TTS synthesis failed', [
                'instance_id' => $instance->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('voice.errors.tts_failed'), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('TTS synthesis failed', [
                'instance_id' => $instance->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('voice.errors.tts_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response($audio, Response::HTTP_OK, [
            'Content-Type' => $this->detectAudioContentType($audio),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function detectAudioContentType(string $audio): string
    {
        if (str_starts_with($audio, 'RIFF') && str_contains(substr($audio, 0, 16), 'WAVE')) {
            return 'audio/wav';
        }

        if (str_starts_with($audio, 'OggS')) {
            return 'audio/ogg';
        }

        if (str_starts_with($audio, 'fLaC')) {
            return 'audio/flac';
        }

        // ID3 tag or MPEG frame sync
        if (str_starts_with($audio, 'ID3') || (strlen($audio) > 1 && (ord($audio[0]) & 0xFF) === 0xFF)) {
            return 'audio/mpeg';
        }

        return 'audio/mpeg';
    }

    public function sendMessage(SendVoiceCallerMessageRequest $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->authorizeCallForInstance($voiceCall, $instance, $user);

        try {
            $result = $this->voiceCallService->sendCallerMessage(
                $voiceCall,
                $user,
                $request->validated('message'),
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('Voice call message failed', [
                'voice_call_id' => $voiceCall->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(__('voice.errors.unexpected'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $userMessage = $result['caller_message'];
        $assistantMessage = $result['assistant_message'];

        // Reload chatbot messages for HTML partials when linked to a conversation.
        $voiceCall->refresh();
        $chatbotUserMsg = null;
        $chatbotAssistantMsg = null;
        if ($voiceCall->chatbot_conversation_id) {
            $chatbotUserMsg = \App\Models\AiChatbot\ChatbotMessage::query()
                ->where('conversation_id', $voiceCall->chatbot_conversation_id)
                ->where('role', 'user')
                ->latest('id')
                ->first();
            $chatbotAssistantMsg = \App\Models\AiChatbot\ChatbotMessage::query()
                ->where('conversation_id', $voiceCall->chatbot_conversation_id)
                ->where('role', 'assistant')
                ->latest('id')
                ->first();
        }

        return response()->json([
            'voice_call' => $this->serializeCall($voiceCall->fresh()),
            'conversation' => $voiceCall->chatbot_conversation_id ? [
                'id' => $voiceCall->chatbot_conversation_id,
            ] : null,
            'caller_message' => [
                'id' => $userMessage->id,
                'role' => 'caller',
                'content' => $userMessage->content,
            ],
            'assistant_message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $assistantMessage->content,
            ],
            'user_message_html' => $chatbotUserMsg
                ? view('ai-chatbot.partials.message', ['message' => $chatbotUserMsg])->render()
                : view('ai-chatbot.voice.partials.message', ['message' => $userMessage])->render(),
            'assistant_message_html' => $chatbotAssistantMsg
                ? view('ai-chatbot.partials.message', ['message' => $chatbotAssistantMsg])->render()
                : view('ai-chatbot.voice.partials.message', ['message' => $assistantMessage])->render(),
        ]);
    }

    public function end(Request $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);
        $this->authorizeCallForInstance($voiceCall, $instance, $user);

        $call = $this->voiceCallService->endCall($voiceCall, $user);

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'voice_call' => $this->serializeCall($call),
            ]);
        }

        return redirect()->route('ai-chatbot.instances.show', $instance);
    }

    protected function authorizeCallForInstance(VoiceCall $voiceCall, ChatbotInstance $instance, $user): void
    {
        $this->voiceCallService->authorizeCallForUser($voiceCall, $user);

        if ($voiceCall->chatbot_instance_id !== $instance->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCall(VoiceCall $call): array
    {
        $metadata = $call->metadata ?? [];

        return [
            'id' => $call->id,
            'status' => $call->statusEnum()->value,
            'provider' => $call->provider,
            'caller_number' => $call->caller_number,
            'conversation_id' => $call->chatbot_conversation_id,
            'interaction_mode' => $metadata['interaction_mode'] ?? VoiceInteractionMode::Text->value,
            'voice_profile' => $metadata['voice_profile'] ?? VoiceProfile::Woman->value,
            'voice_synthesis' => $metadata['voice_synthesis'] ?? VoiceProfile::Woman->synthesis(),
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->duration_seconds,
        ];
    }

    protected function errorResponse(string $message, int $status)
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
