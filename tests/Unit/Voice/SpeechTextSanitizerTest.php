<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\SpeechTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpeechTextSanitizerTest extends TestCase
{
    private SpeechTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new SpeechTextSanitizer;
    }

    #[DataProvider('emojiSamples')]
    public function test_it_removes_emojis_from_speech_text(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    public function test_it_maps_hebrew_bank_reference_to_arabic_speech(): void
    {
        $input = 'بدي صورة فيها المبلغ، التاريخ، و מספר אסמכתא.';
        $out = $this->sanitizer->sanitize($input);

        $this->assertStringContainsString('رقم الإيصال', $out);
        $this->assertDoesNotMatchRegularExpression('/\p{Hebrew}/u', $out);
    }

    public function test_it_transliterates_unknown_hebrew_words(): void
    {
        $out = $this->sanitizer->sanitize('تمام סניף هون');
        $this->assertStringContainsString('فرع', $out);
        $this->assertDoesNotMatchRegularExpression('/\p{Hebrew}/u', $out);
    }

    public function test_it_reduces_elongated_fillers(): void
    {
        $out = $this->sanitizer->sanitize('إييييي تمام، آآآ خليني أشوف');
        $this->assertStringNotContainsString('إييييي', $out);
        $this->assertStringNotContainsString('آآآ', $out);
        $this->assertStringContainsString('تمام', $out);
    }

    public static function emojiSamples(): array
    {
        return [
            'plain emoji' => ['مرحباً 😊 كيف حالك؟', 'مرحباً كيف حالك؟'],
            'multiple emoji' => ['Hello 👋 world 🌍!', 'Hello world !'],
            'markdown and emoji' => ['**مرحباً** 😀', 'مرحباً'],
            'emoji only' => ['😀👍', ''],
        ];
    }
}
