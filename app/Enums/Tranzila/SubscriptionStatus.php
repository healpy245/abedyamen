<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Failed = 'failed';
    // No usable answer came back, so the standing order may exist in Tranzila.
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('tranzila.subscription_status.'.$this->value);
    }

    public function tone(): ?string
    {
        return match ($this) {
            self::Active => 'green',
            self::Failed => 'red',
            self::Inactive => null,
            self::Pending, self::Unknown => 'amber',
        };
    }
}
