<?php

namespace Tests\Feature\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Enums\Voice\VoiceProviderName;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallMessage;
use App\Services\Voice\Providers\TelnyxVoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        config([
            'voice.providers.telnyx.api_key' => null,
            'voice.providers.telnyx.connection_id' => null,
            'voice.providers.telnyx.phone_number' => null,
            'voice.providers.telnyx.public_key' => null,
            'voice.providers.telnyx.webhook_verify' => true,
            'voice.providers.telnyx.webhook_verify_bypass' => false,
        ]);
    }

    public function test_health_endpoint_does_not_expose_secrets(): void
    {
        config([
            'voice.providers.telnyx.api_key' => 'secret-key-value',
            'voice.providers.telnyx.connection_id' => 'conn-123',
        ]);

        $response = $this->getJson('/api/voice/telnyx/health');

        $response->assertOk()
            ->assertJsonPath('provider', 'telnyx')
            ->assertJsonStructure([
                'success',
                'provider',
                'configured',
                'webhook_ready',
                'missing_configuration',
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-key-value', (string) $body);
        $this->assertStringNotContainsString('conn-123', (string) $body);
    }

    public function test_unconfigured_telnyx_provider_does_not_make_http_requests(): void
    {
        $provider = app(TelnyxVoiceProvider::class);

        $result = $provider->answerCall('v3:test-call-id');

        $this->assertFalse($result['success']);
        $this->assertSame('provider_not_configured', $result['error']);
        Http::assertNothingSent();
    }

    public function test_duplicate_provider_event_ids_do_not_create_duplicate_records(): void
    {
        $user = User::factory()->create();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => VoiceProviderName::Telnyx->value,
            'provider_call_id' => 'v3:abc123',
            'status' => VoiceCallStatus::Ringing,
        ]);

        config([
            'voice.providers.telnyx.webhook_verify' => false,
        ]);

        $payload = [
            'data' => [
                'id' => 'evt_duplicate_1',
                'event_type' => 'call.hangup',
                'occurred_at' => now()->toIso8601String(),
                'payload' => [
                    'call_control_id' => 'v3:abc123',
                ],
            ],
        ];

        $this->postJson('/api/voice/telnyx/webhook', $payload)->assertOk();
        $this->postJson('/api/voice/telnyx/webhook', $payload)->assertOk();

        $this->assertSame(1, VoiceCallMessage::query()
            ->where('provider_event_id', 'evt_duplicate_1')
            ->count());
    }

    public function test_webhook_rejects_when_verification_required_and_public_key_missing_outside_testing_bypass(): void
    {
        config([
            'app.env' => 'production',
            'voice.providers.telnyx.webhook_verify' => true,
            'voice.providers.telnyx.public_key' => null,
            'voice.providers.telnyx.webhook_verify_bypass' => false,
        ]);

        $this->postJson('/api/voice/telnyx/webhook', [
            'data' => [
                'id' => 'evt_1',
                'event_type' => 'call.initiated',
                'payload' => ['call_control_id' => 'v3:new'],
            ],
        ])->assertForbidden();
    }
}
