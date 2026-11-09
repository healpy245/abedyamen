<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum DocumentType: string
{
    case TaxInvoice = 'IN';
    case TaxInvoiceReceipt = 'IR';
    case Receipt = 'RE';
    case DealInvoice = 'DI';

    public function label(): string
    {
        return __('tranzila.document_type.'.$this->value);
    }

    public function hasItems(): bool
    {
        return $this !== self::Receipt;
    }

    public function hasPayments(): bool
    {
        return $this === self::Receipt || $this === self::TaxInvoiceReceipt;
    }
}
