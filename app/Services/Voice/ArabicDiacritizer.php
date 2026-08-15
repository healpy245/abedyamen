<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adds Arabic diacritics (tashkeel) so TTS pronunciation is clearer and less mumbled.
 */
class ArabicDiacritizer
{
    private const TASHKEEL_PATTERN = '/[\x{064B}-\x{0652}\x{0670}]/u';

    public function prepare(string $text): string
    {
        $text = trim($text);
        if ($text === '' || ! $this->containsArabic($text)) {
            return $text;
        }

        if (! filter_var(config('voice.tts.arabic_diacritize', true), FILTER_VALIDATE_BOOLEAN)) {
            return $text;
        }

        // Already heavily vocalized — leave as-is.
        if ($this->diacriticRatio($text) >= 0.25) {
            return $text;
        }

        try {
            return $this->diacritize($text);
        } catch (Throwable $e) {
            Log::debug('Arabic diacritization skipped', ['error' => $e->getMessage()]);

            return $text;
        }
    }

    private function diacritize(string $text): string
    {
        $cacheKey = 'tts_ar_tashkeel:'.sha1($text);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        if ($apiKey === '') {
            return $text;
        }

        $model = (string) config('voice.tts.diacritize_model', 'gpt-4o-mini');
        $timeout = max(3, (int) config('voice.tts.diacritize_timeout', 8));

        $http = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson();

        if (! config('services.openai.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => min(800, max(120, mb_strlen($text) * 4)),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'أنت مدقّق تشكيل عربي للكلام المنطوق (TTS). '
                        .'أضف التشكيل الكامل الصحيح (فتحة ضمة كسرة شدة تنوين سكون) على النص العربي فقط. '
                        .'حافظ على نفس الكلمات والترتيب وعلامات الترقيم والأرقام والكلمات غير العربية كما هي. '
                        .'لا تترجم ولا تختصر ولا تضف شرحاً. أعد النص المُشكَّل فقط.',
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ]);

        if (! $response->successful()) {
            return $text;
        }

        $out = trim((string) ($response->json('choices.0.message.content') ?? ''));
        $out = trim($out, " \t\n\r\"'`");

        if ($out === '' || ! $this->containsArabic($out) || ! $this->sameSkeleton($text, $out)) {
            return $text;
        }

        // Prefer result only when it actually added diacritics.
        if ($this->diacriticRatio($out) <= $this->diacriticRatio($text)) {
            return $text;
        }

        Cache::put($cacheKey, $out, now()->addDays(7));

        return $out;
    }

    private function containsArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    private function diacriticRatio(string $text): float
    {
        $letters = preg_match_all('/\p{Arabic}/u', $text) ?: 0;
        if ($letters === 0) {
            return 0.0;
        }

        $marks = preg_match_all(self::TASHKEEL_PATTERN, $text) ?: 0;

        return $marks / $letters;
    }

    /**
     * Ensure diacritized output did not rewrite the underlying letters/digits.
     */
    private function sameSkeleton(string $original, string $candidate): bool
    {
        return $this->skeleton($original) === $this->skeleton($candidate);
    }

    private function skeleton(string $text): string
    {
        $text = preg_replace(self::TASHKEEL_PATTERN, '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
