<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

use App\Enums\Voice\VoiceCallStatus;
use App\Enums\Voice\VoiceInteractionMode;
use App\Exceptions\Voice\RealtimeUpstreamException;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\AiChatbotInstanceService;
use App\Support\Voice\RealtimeSdpTracer;
use App\Support\Voice\RealtimeTurnDetectionBuilder;
use App\Support\Voice\RealtimeVoiceResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RealtimeSessionService
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
        protected RealtimeInstructionsBuilder $instructionsBuilder,
        protected RealtimeCallsClient $callsClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createSession(
        User $user,
        ChatbotInstance $instance,
        ?int $conversationId = null,
        bool $isReconnect = false,
    ): array {
        $this->instanceService->authorizeForUser($instance, $user);

        if ($conversationId !== null) {
            $conversation = ChatbotConversation::query()
                ->where('id', $conversationId)
                ->where('user_id', $user->id)
                ->where('instance_id', $instance->id)
                ->first();

            if ($conversation === null) {
                throw new RuntimeException(__('voice.errors.conversation_not_found'));
            }
        }

        $this->assertApiKeyConfigured();

        $locale = (string) app()->getLocale();
        $model = $this->realtimeModel();
        $voice = $this->realtimeVoice();

        $call = $this->createOrReuseCall($user, $instance, $conversationId, $model, $voice, $isReconnect);

        $metadata = $call->metadata ?? [];
        $metadata['realtime'] = [
            'interaction_mode' => VoiceInteractionMode::Phone->value,
            'source' => 'realtime_webrtc_unified',
            'is_reconnect' => $isReconnect,
            'protocol' => 'ga_calls',
        ];
        $call->metadata = $metadata;
        $call->save();

        return [
            'voice_call_id' => $call->id,
            'conversation_id' => $call->chatbot_conversation_id,
            'model' => $model,
            'voice' => $voice,
            'protocol' => 'ga_calls',
            'webrtc_url' => route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]),
            'events_url' => route('ai-chatbot.instances.voice.realtime.events', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]),
            'tools_url' => route('ai-chatbot.instances.voice.realtime.tools', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]),
            'end_url' => route('ai-chatbot.instances.voice.realtime.end', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]),
            'metrics_url' => route('ai-chatbot.instances.voice.realtime.metrics', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]),
            'play_greeting' => $call->greeting_played_at === null && ! $isReconnect,
            'opening_greeting' => $this->instructionsBuilder->openingGreeting($locale),
            'opening_greeting_instructions' => $this->instructionsBuilder->openingGreetingInstructions($locale),
            'diagnostics_enabled' => (bool) config('app.debug'),
        ];
    }

    /**
     * Proxy browser WebRTC SDP to OpenAI GA unified /realtime/calls endpoint.
     */
    public function connectWebRtc(ChatbotInstance $instance, VoiceCall $voiceCall, string $sdp): string
    {
        $sdp = $this->normalizeOfferSdp($sdp);
        RealtimeSdpTracer::trace('C_service_after_normalize', $sdp, [
            'voice_call_id' => $voiceCall->id,
        ]);

        $locale = (string) app()->getLocale();
        $sessionConfig = $this->buildRealtimeSessionConfig($instance, $locale);
        $baseUrl = rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/');

        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);
        $organization = config('openai.organization');
        $organization = is_string($organization) ? $organization : null;

        $response = $this->callsClient->exchangeSdp(
            $baseUrl,
            $apiKey,
            $sdp,
            $sessionConfig,
            $sslVerify,
            $organization,
            (int) config('voice.realtime.timeout', 30),
        );

        if (! $response->successful()) {
            Log::warning('OpenAI realtime WebRTC connect failed', [
                'voice_call_id' => $voiceCall->id,
                'instance_id' => $instance->id,
                'model' => $sessionConfig['model'] ?? null,
                'status' => $response->status,
                'body' => $response->body,
            ]);

            throw new RealtimeUpstreamException(
                __('voice.realtime.errors.webrtc_failed'),
                $response->status,
                $response->body,
            );
        }

        $answerSdp = $response->body;
        if ($answerSdp === '' || ! str_starts_with($answerSdp, 'v=0')) {
            throw new RealtimeUpstreamException(
                __('voice.realtime.errors.webrtc_failed'),
                $response->status,
                $response->body,
            );
        }

        $metadata = $voiceCall->metadata ?? [];
        if (! is_array($metadata['realtime'] ?? null)) {
            $metadata['realtime'] = [];
        }
        $metadata['realtime']['connected_at'] = now()->toIso8601String();
        $voiceCall->metadata = $metadata;
        $voiceCall->save();

        return $answerSdp;
    }

    public function normalizeOfferSdp(string $sdp): string
    {
        $sdp = str_replace(["\r\n", "\r"], "\n", ltrim($sdp));
        $sdp = rtrim($sdp, " \t");
        $sdp = str_replace("\n", "\r\n", $sdp);

        if (! str_ends_with($sdp, "\r\n")) {
            $sdp .= "\r\n";
        }

        if ($sdp === '' || ! str_starts_with($sdp, 'v=0')) {
            throw ValidationException::withMessages([
                'sdp' => [__('voice.realtime.errors.invalid_sdp')],
            ]);
        }

        if (! preg_match('/\r\nm=audio /', $sdp)) {
            throw ValidationException::withMessages([
                'sdp' => [__('voice.realtime.errors.invalid_sdp')],
            ]);
        }

        if (! preg_match('/\r\na=fingerprint:/', $sdp) || ! preg_match('/\r\na=ice-ufrag:/', $sdp)) {
            throw ValidationException::withMessages([
                'sdp' => [__('voice.realtime.errors.invalid_sdp')],
            ]);
        }

        if (strlen($sdp) < 200) {
            throw ValidationException::withMessages([
                'sdp' => [__('voice.realtime.errors.invalid_sdp')],
            ]);
        }

        return $sdp;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRealtimeSessionConfig(ChatbotInstance $instance, string $locale): array
    {
        return [
            'type' => 'realtime',
            'model' => $this->realtimeModel(),
            'instructions' => $this->instructionsBuilder->build($instance, $locale),
            'output_modalities' => ['audio'],
            'tools' => $this->toolDefinitions($instance),
            'audio' => [
                'input' => [
                    'transcription' => [
                        'model' => (string) config('voice.realtime.transcription_model', 'whisper-1'),
                    ],
                    'turn_detection' => RealtimeTurnDetectionBuilder::build(),
                ],
                'output' => [
                    'voice' => $this->realtimeVoice(),
                ],
            ],
        ];
    }

    private function realtimeModel(): string
    {
        return (string) config('voice.realtime.model', 'gpt-realtime');
    }

    private function realtimeVoice(): string
    {
        return RealtimeVoiceResolver::resolve((string) config('voice.realtime.voice', 'marin'));
    }

    private function assertApiKeyConfigured(): void
    {
        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        if ($apiKey === '') {
            throw new RuntimeException(__('voice.realtime.errors.api_key_missing'));
        }
    }

    private function createOrReuseCall(
        User $user,
        ChatbotInstance $instance,
        ?int $conversationId,
        string $model,
        string $voice,
        bool $isReconnect,
    ): VoiceCall {
        if ($isReconnect && $conversationId !== null) {
            $existing = VoiceCall::query()
                ->where('user_id', $user->id)
                ->where('chatbot_instance_id', $instance->id)
                ->where('chatbot_conversation_id', $conversationId)
                ->where('status', VoiceCallStatus::Active)
                ->latest('id')
                ->first();

            if ($existing) {
                $existing->realtime_model = $model;
                $existing->realtime_voice = $voice;
                $existing->save();

                return $existing;
            }
        }

        return VoiceCall::query()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'provider_call_id' => 'rt_'.bin2hex(random_bytes(8)),
            'status' => VoiceCallStatus::Active,
            'chatbot_conversation_id' => $conversationId,
            'started_at' => now(),
            'answered_at' => now(),
            'realtime_model' => $model,
            'realtime_voice' => $voice,
            'metadata' => [
                'simulated' => true,
                'interaction_mode' => VoiceInteractionMode::Phone->value,
                'source' => 'realtime_webrtc_unified',
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(ChatbotInstance $instance): array
    {
        $tools = [
            [
                'type' => 'function',
                'name' => 'lookup_customer',
                'description' => 'Look up a customer record by phone or identity. Only call when the caller provides an identifier.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'identifier' => ['type' => 'string', 'description' => 'Phone number or identity ID'],
                    ],
                    'required' => ['identifier'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'create_lead',
                'description' => 'Create a sales lead for follow-up.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'create_support_ticket',
                'description' => 'Open a technical support ticket.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    ],
                    'required' => ['summary'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'escalate_to_human',
                'description' => 'Transfer the caller to a human agent when requested or when automation cannot help.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['reason'],
                ],
            ],
        ];

        if ($instance->hasMalanIntegration()) {
            $tools = array_merge($tools, [
                [
                    'type' => 'function',
                    'name' => 'lookup_malan_customer',
                    'description' => 'Look up a Malan Internet customer by registered phone or identity for outage/account status. Prefer this over lookup_customer for Melan.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'lookup_type' => ['type' => 'string', 'enum' => ['phone', 'identity']],
                            'value' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['internet_outage', 'account_status', 'debt_payment']],
                        ],
                        'required' => ['lookup_type', 'value', 'reason'],
                    ],
                ],
                [
                    'type' => 'function',
                    'name' => 'create_malan_support_report',
                    'description' => 'Create Malan support report ONLY after showing a draft task summary and getting explicit customer approval. Pass confirmed_by_customer=true only after approval.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'issue_type' => ['type' => 'string', 'enum' => ['full_outage']],
                            'summary' => ['type' => 'string'],
                            'confirmed_by_customer' => ['type' => 'boolean'],
                        ],
                        'required' => ['issue_type', 'summary', 'confirmed_by_customer'],
                    ],
                ],
                [
                    'type' => 'function',
                    'name' => 'set_malan_payment_method_preference',
                    'description' => 'Record bank_transfer or visa choice. For bank_transfer, tell caller to send proof on WhatsApp.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'payment_method' => ['type' => 'string', 'enum' => ['bank_transfer', 'visa_saved', 'visa_other']],
                        ],
                        'required' => ['payment_method'],
                    ],
                ],
                [
                    'type' => 'function',
                    'name' => 'charge_malan_saved_payment_method',
                    'description' => 'Charge saved card using verified server context only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'confirmed_by_customer' => ['type' => 'boolean'],
                        ],
                        'required' => ['confirmed_by_customer'],
                    ],
                ],
                [
                    'type' => 'function',
                    'name' => 'create_malan_one_time_payment_link',
                    'description' => 'Create hosted one-time payment link. Never collect card details by voice.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'confirmed_by_customer' => ['type' => 'boolean'],
                            'delivery_channel' => ['type' => 'string', 'enum' => ['whatsapp', 'web', 'voice']],
                        ],
                        'required' => ['confirmed_by_customer', 'delivery_channel'],
                    ],
                ],
                [
                    'type' => 'function',
                    'name' => 'request_malan_service_reactivation',
                    'description' => 'Request reactivation after payment verification. May return integration_pending.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['reason'],
                    ],
                ],
            ]);
        }

        return $tools;
    }
}
