<?php

declare(strict_types=1);

namespace App\Services\Voice\Telnyx;

use App\Enums\Voice\VoiceCallMessageRole;
use App\Enums\Voice\VoiceCallStatus;
use App\Enums\Voice\VoiceProviderName;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallMessage;
use Illuminate\Support\Facades\Log;

class TelnyxWebhookService
{
    public function __construct(
        protected TelnyxEventParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{duplicate: bool, handled: bool, event_type: string|null}
     */
    public function handle(array $payload): array
    {
        $event = $this->parser->parse($payload);
        $eventType = $event['event_type'];
        $eventId = $event['event_id'];

        if ($eventId !== null && VoiceCallMessage::query()->where('provider_event_id', $eventId)->exists()) {
            return [
                'duplicate' => true,
                'handled' => true,
                'event_type' => $eventType,
            ];
        }

        $call = $this->findOrCreateCall($event);

        if ($call !== null && $eventId !== null && $call->hasProcessedEvent($eventId)) {
            return [
                'duplicate' => true,
                'handled' => true,
                'event_type' => $eventType,
            ];
        }

        $handled = match ($eventType) {
            'call.initiated' => $this->handleInitiated($call, $event),
            'call.answered' => $this->handleAnswered($call, $event),
            'call.hangup' => $this->handleHangup($call, $event),
            default => false,
        };

        if ($call !== null && $eventId !== null) {
            $call->markEventProcessed($eventId);
        }

        if ($eventId !== null && $handled) {
            $this->storeSystemEvent($call, $eventId, $eventType ?? 'unknown', $event);
        }

        Log::info('Telnyx voice webhook received', [
            'event_type' => $eventType,
            'provider_call_id' => $event['provider_call_id'],
            'handled' => $handled,
        ]);

        return [
            'duplicate' => false,
            'handled' => $handled,
            'event_type' => $eventType,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleInitiated(?VoiceCall $call, array $event): bool
    {
        if ($call === null) {
            return false;
        }

        if ($call->statusEnum() === VoiceCallStatus::Pending) {
            $call->update([
                'status' => VoiceCallStatus::Ringing,
                'caller_number' => $event['caller_number'] ?? $call->caller_number,
                'called_number' => $event['called_number'] ?? $call->called_number,
                'started_at' => $call->started_at ?? now(),
            ]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleAnswered(?VoiceCall $call, array $event): bool
    {
        if ($call === null) {
            return false;
        }

        $call->update([
            'status' => VoiceCallStatus::Active,
            'answered_at' => now(),
            'caller_number' => $event['caller_number'] ?? $call->caller_number,
            'called_number' => $event['called_number'] ?? $call->called_number,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleHangup(?VoiceCall $call, array $event): bool
    {
        if ($call === null) {
            return false;
        }

        if ($call->isTerminal()) {
            return true;
        }

        $endedAt = now();
        $duration = null;

        if ($call->answered_at) {
            $duration = max(0, $call->answered_at->diffInSeconds($endedAt));
        } elseif ($call->started_at) {
            $duration = max(0, $call->started_at->diffInSeconds($endedAt));
        }

        $call->update([
            'status' => VoiceCallStatus::Completed,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function findOrCreateCall(array $event): ?VoiceCall
    {
        $providerCallId = $event['provider_call_id'];

        if ($providerCallId === null) {
            return null;
        }

        $existing = VoiceCall::query()
            ->where('provider', VoiceProviderName::Telnyx->value)
            ->where('provider_call_id', $providerCallId)
            ->first();

        if ($existing) {
            return $existing;
        }

        if (($event['event_type'] ?? null) !== 'call.initiated') {
            return null;
        }

        $defaultInstanceId = config('voice.default_chatbot_instance_id');

        if (blank($defaultInstanceId)) {
            Log::warning('Telnyx call.initiated received but VOICE_DEFAULT_CHATBOT_INSTANCE_ID is not set.');

            return null;
        }

        $instance = ChatbotInstance::query()->find((int) $defaultInstanceId);

        if ($instance === null) {
            Log::warning('Telnyx call.initiated received but default chatbot instance was not found.', [
                'instance_id' => $defaultInstanceId,
            ]);

            return null;
        }

        return VoiceCall::query()->create([
            'user_id' => $instance->user_id,
            'chatbot_instance_id' => $instance->id,
            'provider' => VoiceProviderName::Telnyx->value,
            'provider_call_id' => $providerCallId,
            'caller_number' => $event['caller_number'],
            'called_number' => $event['called_number'],
            'status' => VoiceCallStatus::Pending,
            'started_at' => now(),
            'metadata' => [
                'source' => 'telnyx_webhook',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function storeSystemEvent(?VoiceCall $call, string $eventId, string $eventType, array $event): void
    {
        if ($call === null) {
            return;
        }

        VoiceCallMessage::query()->create([
            'voice_call_id' => $call->id,
            'role' => VoiceCallMessageRole::System,
            'content' => "Telnyx event: {$eventType}",
            'provider_event_id' => $eventId,
            'metadata' => [
                'event_type' => $eventType,
                'occurred_at' => $event['occurred_at'] ?? null,
            ],
        ]);
    }
}
