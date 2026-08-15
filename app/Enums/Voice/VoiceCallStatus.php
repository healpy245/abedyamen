<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceCallStatus: string
{
    case Pending = 'pending';
    case Ringing = 'ringing';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function acceptsMessages(): bool
    {
        return $this === self::Active;
    }
}
