<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

use App\Enums\Voice\VoiceCallMessageRole;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceCallMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeTranscriptService
{
    public function recordMetric(VoiceCall $call, string $metricKey, ?array $payload = null): VoiceCallEvent
    {
        return VoiceCallEvent::query()->create([
            'voice_call_id' => $call->id,
            'event_type' => 'metric',
            'metric_key' => $metricKey,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }

    public function recordTranscript(
        VoiceCall $call,
        string $role,
        string $content,
        ?array $payload = null,
    ): ?array {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        VoiceCallEvent::query()->create([
            'voice_call_id' => $call->id,
            'event_type' => 'transcript',
            'metric_key' => $role,
            'occurred_at' => now(),
            'payload' => array_merge($payload ?? [], ['content' => $content]),
        ]);

        $messageRole = match ($role) {
            'user', 'caller' => VoiceCallMessageRole::Caller,
            'assistant' => VoiceCallMessageRole::Assistant,
            default => VoiceCallMessageRole::System,
        };

        $call->messages()->create([
            'role' => $messageRole,
            'content' => $content,
            'metadata' => $payload,
        ]);

        $chatbotMessage = $this->syncChatbotMessage($call, $messageRole, $content);
        if ($chatbotMessage === null) {
            return null;
        }

        return [
            'role' => $role,
            'conversation_id' => $chatbotMessage->conversation_id,
            'message_html' => view('ai-chatbot.partials.message', [
                'message' => $chatbotMessage,
            ])->render(),
        ];
    }

    public function markGreetingPlayed(VoiceCall $call): void
    {
        if ($call->greeting_played_at === null) {
            $call->greeting_played_at = now();
            $call->save();
        }
    }

    public function incrementInterruption(VoiceCall $call): void
    {
        $call->increment('interruption_count');
        $this->recordMetric($call, 'interruption_detected');
    }

    public function generateSummary(VoiceCall $call, User $user): ?string
    {
        if ($call->conversation_summary) {
            return $call->conversation_summary;
        }

        $transcript = $call->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (VoiceCallMessage $message) => strtoupper($message->role->value).': '.$message->content)
            ->implode("\n");

        if ($transcript === '') {
            return null;
        }

        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        if ($apiKey === '') {
            return null;
        }

        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);
        $http = Http::withToken($apiKey)->timeout(20)->acceptJson();
        if (! $sslVerify) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('openai.default_model', 'gpt-4o-mini'),
                'temperature' => 0.3,
                'max_tokens' => 300,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Summarize this phone call transcript in 2-3 sentences. Use the same language as the conversation.',
                    ],
                    ['role' => 'user', 'content' => $transcript],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Voice call summary generation failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $summary = trim((string) ($response->json('choices.0.message.content') ?? ''));
        if ($summary !== '') {
            $call->conversation_summary = $summary;
            $call->save();
        }

        return $summary ?: null;
    }

    private function syncChatbotMessage(VoiceCall $call, VoiceCallMessageRole $role, string $content): ?ChatbotMessage
    {
        if (! in_array($role, [VoiceCallMessageRole::Caller, VoiceCallMessageRole::Assistant], true)) {
            return null;
        }

        $conversation = $this->ensureConversation($call);
        if ($conversation === null) {
            return null;
        }

        $chatbotMessage = ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => $role === VoiceCallMessageRole::Caller ? 'user' : 'assistant',
            'message' => $content,
        ]);

        if ($call->chatbot_conversation_id === null) {
            $call->chatbot_conversation_id = $conversation->id;
            $call->save();
        }

        return $chatbotMessage;
    }

    private function ensureConversation(VoiceCall $call): ?ChatbotConversation
    {
        if ($call->chatbot_conversation_id) {
            return ChatbotConversation::query()->find($call->chatbot_conversation_id);
        }

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $call->user_id,
            'instance_id' => $call->chatbot_instance_id,
            'title' => 'Voice call',
        ]);

        $call->chatbot_conversation_id = $conversation->id;
        $call->save();

        return $conversation;
    }
}
