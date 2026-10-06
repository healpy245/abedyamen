<?php

declare(strict_types=1);

namespace App\Enums\KamanSupport;

enum SupportCommandStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Done = 'done';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::Sent;
    }
}
