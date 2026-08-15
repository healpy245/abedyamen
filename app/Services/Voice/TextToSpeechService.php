<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Contracts\Voice\TextToSpeechProvider;
use App\Enums\Voice\VoiceProfile;
use App\Enums\Voice\VoiceTtsProvider;
use App\Services\Voice\Tts\EdgeNeuralTextToSpeechProvider;
use App\Services\Voice\Tts\ElevenLabsTextToSpeechProvider;
use App\Services\Voice\Tts\OpenAiTextToSpeechProvider;
use App\Services\Voice\Tts\TelnyxTextToSpeechProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TextToSpeechService
{
    public function __construct(
        protected ArabicDiacritizer $arabicDiacritizer,
        protected SpeechTextSanitizer $speechTextSanitizer,
    ) {}

    public function providerName(): VoiceTtsProvider
    {
        $value = (string) config('voice.tts.provider', VoiceTtsProvider::Auto->value);

        return VoiceTtsProvider::tryFrom($value) ?? VoiceTtsProvider::Auto;
    }

    public function usesBrowserFallback(): bool
    {
        return $this->providerName() === VoiceTtsProvider::Browser;
    }

    public function synthesize(string $text, VoiceProfile $profile, string $locale): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        // Always sanitize before language detection / provider calls (Hebrew → Arabic speech, fillers).
        $text = $this->speechTextSanitizer->sanitize($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        $language = $this->languageKey($locale, $text);
        // Diacritics add latency and push MSA pronunciation — keep off for dialect speech.
        if ($language === 'ar' && filter_var(config('voice.tts.arabic_diacritize', false), FILTER_VALIDATE_BOOLEAN)) {
            $text = $this->arabicDiacritizer->prepare($text);
        }

        $lastException = null;

        foreach ($this->providerChain($language) as $provider) {
            try {
                $voiceName = $this->resolveVoiceName($profile, $language, $provider);
                $options = $this->optionsForProvider($provider, $profile, $language);

                return $this->resolveProvider($provider)->synthesize($text, $voiceName, $options);
            } catch (Throwable $exception) {
                $lastException = $exception;
                Log::debug('TTS provider failed, trying next', [
                    'provider' => $provider->value,
                    'language' => $language,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new RuntimeException('TTS synthesis failed.');
    }

    /**
     * @return list<VoiceTtsProvider>
     */
    private function providerChain(string $language = 'en'): array
    {
        $configured = $this->providerName();

        if ($configured === VoiceTtsProvider::Browser) {
            throw new RuntimeException('browser_tts');
        }

        if ($configured !== VoiceTtsProvider::Auto) {
            return [$configured];
        }

        // Arabic: Telnyx Bayan (native Arabic dialects) first when configured.
        if ($language === 'ar') {
            $chain = [];
            if ($this->hasTelnyxKey()) {
                $chain[] = VoiceTtsProvider::Telnyx;
            }
            if ($this->hasOpenAiKey()) {
                $chain[] = VoiceTtsProvider::OpenAi;
            }
            $chain[] = VoiceTtsProvider::Edge;
            if ($this->hasElevenLabsKey()) {
                $chain[] = VoiceTtsProvider::ElevenLabs;
            }

            return $chain;
        }

        $chain = [];
        if ($this->hasTelnyxKey()) {
            $chain[] = VoiceTtsProvider::Telnyx;
        }
        if ($this->hasElevenLabsKey()) {
            $chain[] = VoiceTtsProvider::ElevenLabs;
        }
        if ($this->hasOpenAiKey()) {
            $chain[] = VoiceTtsProvider::OpenAi;
        }
        $chain[] = VoiceTtsProvider::Edge;

        return $chain;
    }

    private function hasOpenAiKey(): bool
    {
        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));

        return $apiKey !== '';
    }

    private function hasElevenLabsKey(): bool
    {
        return trim((string) config('voice.tts.elevenlabs.api_key')) !== '';
    }

    private function hasTelnyxKey(): bool
    {
        $apiKey = trim((string) (
            config('voice.tts.telnyx.api_key')
            ?: config('voice.providers.telnyx.api_key')
        ));

        return $apiKey !== '';
    }

    private function resolveProvider(VoiceTtsProvider $provider): TextToSpeechProvider
    {
        return match ($provider) {
            VoiceTtsProvider::Edge => app(EdgeNeuralTextToSpeechProvider::class),
            VoiceTtsProvider::OpenAi => app(OpenAiTextToSpeechProvider::class),
            VoiceTtsProvider::ElevenLabs => app(ElevenLabsTextToSpeechProvider::class),
            VoiceTtsProvider::Telnyx => app(TelnyxTextToSpeechProvider::class),
            VoiceTtsProvider::Auto, VoiceTtsProvider::Browser => throw new RuntimeException('browser_tts'),
        };
    }

    private function resolveVoiceName(
        VoiceProfile $profile,
        string $language,
        VoiceTtsProvider $provider,
    ): string {
        $voices = config("voice.tts.{$provider->value}.voices.{$language}")
            ?? config("voice.tts.{$provider->value}.voices.".config('voice.tts.fallback_language', 'en'))
            ?? [];

        $voiceName = $voices[$profile->value] ?? null;
        if (! is_string($voiceName) || $voiceName === '') {
            throw new RuntimeException("No {$provider->value} TTS voice configured for {$profile->value}/{$language}.");
        }

        return $voiceName;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsForProvider(VoiceTtsProvider $provider, VoiceProfile $profile, string $language): array
    {
        if ($provider === VoiceTtsProvider::OpenAi) {
            $instructions = (string) (config("voice.tts.openai.instructions.{$language}")
                ?? config('voice.tts.openai.instructions.en')
                ?? '');

            return [
                'instructions' => $instructions,
                'speed' => config('voice.tts.openai.speed', 1.05),
                'model' => config('voice.tts.openai.model', 'gpt-4o-mini-tts'),
            ];
        }

        if ($provider === VoiceTtsProvider::Telnyx) {
            $options = [
                'api_key' => config('voice.tts.telnyx.api_key') ?: config('voice.providers.telnyx.api_key'),
                'base_url' => config('voice.tts.telnyx.base_url', 'https://api.telnyx.com/v2'),
                'response_format' => config('voice.tts.telnyx.response_format', 'wav'),
                'sample_rate' => config('voice.tts.telnyx.sample_rate', 16000),
            ];

            $dialect = $language === 'ar'
                ? trim((string) config('voice.tts.telnyx.language_ar', ''))
                : $language;
            if ($dialect !== '') {
                $options['language'] = $dialect;
            }

            return $options;
        }

        if ($provider === VoiceTtsProvider::ElevenLabs) {
            return [
                'api_key' => config('voice.tts.elevenlabs.api_key'),
                'base_url' => config('voice.tts.elevenlabs.base_url'),
                'model' => config('voice.tts.elevenlabs.model', 'eleven_multilingual_v2'),
                'stability' => config('voice.tts.elevenlabs.stability', 0.5),
                'similarity_boost' => config('voice.tts.elevenlabs.similarity_boost', 0.75),
                'style' => config('voice.tts.elevenlabs.style', 0.0),
                'speed' => config('voice.tts.elevenlabs.speed', 1.0),
                'use_speaker_boost' => config('voice.tts.elevenlabs.use_speaker_boost', true),
                'optimize_streaming_latency' => config('voice.tts.elevenlabs.optimize_streaming_latency', 3),
            ];
        }

        $synthesis = $profile->synthesis();
        $pitchDelta = (int) round(((float) ($synthesis['pitch'] ?? 1.0) - 1.0) * 100);
        $rateDelta = (int) round(((float) ($synthesis['rate'] ?? 1.0) - 1.0) * 100);

        return [
            'pitch' => sprintf('%+d%%', $pitchDelta),
            'rate' => sprintf('%+d%%', $rateDelta),
        ];
    }

    private function languageKey(string $locale, string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'ar';
        }

        if (preg_match('/\p{Hebrew}/u', $text)) {
            return 'he';
        }

        if (preg_match('/[a-zA-Z]/', $text)) {
            return 'en';
        }

        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return (string) config('voice.tts.fallback_language', 'en');
        }

        $primary = explode('-', $locale)[0];

        return match ($primary) {
            'ar' => 'ar',
            'he' => 'he',
            default => 'en',
        };
    }
}
