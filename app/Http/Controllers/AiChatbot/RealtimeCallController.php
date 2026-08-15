<?php

namespace App\Http\Controllers\AiChatbot;

use App\Exceptions\Voice\RealtimeUpstreamException;
use App\Http\Controllers\AiChatbot\Concerns\RespondsWithRealtimeErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\ConnectRealtimeCallRequest;
use App\Http\Requests\Voice\CreateRealtimeSessionRequest;
use App\Http\Requests\Voice\ExecuteRealtimeToolRequest;
use App\Http\Requests\Voice\StoreRealtimeEventsRequest;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\Voice\Realtime\RealtimeSessionService;
use App\Services\Voice\Realtime\RealtimeToolExecutor;
use App\Services\Voice\Realtime\RealtimeTranscriptService;
use App\Services\Voice\VoiceCallService;
use App\Support\Voice\RealtimeSdpTracer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RealtimeCallController extends Controller
{
    use RespondsWithRealtimeErrors;

    public function __construct(
        protected RealtimeSessionService $sessionService,
        protected RealtimeTranscriptService $transcriptService,
        protected RealtimeToolExecutor $toolExecutor,
        protected VoiceCallService $voiceCallService,
        protected AiChatbotInstanceService $instanceService,
    ) {}

    public function createSession(CreateRealtimeSessionRequest $request, ChatbotInstance $instance)
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        try {
            $payload = $this->sessionService->createSession(
                $user,
                $instance,
                $request->conversationId(),
                $request->isReconnect(),
            );
        } catch (RuntimeException $e) {
            return $this->realtimeErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::error('Realtime session creation failed', ['error' => $e->getMessage()]);

            return $this->realtimeErrorResponse(
                __('voice.realtime.errors.session_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                config('app.debug') ? ['error' => $e->getMessage()] : [],
            );
        }

        return response()->json($payload);
    }

    public function connect(ConnectRealtimeCallRequest $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->authorizeCall($instance, $voiceCall, $user);

        try {
            $sdp = $request->offerSdp();
            RealtimeSdpTracer::trace('B_controller_after_validation', $sdp, [
                'voice_call_id' => $voiceCall->id,
                'instance_id' => $instance->id,
            ]);

            $answerSdp = $this->sessionService->connectWebRtc($instance, $voiceCall, $sdp);
        } catch (ValidationException $e) {
            return $this->realtimeValidationResponse($e);
        } catch (RealtimeUpstreamException $e) {
            return $this->realtimeUpstreamResponse($e);
        } catch (Throwable $e) {
            Log::error('Realtime WebRTC connect failed', [
                'voice_call_id' => $voiceCall->id,
                'error' => $e->getMessage(),
            ]);

            return $this->realtimeErrorResponse(
                __('voice.realtime.errors.webrtc_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                config('app.debug') ? ['error' => $e->getMessage()] : [],
            );
        }

        return response($answerSdp, Response::HTTP_OK)->header('Content-Type', 'application/sdp');
    }

    public function storeEvents(StoreRealtimeEventsRequest $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->authorizeCall($instance, $voiceCall, $user);

        $messages = [];
        $conversationId = $voiceCall->chatbot_conversation_id;

        foreach ($request->validated('events') as $event) {
            $type = (string) $event['type'];

            if ($type === 'transcript') {
                $result = $this->transcriptService->recordTranscript(
                    $voiceCall,
                    (string) ($event['role'] ?? 'system'),
                    (string) ($event['content'] ?? ''),
                    $event['payload'] ?? null,
                );

                if ($result !== null) {
                    $messages[] = [
                        'role' => $result['role'],
                        'html' => $result['message_html'],
                    ];
                    $conversationId = $result['conversation_id'];
                }
            } elseif ($type === 'metric') {
                $this->transcriptService->recordMetric(
                    $voiceCall,
                    (string) ($event['metric_key'] ?? 'unknown'),
                    $event['payload'] ?? null,
                );
            } elseif ($type === 'greeting_played') {
                $this->transcriptService->markGreetingPlayed($voiceCall);
            } elseif ($type === 'interruption') {
                $this->transcriptService->incrementInterruption($voiceCall);
            }
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversationId,
            'messages' => $messages,
        ]);
    }

    public function storeMetrics(Request $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->authorizeCall($instance, $voiceCall, $user);

        $validated = $request->validate([
            'metrics' => ['required', 'array'],
            'metrics.*' => ['nullable'],
        ]);

        foreach ($validated['metrics'] as $key => $value) {
            $this->transcriptService->recordMetric($voiceCall, (string) $key, is_array($value) ? $value : ['value' => $value]);
        }

        return response()->json(['ok' => true]);
    }

    public function executeTool(ExecuteRealtimeToolRequest $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->authorizeCall($instance, $voiceCall, $user);

        $result = $this->toolExecutor->execute(
            $voiceCall,
            $instance->id,
            $request->validated('tool_name'),
            $request->validated('arguments') ?? [],
            (string) $voiceCall->id,
        );

        return response()->json([
            'result' => $result,
        ]);
    }

    public function end(Request $request, ChatbotInstance $instance, VoiceCall $voiceCall)
    {
        $user = $request->user();
        $this->authorizeCall($instance, $voiceCall, $user);

        $call = $this->voiceCallService->endCall($voiceCall, $user);
        $summary = $this->transcriptService->generateSummary($call, $user);

        return response()->json([
            'voice_call' => [
                'id' => $call->id,
                'status' => $call->statusEnum()->value,
                'duration_seconds' => $call->duration_seconds,
                'interruption_count' => $call->interruption_count,
                'summary' => $summary,
            ],
        ]);
    }

    protected function authorizeCall(ChatbotInstance $instance, VoiceCall $voiceCall, $user): void
    {
        $this->instanceService->authorizeForUser($instance, $user);
        $this->voiceCallService->authorizeCallForUser($voiceCall, $user);

        if ($voiceCall->chatbot_instance_id !== $instance->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
