<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum CreditTerm: int
{
    case Regular = 1;
    case CreditPlan = 6;
    case Installments = 8;

    public function label(): string
    {
        return __('tranzila.credit_term.'.$this->value);
    }
}
