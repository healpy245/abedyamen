<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

/**
 * Listening-window (debounce) timing for campaign message bursts.
 * Separate from human typing delay after AI generation.
 */
final class CampaignListeningWindow
{
    /** Normal inactivity before processing a burst (seconds). */
    public const DEBOUNCE_MIN = 2.5;

    public const DEBOUNCE_MAX = 4.0;

    /** Hard cap so continuous messaging cannot delay forever. */
    public const MAX_BURST_SECONDS = 10;

    public static function randomDebounceSeconds(): float
    {
        return self::DEBOUNCE_MIN + (mt_rand(0, 1000) / 1000) * (self::DEBOUNCE_MAX - self::DEBOUNCE_MIN);
    }

    /**
     * Seconds to wait from now until the burst should be processed.
     *
     * @param  \Carbon\CarbonInterface|null  $burstStartedAt  First message of the current burst
     */
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
