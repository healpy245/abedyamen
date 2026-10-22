<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * OpenAI keys for the Mowahada / Farz bots.
 * Live ديما and أصواتكم each have their own key.
 * The workspace test chat, internal scripts, and the other Mowahada bots keep OPENAI_MOWAHADA_API_KEY.
 * Everything else keeps the shared OPENAI_API_KEY.
 */
class MowahadaOpenAi
{
    public const DIMA = 'dima';

    public const APPS = 'apps';

    public static function key(?string $bot = null): string
    {
        $dedicated = match ($bot) {
            self::DIMA => trim((string) config('services.openai.dima_api_key')),
            self::APPS => trim((string) config('services.openai.aswatkum_api_key')),
            default => '',
        };
        if ($dedicated !== '') {
            return $dedicated;
        }

        $testing = trim((string) config('services.openai.mowahada_api_key'));
        if ($testing !== '') {
            return $testing;
        }

        return trim((string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY')));
    }
}
