<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

use Illuminate\Support\Facades\Log;

class TurnLogger
{
    public function write(int $conversationId, string $stage, bool $close, string $mediaId, int $calls, string $raw, string $final): void
    {
        Log::info('dima-v3.turn', [
            'conversation_id' => $conversationId,
            'stage' => $stage,
            'close' => $close,
            'media_id' => $mediaId,
            'model_calls' => $calls,
            'raw' => self::redact($raw),
            'final' => self::redact($final),
        ]);
    }

    public static function redact(string $text): string
    {
        foreach (['services.openai.dima_api_key', 'services.openai.mowahada_api_key', 'services.openai.api_key'] as $key) {
            $secret = trim((string) config($key));
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        $redacted = preg_replace_callback('/\+?\d(?:[\s().-]*\d){6,}/u', static function (array $match): string {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';

            return strlen($digits) >= 7 ? '[redacted]' : $match[0];
        }, $text);

        return is_string($redacted) ? $redacted : $text;
    }
}
