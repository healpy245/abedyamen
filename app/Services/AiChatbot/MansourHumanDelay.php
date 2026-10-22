<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * WhatsApp typing time for منصور. Short lines stay brief. Longer replies wait longer.
 * Green API accepts 1000–20000 ms; the sender clamps to that range.
 */
class MansourHumanDelay
{
    public static function millisecondsForText(string $text, bool $half = false): int
    {
        $length = mb_strlen(trim($text));

        [$min, $max] = match (true) {
            $length <= 40 => [1200, 2000],
            $length <= 90 => [2500, 4500],
            $length <= 180 => [5500, 9000],
            default => [10000, 17000],
        };

        if ($half) {
            $min = intdiv($min, 2);
            $max = intdiv($max, 2);
        }

        return $min + mt_rand(0, max(0, $max - $min));
    }
}
