<?php

declare(strict_types=1);

namespace App\Services\Voice\Tts;

use App\Contracts\Voice\TextToSpeechProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EdgeNeuralTextToSpeechProvider implements TextToSpeechProvider
{
    private const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';

    private const OUTPUT_FORMAT = 'audio-24khz-48kbitrate-mono-mp3';

    public function synthesize(string $text, string $voiceName, array $options = []): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('TTS text is empty.');
        }

        $pitch = $options['pitch'] ?? '+0%';
        $rate = $options['rate'] ?? '+0%';
        $locale = $this->localeFromVoiceName($voiceName);
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $ssml = <<<SSML
<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="{$locale}">
  <voice name="{$voiceName}">
    <prosody pitch="{$pitch}" rate="{$rate}" volume="+0%">{$escaped}</prosody>
  </voice>
</speak>
SSML;

        $url = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/edge/v1'
            .'?TrustedClientToken='.self::TRUSTED_CLIENT_TOKEN;

        $response = Http::withHeaders([
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => self::OUTPUT_FORMAT,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        ])
            ->timeout((int) config('voice.tts.timeout', 30))
            ->withBody($ssml, 'application/ssml+xml')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('Edge neural TTS failed with status '.$response->status());
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('Edge neural TTS returned empty audio.');
        }

        return $audio;
    }

    private function localeFromVoiceName(string $voiceName): string
    {
        $parts = explode('-', $voiceName);
        if (count($parts) >= 2) {
            return $parts[0].'-'.$parts[1];
        }

        return 'en-US';
    }
}
