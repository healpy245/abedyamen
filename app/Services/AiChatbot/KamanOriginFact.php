<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Kaman is originally from Kafr Qasim.
 * Never volunteer it; reply with this word only when the customer asked.
 */
class KamanOriginFact
{
    public const REPLY = 'كفر قاسم.';

    public function customerAsked(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/\{\{[A-Z0-9_]+\}\}/', $text)
            && ! preg_match('/كمان|kaman|קמאן|الشركة|اصلكم|أصلكم/iu', $text)
        ) {
            return false;
        }

        return (bool) preg_match(
            '/(?:من\s*وين|من\s*أين|מאיפה|where.{0,24}from).{0,28}(?:كمان|kaman|קמאן|الشركة|החברה|اصلكم|أصلكم|انتو|أنتو|أنتم|אתם)'
            .'|(?:كمان|kaman|קמאן|الشركة|החברה|اصلكم|أصلكم|انتو|أنتو|أنتم).{0,28}(?:من\s*وين|من\s*أين|מאיפה|from)'
            .'|(?:من\s*أي\s*(?:بلد|مدينة)).{0,20}(?:كمان|kaman|الشركة|انتو|أنتو)'
            .'|(?:كمان|kaman|الشركة).{0,20}(?:من\s*أي\s*(?:بلد|مدينة)|originally)'
            .'|(?:اصلكم|أصلكم|اصل\s*الشركة|أصل\s*الشركة|originally.{0,20}(?:kaman|كمان|from))/iu',
            $text
        );
    }
}
