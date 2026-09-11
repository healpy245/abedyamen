<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

/**
 * Human-like WhatsApp send delays for campaign AI replies (length buckets + randomness).
 * Short bubbles stay snappy; longer bubbles get proportionally longer typing waits.
 */
class CampaignHumanDelay
{
    /**
     * Seconds to wait after AI generation before sending this bubble to WhatsApp.
     * Short asks ("تمام، اعطيني اسمك الكامل بس.") stay under a second.
     */
    public static function secondsForText(string $text): float
    {
        $length = mb_strlen(trim($text));

        [$min, $max] = match (true) {
            $length <= 55 => [0.35, 0.75],
            $length <= 90 => [0.9, 1.7],
            $length <= 160 => [1.8, 3.2],
            $length <= 280 => [3.2, 5.5],
            $length <= 450 => [5.0, 8.5],
            default => [7.0, 12.0],
        };

        // Only nudge mid/long pitches — short collection asks stay snappy.
        if ($length > 55) {
            $proportional = 0.6 + ($length / 55);
            $min = max($min, min($proportional, $max));
        }

        $seconds = $min + (mt_rand(0, 1000) / 1000) * max(0.0, $max - $min);

        return round(max(0.35, $seconds), 2);
    }

    public static function millisecondsForText(string $text): int
    {
        return (int) round(self::secondsForText($text) * 1000);
    }

    /**
     * Extra pause between consecutive WhatsApp bubbles so the customer can read.
     */
    public static function secondsBetweenBubbles(): int
    {
        return random_int(1, 2);
    }

    /**
     * Split one model reply into WhatsApp bubbles (blank-line separated).
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
            $part = self::stripInstructionNumbering(trim((string) $part));
            if ($part !== '') {
                $bubbles[] = $part;
            }
        }

        return $bubbles !== [] ? $bubbles : [self::stripInstructionNumbering($text)];
    }

    /**
     * Models sometimes copy prompt markers like ① / ② into WhatsApp text — strip them.
     */
    public static function stripInstructionNumbering(string $text): string
    {
        $text = preg_replace('/^[①②③④⑤⑥⑦⑧⑨⑩]+\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:الفقعة|الرسالة)\s*(?:الأولى|الثانية|الأولى|1|2)\s*[:\-]?\s*/u', '', $text) ?? $text;

        return trim($text);
    }
}
