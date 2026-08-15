<?php

namespace Tests\Unit\Voice;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\Voice\Realtime\RealtimeInstructionsBuilder;
use App\Services\Voice\Realtime\RealtimeSessionService;
use App\Support\Voice\RealtimeTurnDetectionBuilder;
use App\Support\Voice\RealtimeVoiceResolver;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_normalize_offer_sdp_preserves_required_trailing_crlf(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'system_prompt' => 'Help customers.',
        ]);

        $service = app(RealtimeSessionService::class);
        $offer = "v=0\no=- 1 2 IN IP4 127.0.0.1\ns=-\nt=0 0\nm=audio 9 UDP/TLS/RTP/SAVPF 111\n"
            ."a=ice-ufrag:abcd\n"
            ."a=ice-pwd:abcdefghijklmnopqrstuvwxyz123456\n"
            ."a=fingerprint:sha-256 00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF\n"
            ."a=rtpmap:111 opus/48000/2\n";

        $normalized = $service->normalizeOfferSdp($offer);

        $this->assertStringStartsWith("v=0\r\n", $normalized);
        $this->assertStringEndsWith("\r\n", $normalized);
        $this->assertGreaterThanOrEqual(200, strlen($normalized));
    }

    public function test_build_realtime_session_config_uses_resolved_voice_and_server_vad(): void
    {
        config([
            'voice.realtime.voice' => 'marin',
            'voice.realtime.vad.type' => 'server_vad',
            'voice.realtime.vad.silence_duration_ms' => 750,
        ]);

        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'system_prompt' => 'Melan Internet pricing rules.',
        ]);

        $service = app(RealtimeSessionService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRealtimeSessionConfig');
        $method->setAccessible(true);
        $config = $method->invoke($service, $instance, 'ar');

        $this->assertSame('marin', $config['audio']['output']['voice']);
        $this->assertSame('server_vad', $config['audio']['input']['turn_detection']['type']);
        $this->assertSame(750, $config['audio']['input']['turn_detection']['silence_duration_ms']);
        $this->assertStringContainsString('[VOICE DELIVERY — HIGHEST PRIORITY]', $config['instructions']);
        $this->assertStringContainsString('Melan Internet pricing rules.', $config['instructions']);
    }

    public function test_unsupported_voice_falls_back_to_marin(): void
    {
        $this->assertSame('marin', RealtimeVoiceResolver::resolve('not-a-real-voice'));
        $this->assertSame('cedar', RealtimeVoiceResolver::resolve('cedar'));
    }

    public function test_semantic_vad_config_is_built_when_enabled(): void
    {
        config([
            'voice.realtime.vad.type' => 'semantic_vad',
            'voice.realtime.vad.eagerness' => 'medium',
        ]);

        $config = RealtimeTurnDetectionBuilder::build();

        $this->assertSame('semantic_vad', $config['type']);
        $this->assertSame('medium', $config['eagerness']);
        $this->assertArrayNotHasKey('threshold', $config);
    }

    public function test_instructions_builder_includes_voice_layer_and_tenant_prompt_without_greeting_repeat(): void
    {
        app()->setLocale('ar');

        $instance = ChatbotInstance::factory()->create([
            'system_prompt' => 'شركة ملان للإنترنت تغطي رام الله والبيرة.',
        ]);

        $builder = app(RealtimeInstructionsBuilder::class);
        $instructions = $builder->build($instance, 'ar');
        $greeting = $builder->openingGreeting('ar');

        $this->assertStringContainsString('سالي', $instructions);
        $this->assertStringContainsString('[BUSINESS KNOWLEDGE AND RULES]', $instructions);
        $this->assertStringContainsString('شركة ملان للإنترنت', $instructions);
        $this->assertStringContainsString('[TOOL AND TRUTHFULNESS RULES]', $instructions);
        $this->assertStringNotContainsString($greeting, $instructions);

        $greetingInstructions = $builder->openingGreetingInstructions('ar');
        $this->assertStringContainsString($greeting, $greetingInstructions);
        $this->assertStringContainsString('مرة واحدة فقط', $greetingInstructions);
    }
}
