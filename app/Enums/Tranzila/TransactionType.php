<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum TransactionType: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return __('tranzila.transaction_type.'.$this->value);
    }
}
