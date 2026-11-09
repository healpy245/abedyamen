<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum DocumentPaymentMethod: int
{
    case CreditCard = 1;
    case Cheque = 3;
    case BankTransfer = 4;
    case Cash = 5;
    case PayPal = 6;
    case Other = 10;

    public function label(): string
    {
        return __('tranzila.payment_method.'.$this->value);
    }

    /**
     * What staff can record on a manual document. Card payments come from a charge.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Cash, self::Cheque, self::BankTransfer, self::Other];
    }
}
