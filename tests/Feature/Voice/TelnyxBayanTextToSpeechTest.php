<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxBayanTextToSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_auto_arabic_prefers_telnyx_bayan_yara(): void
    {
        config([
            'voice.tts.provider' => 'auto',
            'voice.tts.arabic_diacritize' => false,
            'voice.tts.telnyx.api_key' => 'KEYTELNYXTEST',
            'voice.tts.telnyx.voices.ar.woman' => 'Telnyx.Bayan.Yara',
            'openai.api_key' => 'openai-key',
            'voice.tts.elevenlabs.api_key' => 'el-key',
        ]);

        $wav = 'RIFF'.pack('V', 36).'WAVEfmt '.str_repeat("\0", 24).'data'.pack('V', 0);

        Http::fake([
            'api.telnyx.com/*' => Http::response($wav, 200, [
                'Content-Type' => 'audio/wav',
            ]),
            'api.openai.com/*' => Http::response(str_repeat('o', 64), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
            'api.elevenlabs.io/*' => Http::response(str_repeat('e', 64), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.tts', $instance), [
                'text' => 'مرحبا، معك سالي',
                'voice_profile' => 'woman',
                'locale' => 'ar',
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'api.telnyx.com/v2/text-to-speech/speech')
                && ($body['voice'] ?? null) === 'Telnyx.Bayan.Yara'
                && str_contains((string) ($body['text'] ?? ''), 'مرحبا');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com/v1/audio/speech'));
    }
}
