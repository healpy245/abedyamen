<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum ServiceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __('finance.service.'.$this->value);
    }

    /**
     * Colour family used by the finance pills (ok, warn, bad, info, muted).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Inactive => 'muted',
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
        };
    }
}
