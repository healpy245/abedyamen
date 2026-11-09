<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum ChargeFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half-yearly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return __('tranzila.frequency.'.$this->value);
    }
}
