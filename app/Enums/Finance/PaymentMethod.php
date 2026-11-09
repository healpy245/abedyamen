<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentMethod: string
{
    case CreditCard = 'credit_card';
    case StandingOrder = 'standing_order';
    case BankTransfer = 'bank_transfer';
    case Bit = 'bit';
    case Cash = 'cash';
    case Check = 'check';

    public function label(): string
    {
        return __('finance.method.'.$this->value);
    }
}
