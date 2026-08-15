<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Enums\Voice\VoiceProfile;
use App\Http\Controllers\Controller;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\Voice\Streaming\VoiceStreamingTurnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VoiceStreamController extends Controller
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
        protected VoiceStreamingTurnService $streamingTurnService,
    ) {}

    public function converse(Request $request, ChatbotInstance $instance): StreamedResponse
    {
        $user = $request->user();
        $this->instanceService->authorizeForUser($instance, $user);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
            'voice_profile' => ['nullable', 'string'],
            'locale' => ['nullable', 'string', 'max:16'],
            'channel' => ['nullable', 'string', 'max:32'],
        ]);

        $profile = VoiceProfile::tryFrom((string) ($validated['voice_profile'] ?? 'woman'))
            ?? VoiceProfile::Woman;
        $locale = (string) ($validated['locale'] ?? 'ar');
        $channel = (string) ($validated['channel'] ?? 'test');
        $conversationId = isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null;
        $message = trim((string) $validated['message']);

        return response()->stream(function () use ($user, $instance, $message, $conversationId, $profile, $locale, $channel): void {
            // Disable output buffering for progressive SSE.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                foreach ($this->streamingTurnService->run(
                    $user,
                    $instance,
                    $message,
                    $conversationId,
                    $profile,
                    $locale,
                    $channel,
                ) as $event) {
                    echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();

                    if (connection_aborted()) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                Log::error('Voice stream converse failed', ['error' => $e->getMessage()]);
                echo 'data: '.json_encode([
                    'type' => 'error',
                    'message' => 'voice_stream_failed',
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            }

            echo "data: {\"type\":\"stream_end\"}\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
