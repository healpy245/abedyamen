<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Failed = 'failed';

    public function label(): string
    {
        return __('finance.payment.'.$this->value);
    }

    /**
     * Colour family used by the finance pills (ok, warn, bad, info, muted).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Inactive => 'muted',
            self::Failed => 'bad',
        };
    }

    /**
     * Dot colour for the design-system badge; null keeps it neutral.
     */
    public function badgeTone(): ?string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => null,
            self::Failed => 'red',
        };
    }
}
