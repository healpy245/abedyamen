<?php

declare(strict_types=1);

namespace App\Enums\Sales;

/**
 * New → contacted → meeting scheduled → after the meeting. Two stages hold sub-statuses, kept as their own values:
 * "Contacted" is not answering, will decide on a meeting, or not interested; "After meeting" is waiting to
 * decide, won or lost.
 */
enum LeadStage: string
{
    case New = 'new';
    case NoAnswer = 'no_answer';
    case Deciding = 'deciding';
    case NotInterested = 'not_interested';
    case Meeting = 'meeting';
    case Waiting = 'waiting';
    case Won = 'won';
    case Lost = 'lost';

    public const CONTACTED = 'contacted';

    public const AFTER_MEETING = 'after_meeting';

    /**
     * Values from before the stages were merged, as the phone app or an old browser may still send them.
     */
    public const LEGACY = ['contacted' => 'deciding', 'demo' => 'waiting', 'negotiation' => 'waiting'];

    public function label(): string
    {
        return __('sales.lead_stage.'.$this->value);
    }

    /**
     * The stage a lead shows under: its own, "Contacted" or "After meeting".
     */
    public function group(): string
    {
        return match (true) {
            $this->isContacted() => self::CONTACTED,
            $this->isAfterMeeting() => self::AFTER_MEETING,
            default => $this->value,
        };
    }

    public function isContacted(): bool
    {
        return in_array($this, [self::NoAnswer, self::Deciding, self::NotInterested], true);
    }

    public function groupLabel(): string
    {
        return __('sales.lead_stage.'.$this->group());
    }

    public function isAfterMeeting(): bool
    {
        return in_array($this, [self::Waiting, self::Won, self::Lost], true);
    }

    public static function fromInput(mixed $value): ?self
    {
        $value = (string) $value;

        return self::tryFrom(self::LEGACY[$value] ?? $value);
    }
}
