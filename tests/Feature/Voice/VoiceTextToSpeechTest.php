<?php

namespace Tests\Feature\Voice;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceTextToSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
    }

    private function chatbotUser(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function instanceFor(User $user): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'name' => 'Support Bot',
        ]);
    }

    public function test_auto_provider_uses_openai_for_arabic_speech(): void
    {
        config([
            'voice.tts.provider' => 'auto',
            'voice.tts.arabic_diacritize' => false,
            'openai.api_key' => 'test-key',
            'voice.tts.elevenlabs.api_key' => null,
            'voice.tts.telnyx.api_key' => null,
            'voice.providers.telnyx.api_key' => null,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(str_repeat('x', 128), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $response = $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحباً 😊 كيف يمكنني مساعدتك؟',
                'voice_profile' => 'woman',
                'locale' => 'ar-SA',
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'api.openai.com/v1/audio/speech')
                && ($body['voice'] ?? null) === 'coral'
                && ($body['model'] ?? null) === 'gpt-4o-mini-tts'
                && str_contains((string) ($body['input'] ?? ''), 'مرحباً')
                && ! str_contains((string) ($body['input'] ?? ''), '😊');
        });
    }

    public function test_elevenlabs_provider_uses_rafoush_settings_for_arabic_speech(): void
    {
        config([
            'voice.tts.provider' => 'elevenlabs',
            'voice.tts.arabic_diacritize' => false,
            'voice.tts.elevenlabs.api_key' => 'el-test-key',
            'voice.tts.elevenlabs.model' => 'eleven_multilingual_v2',
            'voice.tts.elevenlabs.stability' => 0.5,
            'voice.tts.elevenlabs.similarity_boost' => 0.75,
            'voice.tts.elevenlabs.style' => 0.0,
            'voice.tts.elevenlabs.speed' => 1.0,
            'voice.tts.elevenlabs.optimize_streaming_latency' => 0,
            'voice.tts.elevenlabs.voices.ar.woman' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.man' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.girl' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.boy' => 'rafoush-voice-id',
        ]);

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('e', 128), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $response = $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحباً 😊 كيف يمكنني مساعدتك؟',
                'voice_profile' => 'woman',
                'locale' => 'ar-SA',
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(function ($request) {
            $body = $request->data();
            $settings = $body['voice_settings'] ?? [];

            return str_contains($request->url(), 'api.elevenlabs.io/v1/text-to-speech/rafoush-voice-id')
                && ! str_contains($request->url(), 'optimize_streaming_latency=')
                && ($request->header('xi-api-key')[0] ?? null) === 'el-test-key'
                && ($body['model_id'] ?? null) === 'eleven_multilingual_v2'
                && str_contains((string) ($body['text'] ?? ''), 'مرحباً')
                && ! str_contains((string) ($body['text'] ?? ''), '😊')
                && (float) ($settings['stability'] ?? -1) === 0.5
                && (float) ($settings['similarity_boost'] ?? -1) === 0.75
                && (float) ($settings['style'] ?? -1) === 0.0
                && (float) ($settings['speed'] ?? -1) === 1.0;
        });
    }

    public function test_auto_provider_prefers_openai_for_arabic_when_configured(): void
    {
        config([
            'voice.tts.provider' => 'auto',
            'voice.tts.arabic_diacritize' => false,
            'openai.api_key' => 'test-key',
            'voice.tts.elevenlabs.api_key' => 'el-test-key',
            'voice.tts.telnyx.api_key' => null,
            'voice.providers.telnyx.api_key' => null,
            'voice.tts.elevenlabs.voices.ar.woman' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.man' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.girl' => 'rafoush-voice-id',
            'voice.tts.elevenlabs.voices.ar.boy' => 'rafoush-voice-id',
        ]);

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(str_repeat('e', 64), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
            'api.openai.com/*' => Http::response(str_repeat('x', 64), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحباً',
                'voice_profile' => 'woman',
                'locale' => 'ar-SA',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'api.openai.com/v1/audio/speech')
                && ($body['voice'] ?? null) === 'coral'
                && str_contains((string) ($body['instructions'] ?? ''), 'Palestinian');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.elevenlabs.io/v1/text-to-speech/'));
    }

    public function test_empty_text_after_emoji_removal_returns_validation_error(): void
    {
        config(['voice.tts.provider' => 'openai', 'openai.api_key' => 'test-key']);

        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => '😀👍',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('voice.errors.tts_empty'));
    }

    public function test_browser_provider_returns_use_browser_flag(): void
    {
        config(['voice.tts.provider' => 'browser']);

        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'Hello',
            ])
            ->assertOk()
            ->assertJson(['use_browser' => true]);
    }

    public function test_stranger_cannot_synthesize_speech(): void
    {
        $owner = $this->chatbotUser();
        $stranger = User::factory()->create([
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);
        $instance = $this->instanceFor($owner);

        $this->actingAs($stranger)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحباً',
            ])
            ->assertForbidden();
    }
}
