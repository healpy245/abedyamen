<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Debounce window so the Kaman sales bot waits for split WhatsApp bubbles
 * before generating one reply.
 */
final class KamanListeningWindow
{
    /** Inactivity after the last customer bubble before we process (seconds). */
    public const DEBOUNCE_MIN = 6.0;

    public const DEBOUNCE_MAX = 9.0;

    /** Hard cap so a continuous stream cannot delay forever. */
    public const MAX_BURST_SECONDS = 20;

    public static function randomDebounceSeconds(): float
    {
        return self::DEBOUNCE_MIN + (mt_rand(0, 1000) / 1000) * (self::DEBOUNCE_MAX - self::DEBOUNCE_MIN);
    }

    public static function secondsUntilProcess(?\Carbon\CarbonInterface $burstStartedAt = null): int
    {
        $now = now();
        $burstStartedAt ??= $now;
        $debounceTarget = $now->copy()->addSeconds(self::randomDebounceSeconds());
        $maxTarget = $burstStartedAt->copy()->addSeconds(self::MAX_BURST_SECONDS);
        $target = $debounceTarget->lt($maxTarget) ? $debounceTarget : $maxTarget;

        $seconds = (int) ceil($now->diffInSeconds($target, false));

        return max(1, min(self::MAX_BURST_SECONDS, $seconds));
    }
}
