<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentRecordStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Pending = 'pending';

    public function label(): string
    {
        return __('finance.record.'.$this->value);
    }

    /**
     * Colour family used by the finance pills (ok, warn, bad, info, muted).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'ok',
            self::Failed => 'bad',
            self::Pending => 'warn',
        };
    }

    /**
     * Dot colour for the design-system badge; null keeps it neutral.
     */
    public function badgeTone(): ?string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Failed => 'red',
            self::Pending => 'amber',
        };
    }
}
