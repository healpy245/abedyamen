<?php

declare(strict_types=1);

namespace App\Services\Form;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one recording into text that may mix Arabic, Hebrew, and English.
 */
final class MenuVoiceTranscriber
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    /** @var list<string> */
    private const LANGUAGES = ['ar', 'he', 'en'];

    private const PROMPT = 'Spoken instructions for building a restaurant menu. One sentence may mix Arabic, Hebrew, and English. Keep each word in the script it was spoken in. Example: أضف بيتزا מרגריטה large بـ 45 شيقل and delete the שותה.';

    /** @var list<string> */
    private const LANGUAGE_LIST_MODELS = [
        'gpt-transcribe',
        'gpt-live-transcribe',
        'gpt-4o-transcribe',
        'gpt-4o-mini-transcribe',
    ];

    /** @var list<string> */
    private const EXTENSIONS = ['flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm'];

    public function transcribe(string $binary, string $filename): ?string
    {
        if (strlen($binary) < 800) {
            return null;
        }

        $apiKey = (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            return null;
        }

        $safeName = $this->filename($filename);
        foreach ($this->models() as $model) {
            $withLanguages = $this->usesLanguageList($model);
            $result = $this->request($model, $apiKey, $binary, $safeName, $withLanguages);
            if (($result['status'] ?? 0) === 400 && $withLanguages) {
                $result = $this->request($model, $apiKey, $binary, $safeName, false);
            }
            $text = trim((string) ($result['text'] ?? ''));
            if (($result['ok'] ?? false) === true && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function models(): array
    {
        $models = [
            trim((string) config('malan.voice.transcription_model', 'gpt-4o-transcribe')),
            trim((string) config('malan.voice.transcription_fallback_model', 'whisper-1')),
        ];

        return array_values(array_unique(array_filter($models, static fn ($model) => $model !== '')));
    }

    /**
     * @return array{ok: bool, status: int, text?: string}
     */
    private function request(string $model, string $apiKey, string $binary, string $filename, bool $withLanguages): array
    {
        $http = Http::withToken($apiKey)->timeout(60)->acceptJson();
        if (! (bool) config('services.openai.verify_ssl', true)) {
            $http = $http->withOptions(['verify' => false]);
        }

        $whisper = str_starts_with($model, 'whisper');
        $http = $http
            ->attach('file', $binary, $filename)
            ->attach('model', $model)
            ->attach('prompt', self::PROMPT)
            ->attach('response_format', $whisper ? 'verbose_json' : 'json');

        if ($whisper) {
            $http = $http->attach('temperature', '0.0');
        }

        if ($withLanguages) {
            foreach (self::LANGUAGES as $language) {
                $http = $http->attach('languages[]', $language);
            }
        }

        try {
            $response = $http->post(self::ENDPOINT);
        } catch (Throwable $error) {
            Log::warning('Menu voice transcription exception', [
                'model' => $model,
                'error' => $error->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0];
        }

        if (! $response->successful()) {
            Log::warning('Menu voice transcription failed', [
                'model' => $model,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return ['ok' => false, 'status' => $response->status()];
        }

        $text = trim((string) $response->json('text'));

        return ['ok' => $text !== '', 'status' => $response->status(), 'text' => $text];
    }

    private function usesLanguageList(string $model): bool
    {
        return in_array($model, self::LANGUAGE_LIST_MODELS, true);
    }

    private function filename(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            $ext = 'm4a';
        }

        return 'voice.'.$ext;
    }
}
