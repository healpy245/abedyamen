<?php

declare(strict_types=1);

namespace App\Support\Voice;

use Illuminate\Support\Facades\Log;

final class RealtimeVoiceResolver
{
    /**
     * @return list<string>
     */
    public static function allowedVoices(): array
    {
        $voices = config('voice.realtime.allowed_voices', []);

        return is_array($voices) ? array_values($voices) : [];
    }

    public static function resolve(?string $configured = null): string
    {
        $default = strtolower((string) config('voice.realtime.default_voice', 'marin'));
        $allowed = self::allowedVoices();
        $candidate = strtolower(trim($configured ?: (string) config('voice.realtime.voice', $default)));

        if ($candidate !== '' && in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        if ($candidate !== '' && $candidate !== $default) {
            Log::warning('Unsupported OpenAI realtime voice configured; falling back to marin', [
                'configured' => $configured ?? config('voice.realtime.voice'),
                'candidate' => $candidate,
                'allowed' => $allowed,
            ]);
        }

        if (in_array($default, $allowed, true)) {
            return $default;
        }

        return 'marin';
    }
}
