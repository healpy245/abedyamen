<?php

declare(strict_types=1);

namespace App\Services\Voice\Tts;

use App\Contracts\Voice\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelnyxTextToSpeechProvider implements TextToSpeechProvider
{
    public function synthesize(string $text, string $voiceName, array $options = []): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        $voice = trim($voiceName);
        if ($voice === '') {
            throw new RuntimeException('Telnyx TTS voice is not configured.');
        }

        // Allow short form "Yara" → "Telnyx.Bayan.Yara"
        if (! str_contains($voice, '.')) {
            $voice = 'Telnyx.Bayan.'.$voice;
        }

        $apiKey = trim((string) ($options['api_key'] ?? config('voice.tts.telnyx.api_key') ?? config('voice.providers.telnyx.api_key')));
        if ($apiKey === '') {
            throw new RuntimeException('Telnyx API key is not configured for TTS.');
        }

        $baseUrl = rtrim((string) ($options['base_url'] ?? config('voice.tts.telnyx.base_url', 'https://api.telnyx.com/v2')), '/');
        $responseFormat = (string) ($options['response_format'] ?? config('voice.tts.telnyx.response_format', 'mp3'));
        $sampleRate = (int) ($options['sample_rate'] ?? config('voice.tts.telnyx.sample_rate', 16000));

        $payload = [
            'text' => $text,
            'voice' => $voice,
            'response_format' => $responseFormat,
            'sampling_rate' => $sampleRate,
        ];

        $language = trim((string) ($options['language'] ?? ''));
        if ($language !== '') {
            $payload['language'] = $language;
        }

        $response = Http::withToken($apiKey)
            ->timeout(min(20, (int) config('voice.tts.timeout', 30)))
            // Bayan often returns WAV even when mp3 is requested.
            ->accept('audio/wav, audio/mpeg, application/json')
            ->post($baseUrl.'/text-to-speech/speech', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Telnyx TTS failed with status '.$response->status());
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $json = $response->json();
            $b64 = is_array($json) ? ($json['base64_audio'] ?? null) : null;
            if (! is_string($b64) || $b64 === '') {
                throw new RuntimeException('Telnyx TTS returned JSON without audio.');
            }
            $audio = base64_decode($b64, true);
            if ($audio === false || $audio === '') {
                throw new RuntimeException('Telnyx TTS returned invalid base64 audio.');
            }

            return $audio;
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('Telnyx TTS returned empty audio.');
        }

        return $audio;
    }
}
