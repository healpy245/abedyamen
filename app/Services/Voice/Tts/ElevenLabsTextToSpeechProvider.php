<?php

declare(strict_types=1);

namespace App\Services\Voice\Tts;

use App\Contracts\Voice\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsTextToSpeechProvider implements TextToSpeechProvider
{
    public function synthesize(string $text, string $voiceName, array $options = []): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        $voiceId = trim($voiceName);
        if ($voiceId === '') {
            throw new RuntimeException('ElevenLabs voice ID is not configured.');
        }

        $apiKey = trim((string) ($options['api_key'] ?? config('voice.tts.elevenlabs.api_key')));
        if ($apiKey === '') {
            throw new RuntimeException('ElevenLabs API key is not configured for TTS.');
        }

        $baseUrl = rtrim((string) ($options['base_url'] ?? config('voice.tts.elevenlabs.base_url', 'https://api.elevenlabs.io/v1')), '/');
        $model = (string) ($options['model'] ?? config('voice.tts.elevenlabs.model', 'eleven_multilingual_v2'));

        $stability = $this->clampFloat($options['stability'] ?? config('voice.tts.elevenlabs.stability', 0.5), 0.0, 1.0);
        $similarity = $this->clampFloat($options['similarity_boost'] ?? config('voice.tts.elevenlabs.similarity_boost', 0.75), 0.0, 1.0);
        $style = $this->clampFloat($options['style'] ?? config('voice.tts.elevenlabs.style', 0.0), 0.0, 1.0);
        $speed = $this->clampFloat($options['speed'] ?? config('voice.tts.elevenlabs.speed', 1.0), 0.7, 1.2);
        $useSpeakerBoost = filter_var(
            $options['use_speaker_boost'] ?? config('voice.tts.elevenlabs.use_speaker_boost', true),
            FILTER_VALIDATE_BOOLEAN
        );

        $payload = [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => [
                'stability' => $stability,
                'similarity_boost' => $similarity,
                'style' => $style,
                'use_speaker_boost' => $useSpeakerBoost,
                'speed' => $speed,
            ],
        ];

        $optimizeLatency = (int) ($options['optimize_streaming_latency']
            ?? config('voice.tts.elevenlabs.optimize_streaming_latency', 3));
        $optimizeLatency = max(0, min(4, $optimizeLatency));

        $url = $baseUrl.'/text-to-speech/'.$voiceId;
        if ($optimizeLatency > 0) {
            $url .= '?optimize_streaming_latency='.$optimizeLatency;
        }

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
            'Accept' => 'audio/mpeg',
        ])
            ->timeout((int) config('voice.tts.timeout', 30))
            ->accept('audio/mpeg')
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('ElevenLabs TTS failed with status '.$response->status());
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('ElevenLabs TTS returned empty audio.');
        }

        return $audio;
    }

    private function clampFloat(mixed $value, float $min, float $max): float
    {
        if (! is_numeric($value)) {
            return $min;
        }

        return max($min, min($max, (float) $value));
    }
}
