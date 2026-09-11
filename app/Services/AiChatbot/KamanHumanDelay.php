<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Human-like WhatsApp typing delays and bubble splits for the Kaman POS sales bot.
 */
class KamanHumanDelay
{
    public const MAX_BUBBLES = 2;

    /**
     * Seconds to wait / show typing for this bubble.
     */
    public static function secondsForText(string $text): float
    {
        $length = mb_strlen(trim($text));

        [$min, $max] = match (true) {
            $length <= 40 => [2.8, 4.8],
            $length <= 100 => [4.5, 7.5],
            $length <= 220 => [7.0, 11.0],
            $length <= 400 => [9.0, 14.0],
            default => [11.0, 16.0],
        };

        return round($min + (mt_rand(0, 1000) / 1000) * ($max - $min), 2);
    }

    public static function millisecondsForText(string $text): int
    {
        return (int) round(self::secondsForText($text) * 1000);
    }

    /**
     * Small pause between consecutive WhatsApp bubbles.
     */
    public static function secondsBetweenBubbles(): float
    {
        return round(2.2 + (mt_rand(0, 1300) / 1000), 2);
    }

    /**
     * Split one model reply into at most two WhatsApp bubbles (blank-line separated).
     *
     * @return list<string>
     */
    public static function splitBubbles(string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            return [];
        }

        $parts = preg_split("/\n{2,}/u", $text) ?: [$text];
        $bubbles = [];
        foreach ($parts as $part) {
            $part = self::stripBubbleLabels(trim((string) $part));
            if ($part !== '') {
                $bubbles[] = $part;
            }
        }

        if ($bubbles === []) {
            return [self::stripBubbleLabels($text)];
        }

        if (count($bubbles) <= self::MAX_BUBBLES) {
            return $bubbles;
        }

        $first = array_shift($bubbles);

        return [$first, trim(implode("\n\n", $bubbles))];
    }

    public static function stripBubbleLabels(string $text): string
    {
        $text = preg_replace('/^(?:MESSAGE|رسالة)\s*[12]\s*[:\-]?\s*/iu', '', $text) ?? $text;

        return trim($text);
    }
}
