<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    // No usable answer came back, so the card may or may not have been charged.
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('tranzila.transaction_status.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'green',
            self::Declined => 'red',
            self::Pending, self::Unknown => 'amber',
        };
    }

    /**
     * Someone has to find out what happened before this card can be charged again.
     */
    public function isUnresolved(): bool
    {
        return $this === self::Pending || $this === self::Unknown;
    }
}
