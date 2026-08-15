<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Services\Voice\ArabicDiacritizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArabicDiacritizerTest extends TestCase
{
    public function test_skips_non_arabic_text(): void
    {
        config(['voice.tts.arabic_diacritize' => true]);

        $out = app(ArabicDiacritizer::class)->prepare('Hello there');

        $this->assertSame('Hello there', $out);
        Http::assertNothingSent();
    }

    public function test_diacritizes_arabic_via_openai(): void
    {
        config([
            'voice.tts.arabic_diacritize' => true,
            'openai.api_key' => 'test-key',
            'services.openai.api_key' => 'test-key',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'مَرْحَبًا، مَعَكِ سَالِي.'],
                ]],
            ], 200),
        ]);

        $out = app(ArabicDiacritizer::class)->prepare('مرحبا، معك سالي.');

        $this->assertSame('مَرْحَبًا، مَعَكِ سَالِي.', $out);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    public function test_keeps_original_when_disabled(): void
    {
        config(['voice.tts.arabic_diacritize' => false]);

        $out = app(ArabicDiacritizer::class)->prepare('مرحبا معك سالي');

        $this->assertSame('مرحبا معك سالي', $out);
    }
}
