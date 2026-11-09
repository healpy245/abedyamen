<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum HardwareStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case None = 'none';

    public function label(): string
    {
        return __('finance.hardware.'.$this->value);
    }

    /**
     * Colour family used by the finance pills (ok, warn, bad, info, muted).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'ok',
            self::Partial => 'warn',
            self::Unpaid => 'bad',
            self::None => 'muted',
        };
    }

    /**
     * Dot colour for the design-system badge; null keeps it neutral.
     */
    public function badgeTone(): ?string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Partial => 'amber',
            self::Unpaid => 'red',
            self::None => null,
        };
    }
}
