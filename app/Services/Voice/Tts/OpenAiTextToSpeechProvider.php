<?php

declare(strict_types=1);

namespace App\Services\Voice\Tts;

use App\Contracts\Voice\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiTextToSpeechProvider implements TextToSpeechProvider
{
    public function synthesize(string $text, string $voiceName, array $options = []): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured for TTS.');
        }

        $model = (string) ($options['model'] ?? config('voice.tts.openai.model', 'gpt-4o-mini-tts'));
        $payload = [
            'model' => $model,
            'voice' => $voiceName,
            'input' => $text,
            'response_format' => 'mp3',
        ];

        $instructions = trim((string) ($options['instructions'] ?? ''));
        if ($instructions !== '' && $this->modelSupportsInstructions($model)) {
            $payload['instructions'] = $instructions;
        }

        $speed = $options['speed'] ?? config('voice.tts.openai.speed');
        if (is_numeric($speed)) {
            $payload['speed'] = max(0.25, min(4.0, (float) $speed));
        }

        try {
            return $this->requestAudio($apiKey, $payload);
        } catch (RuntimeException $exception) {
            if ($model !== 'tts-1-hd') {
                Log::debug('OpenAI TTS retrying with tts-1-hd', [
                    'error' => $exception->getMessage(),
                ]);

                $fallbackVoice = $this->legacyVoiceFor($voiceName);
                unset($payload['instructions']);

                return $this->requestAudio($apiKey, array_merge($payload, [
                    'model' => 'tts-1-hd',
                    'voice' => $fallbackVoice,
                ]));
            }

            throw $exception;
        }
    }

    private function legacyVoiceFor(string $voiceName): string
    {
        return match ($voiceName) {
            'coral', 'shimmer', 'nova' => 'nova',
            'onyx', 'echo' => 'onyx',
            'alloy' => 'alloy',
            default => 'nova',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestAudio(string $apiKey, array $payload): string
    {
        $baseUrl = rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/');
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);

        $http = Http::withToken($apiKey)
            ->timeout((int) config('voice.tts.timeout', 30))
            ->accept('audio/mpeg');

        if (! $sslVerify) {
            $http = $http->withoutVerifying();
        }

        if ($organization = config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => $organization]);
        }

        if ($project = config('openai.project')) {
            $http = $http->withHeaders(['OpenAI-Project' => $project]);
        }

        $response = $http->post($baseUrl.'/audio/speech', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI TTS failed with status '.$response->status());
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('OpenAI TTS returned empty audio.');
        }

        return $audio;
    }

    private function modelSupportsInstructions(string $model): bool
    {
        return str_contains($model, 'gpt-4o-mini-tts')
            || str_contains($model, 'gpt-4o-audio');
    }
}
