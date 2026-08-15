<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\Voice\VoiceCallMessageRole;
use App\Enums\Voice\VoiceCallStatus;
use App\Enums\Voice\VoiceInteractionMode;
use App\Enums\Voice\VoiceProfile;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallMessage;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Services\Voice\Providers\FakeVoiceProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoiceCallService
{
    public function __construct(
        protected VoiceManager $voiceManager,
        protected VoiceBotService $voiceBotService,
        protected AiChatbotInstanceService $instanceService,
    ) {}

    public function startSimulatedCall(
        User $user,
        ChatbotInstance $instance,
        ?string $callerNumber = null,
        VoiceInteractionMode $interactionMode = VoiceInteractionMode::Text,
        VoiceProfile $voiceProfile = VoiceProfile::Woman,
        ?int $conversationId = null,
    ): VoiceCall {
        $this->instanceService->authorizeForUser($instance, $user);

        if ($conversationId !== null) {
            $conversation = \App\Models\AiChatbot\ChatbotConversation::query()
                ->where('id', $conversationId)
                ->where('user_id', $user->id)
                ->where('instance_id', $instance->id)
                ->first();

            if ($conversation === null) {
                throw new RuntimeException(__('voice.errors.conversation_not_found'));
            }
        }

        $provider = $this->voiceManager->current();
        $providerCallId = FakeVoiceProvider::generateCallId();

        $call = VoiceCall::query()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => $provider->name(),
            'provider_call_id' => $providerCallId,
            'caller_number' => $callerNumber,
            'called_number' => null,
            'status' => VoiceCallStatus::Ringing,
            'chatbot_conversation_id' => $conversationId,
            'started_at' => now(),
            'metadata' => [
                'simulated' => true,
                'interaction_mode' => $interactionMode->value,
                'voice_profile' => $voiceProfile->value,
                'voice_synthesis' => $voiceProfile->synthesis(),
                'source' => 'chat',
            ],
        ]);

        $answerResult = $provider->answerCall($providerCallId, [
            'voice_call_id' => $call->id,
        ]);

        return $this->markActive($call, $answerResult);
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    public function markActive(VoiceCall $call, array $providerResult = []): VoiceCall
    {
        $call->fill([
            'status' => VoiceCallStatus::Active,
            'answered_at' => now(),
        ]);

        $metadata = $call->metadata ?? [];
        $metadata['provider_answer'] = $providerResult;
        $call->metadata = $metadata;
        $call->save();

        return $call->fresh();
    }

    /**
     * @return array{caller_message: VoiceCallMessage, assistant_message: VoiceCallMessage, assistant_text: string}
     */
    public function sendCallerMessage(VoiceCall $call, User $user, string $text): array
    {
        $this->authorizeCallForUser($call, $user);

        if (! $call->isActive()) {
            throw new RuntimeException(__('voice.errors.call_not_active'));
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new RuntimeException(__('voice.errors.empty_message'));
        }

        $callerMessage = $call->messages()->create([
            'role' => VoiceCallMessageRole::Caller,
            'content' => $trimmed,
        ]);

        $botResult = $this->voiceBotService->processCallerText($call, $trimmed);

        $assistantMessage = $call->messages()->create([
            'role' => VoiceCallMessageRole::Assistant,
            'content' => $botResult['assistant_text'],
            'metadata' => [
                'chatbot_message_id' => $botResult['assistant_message_id'],
            ],
        ]);

        $provider = $this->voiceManager->provider($call->provider);
        $speakResult = $provider->speakText((string) $call->provider_call_id, $botResult['assistant_text'], [
            'voice_call_id' => $call->id,
        ]);

        $metadata = $call->metadata ?? [];
        $metadata['last_speak'] = $speakResult;
        $call->metadata = $metadata;
        $call->save();

        return [
            'caller_message' => $callerMessage,
            'assistant_message' => $assistantMessage,
            'assistant_text' => $botResult['assistant_text'],
        ];
    }

    public function endCall(VoiceCall $call, User $user): VoiceCall
    {
        $this->authorizeCallForUser($call, $user);

        if ($call->isTerminal()) {
            return $call;
        }

        return DB::transaction(function () use ($call): VoiceCall {
            $call->refresh();

            if ($call->isTerminal()) {
                return $call;
            }

            $provider = $call->provider;

            if ($call->provider_call_id && $provider !== 'openai_realtime') {
                $voiceProvider = $this->voiceManager->provider($provider);

                $hangupResult = $voiceProvider->hangUp((string) $call->provider_call_id, [
                    'voice_call_id' => $call->id,
                ]);

                $metadata = $call->metadata ?? [];
                $metadata['provider_hangup'] = $hangupResult;
                $call->metadata = $metadata;
            }

            $endedAt = now();
            $duration = null;

            if ($call->answered_at) {
                $duration = max(0, $call->answered_at->diffInSeconds($endedAt));
            } elseif ($call->started_at) {
                $duration = max(0, $call->started_at->diffInSeconds($endedAt));
            }

            $call->fill([
                'status' => VoiceCallStatus::Completed,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ]);
            $call->save();

            return $call->fresh();
        });
    }

    public function authorizeCallForUser(VoiceCall $call, User $user): void
    {
        if ($call->user_id !== $user->id && ! ($user->is_admin ?? false)) {
            abort(403);
        }
    }
}
