<?php

namespace Tests\Feature\Voice;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Services\Voice\Realtime\RealtimeCallsClient;
use App\Services\Voice\Realtime\RealtimeCallsResponse;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);

        config([
            'voice.realtime.model' => 'gpt-realtime',
            'voice.realtime.voice' => 'marin',
            'voice.realtime.diagnostics.ab_curl' => false,
        ]);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function instanceFor(User $user, string $prompt = 'You help Melan Internet customers.'): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'name' => 'Melan Bot',
            'system_prompt' => $prompt,
        ]);
    }

    private function validOfferSdp(): string
    {
        return "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"
            ."a=ice-ufrag:abcd\r\n"
            ."a=ice-pwd:abcdefghijklmnopqrstuvwxyz123456\r\n"
            ."a=fingerprint:sha-256 00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF\r\n"
            ."a=setup:actpass\r\n"
            ."a=rtpmap:111 opus/48000/2\r\n";
    }

    public function test_authorized_user_can_create_realtime_session_without_exposing_api_key(): void
    {
        config(['openai.api_key' => 'sk-live-secret']);

        $user = $this->user();
        $instance = $this->instanceFor($user);

        $response = $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.session', $instance), [
                'conversation_id' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('model', 'gpt-realtime');
        $response->assertJsonPath('protocol', 'ga_calls');
        $response->assertJsonMissingPath('client_secret');
        $response->assertJsonStructure(['voice_call_id', 'webrtc_url', 'events_url', 'tools_url', 'end_url']);

        $voiceCallId = $response->json('voice_call_id');
        $response->assertJsonPath(
            'webrtc_url',
            route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $voiceCallId,
            ]),
        );

        $this->assertStringNotContainsString('sk-live-secret', json_encode($response->json()));

        $this->assertDatabaseHas('voice_calls', [
            'id' => $voiceCallId,
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'realtime_model' => 'gpt-realtime',
        ]);

        Http::assertNothingSent();
    }

    public function test_connect_returns_sdp_answer_for_authorized_tenant_call(): void
    {
        config(['openai.api_key' => 'test-key', 'app.debug' => true]);

        $user = $this->user();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $offerSdp = $this->validOfferSdp();
        $answerSdp = "v=0\r\no=- 0 0 IN IP4 0.0.0.0\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";

        $this->mock(RealtimeCallsClient::class, function ($mock) use ($answerSdp) {
            $mock->shouldReceive('exchangeSdp')
                ->once()
                ->andReturn(new RealtimeCallsResponse(201, $answerSdp, 'application/sdp'));
        });

        $response = $this->actingAs($user)
            ->postJson(
                route('ai-chatbot.instances.voice.realtime.connect', [
                    'instance' => $instance,
                    'voiceCall' => $call,
                ]),
                ['sdp' => $offerSdp],
                ['Accept' => 'application/sdp'],
            );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/sdp');
        $this->assertSame($answerSdp, $response->getContent());
    }

    public function test_connect_rejects_missing_sdp_with_422(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sdp']);
    }

    public function test_connect_rejects_empty_sdp_with_422(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), ['sdp' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sdp']);
    }

    public function test_connect_rejects_cross_tenant_call_with_404(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);
        $other = $this->instanceFor($user, 'Other tenant');

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $other,
                'voiceCall' => $call,
            ]), ['sdp' => $this->validOfferSdp()])
            ->assertNotFound();
    }

    public function test_connect_rejects_foreign_user_call_with_403(): void
    {
        $owner = $this->user();
        $stranger = User::factory()->create(['is_admin' => false, 'projects' => ['ai-chatbot']]);
        $instance = $this->instanceFor($owner);

        $call = VoiceCall::factory()->create([
            'user_id' => $owner->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($stranger)
            ->postJson(route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), ['sdp' => $this->validOfferSdp()])
            ->assertForbidden();
    }

    public function test_connect_maps_openai_validation_error_to_502(): void
    {
        config(['openai.api_key' => 'test-key', 'app.debug' => true]);

        $user = $this->user();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->mock(RealtimeCallsClient::class, function ($mock) {
            $mock->shouldReceive('exchangeSdp')
                ->once()
                ->andReturn(new RealtimeCallsResponse(
                    400,
                    json_encode([
                        'error' => [
                            'message' => 'Failed to parse offer: failed to unmarshal SDP: EOF',
                            'type' => 'invalid_request_error',
                        ],
                    ]),
                    'application/json',
                ));
        });

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.connect', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), ['sdp' => $this->validOfferSdp()])
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 400)
            ->assertJsonPath('upstream_error', 'Failed to parse offer: failed to unmarshal SDP: EOF')
            ->assertJsonMissing(['upstream_body' => 'sk-']);
    }

    public function test_unauthorized_user_cannot_create_realtime_session(): void
    {
        $owner = $this->user();
        $stranger = User::factory()->create(['is_admin' => false, 'projects' => ['ai-chatbot']]);
        $instance = $this->instanceFor($owner);

        $this->actingAs($stranger)
            ->postJson(route('ai-chatbot.instances.voice.realtime.session', $instance))
            ->assertForbidden();
    }

    public function test_call_can_end_and_store_summary(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
            'started_at' => now()->subMinute(),
            'answered_at' => now()->subMinute(),
        ]);

        $call->messages()->create(['role' => 'caller', 'content' => 'مرحبا']);
        $call->messages()->create(['role' => 'assistant', 'content' => 'أهلاً، كيف أساعدك؟']);

        config(['openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Customer greeted the assistant.']]],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.end', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]))
            ->assertOk()
            ->assertJsonPath('voice_call.status', 'completed');

        $call->refresh();
        $this->assertNotNull($call->ended_at);
        $this->assertNotNull($call->conversation_summary);
    }

    public function test_greeting_is_not_replayed_on_reconnect(): void
    {
        config(['openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = $this->instanceFor($user);

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
            'greeting_played_at' => now(),
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.session', $instance), [
                'conversation_id' => $call->chatbot_conversation_id,
                'reconnect' => true,
            ])
            ->assertOk()
            ->assertJsonPath('play_greeting', false);
    }

    public function test_tool_calls_are_tenant_scoped(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);
        $other = $this->instanceFor($user, 'Other tenant');

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.tools', [
                'instance' => $other,
                'voiceCall' => $call,
            ]), [
                'call_id' => (string) $call->id,
                'tool_name' => 'lookup_customer',
                'arguments' => ['identifier' => '0599'],
            ])
            ->assertNotFound();
    }

    public function test_transcript_events_are_persisted(): void
    {
        $user = $this->user();
        $instance = $this->instanceFor($user);

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'openai_realtime',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.realtime.events', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), [
                'events' => [
                    ['type' => 'transcript', 'role' => 'user', 'content' => 'مرحبا'],
                    ['type' => 'transcript', 'role' => 'assistant', 'content' => 'أهلاً'],
                    ['type' => 'greeting_played'],
                    ['type' => 'interruption'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonCount(2, 'messages');

        $this->assertDatabaseHas('voice_call_messages', [
            'voice_call_id' => $call->id,
            'content' => 'مرحبا',
        ]);

        $call->refresh();
        $this->assertNotNull($call->chatbot_conversation_id);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $call->chatbot_conversation_id,
            'role' => 'user',
            'message' => 'مرحبا',
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $call->chatbot_conversation_id,
            'role' => 'assistant',
            'message' => 'أهلاً',
        ]);
        $this->assertNotNull($call->greeting_played_at);
        $this->assertSame(1, $call->interruption_count);
    }

    public function test_tts_endpoint_remains_available_as_fallback(): void
    {
        config([
            'voice.tts.provider' => 'openai',
            'openai.api_key' => 'test-key',
        ]);

        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response(str_repeat('x', 64), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $user = $this->user();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحباً',
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
    }
}
