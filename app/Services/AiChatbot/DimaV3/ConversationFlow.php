<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

/**
 * Stage names and the only moves a model reply may make.
 * Closed is set by the engine on opt-out, not by the model.
 */
class ConversationFlow
{
    public const GREETING = 'greeting';

    public const INTRODUCTION = 'introduction';

    public const PERMISSION = 'permission';

    public const VOTING_INTENTION = 'voting_intention';

    public const PARTY_PREFERENCE = 'party_preference';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STAGES = [
        self::GREETING,
        self::INTRODUCTION,
        self::PERMISSION,
        self::VOTING_INTENTION,
        self::PARTY_PREFERENCE,
        self::OPEN,
        self::CLOSED,
    ];

    public function normalize(string $stage): string
    {
        return in_array($stage, self::STAGES, true) ? $stage : '';
    }

    /**
     * Forward moves only, and a move may skip steps: one message often
     * introduces Dima and asks permission together. Nothing at or past
     * voting_intention is reachable until permission was granted.
     */
    public function accepts(string $from, string $to, bool $permissionGranted = false): bool
    {
        if ($to === self::CLOSED || $from === self::CLOSED) {
            return false;
        }
        $fromIndex = array_search($from, self::STAGES, true);
        $toIndex = array_search($to, self::STAGES, true);
        if ($fromIndex === false || $toIndex === false || $toIndex < $fromIndex) {
            return false;
        }
        if ($toIndex > $fromIndex && $toIndex >= array_search(self::VOTING_INTENTION, self::STAGES, true)) {
            return $permissionGranted;
        }

        return true;
    }
}
