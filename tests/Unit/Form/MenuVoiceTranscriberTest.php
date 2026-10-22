<?php

declare(strict_types=1);

namespace Tests\Unit\Form;

use App\Services\Form\MenuVoiceTranscriber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MenuVoiceTranscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.verify_ssl' => false,
            'malan.voice.transcription_model' => 'gpt-transcribe',
            'malan.voice.transcription_fallback_model' => 'whisper-1',
        ]);
    }

    public function test_one_recording_asks_for_arabic_hebrew_and_english_together(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'أضف بيتزا מרגריטה large',
            ], 200),
        ]);

        $text = (new MenuVoiceTranscriber)->transcribe(str_repeat('a', 900), 'note.m4a');

        $this->assertSame('أضف بيتزا מרגריטה large', $text);
        Http::assertSent(function ($request) {
            $body = $request->body();
            preg_match_all('/name="languages\[\]"(?:\r\n[^\r\n]+)*\r\n\r\n([a-z]{2})/', $body, $matches);

            return str_contains($body, 'gpt-transcribe')
                && str_contains($body, 'Arabic, Hebrew, and English')
                && ($matches[1] ?? []) === ['ar', 'he', 'en'];
        });
    }

    public function test_whisper_fallback_does_not_lock_a_single_language(): void
    {
        Http::fakeSequence('api.openai.com/v1/audio/transcriptions')
            ->push(['error' => 'bad'], 400)
            ->push(['error' => 'bad'], 400)
            ->push(['text' => 'add שותה and سلطة'], 200);

        $text = (new MenuVoiceTranscriber)->transcribe(str_repeat('b', 900), 'note.wav');

        $this->assertSame('add שותה and سلطة', $text);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, 'whisper-1')
                && ! str_contains($body, 'name="language"')
                && ! str_contains($body, 'name="languages[]"');
        });
    }
}
