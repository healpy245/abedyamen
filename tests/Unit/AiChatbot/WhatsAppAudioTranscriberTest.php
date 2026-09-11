<?php

declare(strict_types=1);

namespace Tests\Unit\AiChatbot;

use App\Services\AiChatbot\WhatsAppAudioTranscriber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppAudioTranscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.verify_ssl' => false,
            'malan.voice.transcription_model' => 'gpt-transcribe',
            'malan.voice.transcription_fallback_model' => 'whisper-1',
            'malan.voice.transcription_language' => 'ar',
            'malan.voice.transcription_languages' => 'ar,he',
        ]);

        Storage::fake('local');
    }

    public function test_usable_transcript_rejects_noise(): void
    {
        $t = new WhatsAppAudioTranscriber;

        $this->assertFalse($t->isUsableTranscript(''));
        $this->assertFalse($t->isUsableTranscript('...'));
        $this->assertFalse($t->isUsableTranscript('؟؟'));
        $this->assertTrue($t->isUsableTranscript('كيف الأسعار'));
        $this->assertTrue($t->isUsableTranscript('ok'));
    }

    public function test_detects_subtitle_hallucinations_and_decoder_loops(): void
    {
        $t = new WhatsAppAudioTranscriber;

        $this->assertTrue($t->looksLikeHallucination('اشترك في القناة ولا تنسى الجرس'));
        $this->assertTrue($t->looksLikeHallucination('Thanks for watching!'));
        $this->assertFalse($t->looksLikeHallucination('قديش سعر الاشتراك عندكم'));

        $this->assertTrue($t->looksRepetitive('لا لا لا لا لا لا'));
        $this->assertTrue($t->looksRepetitive('اااااااااااا'));
        $this->assertFalse($t->looksRepetitive('لا لا ما بدي اشترك معكم'));
    }

    public function test_transliterated_hebrew_terms_are_spelled_back(): void
    {
        $t = new WhatsAppAudioTranscriber;

        $this->assertSame('بدي סיב אופטי', $t->applyDomainSpelling('بدي السيف أوبتي'));
        $this->assertSame('قديش سعر סיב אופטי', $t->applyDomainSpelling('قديش سعر سيب اوبتي'));
        $this->assertSame('انا مع ملان', $t->applyDomainSpelling('انا مع مالان'));
        $this->assertSame('بدي اسأل عن السعر', $t->applyDomainSpelling('بدي اسأل عن السعر'));
        $this->assertSame('انا على אביב', $t->applyDomainSpelling('انا على aviv', 'kaman'));
        $this->assertSame('شغال مع هات', $t->applyDomainSpelling('شغال مع haat', 'kaman'));
    }

    public function test_transcribe_sends_domain_context_to_primary_model(): void
    {
        $this->storeVoice('voice.ogg');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'قديش سعر الـסיב אופטי عندكم',
                'languages' => [['code' => 'ar']],
            ], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.ogg', 'audio/ogg; codecs=opus');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['unclear']);
        $this->assertSame('قديش سعر الـסיב אופטי عندكم', $result['transcript']);
        $this->assertSame('gpt-transcribe', $result['model']);
        $this->assertSame(['ar'], $result['languages']);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, 'gpt-transcribe')
                && str_contains($body, 'name="keywords[]"')
                && str_contains($body, 'סיב אופטי')
                && str_contains($body, 'name="languages[]"')
                && str_contains($body, 'name="include[]"')
                && str_contains($body, 'filename="voice.ogg"');
        });
    }

    public function test_kaman_domain_sends_pos_keywords_not_fiber_terms(): void
    {
        $this->storeVoice('voice.ogg');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'انا على אביב وشغال مع هات',
                'languages' => [['code' => 'ar']],
            ], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe(
            'local',
            'malan/whatsapp-media/voice.ogg',
            'audio/ogg; codecs=opus',
            'kaman',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('انا على אביב وشغال مع هات', $result['transcript']);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, 'name="keywords[]"')
                && str_contains($body, 'كوباه')
                && str_contains($body, 'האט')
                && ! str_contains($body, 'סיב אופטי');
        });
    }

    public function test_opus_voice_note_is_uploaded_with_an_accepted_extension(): void
    {
        // GreenAPI sometimes leaves an extension the endpoint refuses.
        $this->storeVoice('voice.audio');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'اه بدي اشترك'], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.audio', 'audio/opus');

        $this->assertFalse($result['unclear']);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'filename="voice.ogg"'));
    }

    public function test_mic_mistap_is_unclear_without_calling_the_api(): void
    {
        Storage::disk('local')->put('malan/whatsapp-media/tap.ogg', 'OggS'.str_repeat('x', 40));

        Http::fake();

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/tap.ogg', 'audio/ogg');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['unclear']);
        $this->assertSame('audio_too_short', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_falls_back_to_second_model_when_primary_fails(): void
    {
        $this->storeVoice('voice.ogg');

        Http::fakeSequence('api.openai.com/v1/audio/transcriptions')
            ->push(['error' => ['message' => 'server error']], 500)
            ->push(['error' => ['message' => 'server error']], 500)
            ->push(['text' => 'بدي اعرف الاسعار'], 200);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.ogg', 'audio/ogg');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['unclear']);
        $this->assertSame('بدي اعرف الاسعار', $result['transcript']);
        $this->assertSame('whisper-1', $result['model']);
    }

    public function test_hallucinated_primary_transcript_is_replaced_by_fallback(): void
    {
        $this->storeVoice('voice.ogg');

        Http::fakeSequence('api.openai.com/v1/audio/transcriptions')
            ->push(['text' => 'شكرا لمشاهدة الفيديو ولا تنسى الاشتراك في القناة'], 200)
            ->push(['text' => 'مش مهتم شكرا'], 200);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.ogg', 'audio/ogg');

        $this->assertFalse($result['unclear']);
        $this->assertSame('مش مهتم شكرا', $result['transcript']);
        $this->assertSame('whisper-1', $result['model']);
    }

    public function test_low_confidence_transcript_is_unclear_but_keeps_the_guess(): void
    {
        config(['malan.voice.transcription_model' => 'whisper-1']);
        $this->storeVoice('voice.ogg');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'بشط مرد كلا',
                'segments' => [
                    ['avg_logprob' => -1.9, 'no_speech_prob' => 0.2],
                ],
            ], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.ogg', 'audio/ogg');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['unclear']);
        $this->assertSame('low_confidence', $result['reason']);
        $this->assertSame('بشط مرد كلا', $result['transcript']);
    }

    public function test_silence_flagged_by_whisper_is_unclear(): void
    {
        config(['malan.voice.transcription_model' => 'whisper-1']);
        $this->storeVoice('voice.ogg');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'هلا',
                'segments' => [
                    ['avg_logprob' => -0.3, 'no_speech_prob' => 0.94],
                ],
            ], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/voice.ogg', 'audio/ogg');

        $this->assertTrue($result['unclear']);
        $this->assertSame('no_speech', $result['reason']);
    }

    public function test_transcribe_marks_empty_as_unclear(): void
    {
        $this->storeVoice('empty.ogg');

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '   '], 200),
        ]);

        $result = (new WhatsAppAudioTranscriber)->transcribe('local', 'malan/whatsapp-media/empty.ogg', 'audio/ogg');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['unclear']);
        $this->assertSame('unusable', $result['reason']);
    }

    private function storeVoice(string $name): void
    {
        Storage::disk('local')->put(
            'malan/whatsapp-media/'.$name,
            'OggS'.str_repeat("\x01\x02\x03\x04", 800),
        );
    }
}
