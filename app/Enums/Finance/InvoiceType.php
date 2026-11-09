<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum InvoiceType: string
{
    case TaxInvoiceReceipt = 'tax_invoice_receipt';
    case TaxInvoice = 'tax_invoice';
    case Receipt = 'receipt';
    case Credit = 'credit';

    public function label(): string
    {
        return __('finance.invoice_type.'.$this->value);
    }

    /**
     * Receipts confirm a payment only, so they carry no VAT line.
     */
    public function hasVat(): bool
    {
        return $this !== self::Receipt;
    }

    /**
     * How tracked stock moves when this document is issued: sales take items out, credits put them back.
     */
    public function stockDirection(): int
    {
        return match ($this) {
            self::TaxInvoiceReceipt, self::TaxInvoice => -1,
            self::Credit => 1,
            self::Receipt => 0,
        };
    }

    /**
     * Sign of the document in income totals. Only documents that record money received count.
     */
    public function incomeSign(): int
    {
        return match ($this) {
            self::TaxInvoiceReceipt, self::Receipt => 1,
            self::Credit => -1,
            self::TaxInvoice => 0,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::TaxInvoiceReceipt => 'ok',
            self::TaxInvoice => 'info',
            self::Receipt => 'warn',
            self::Credit => 'bad',
        };
    }

    /**
     * Dot colour for the design-system badge; null keeps it neutral.
     */
    public function badgeTone(): ?string
    {
        return match ($this) {
            self::TaxInvoiceReceipt => 'green',
            self::TaxInvoice => 'blue',
            self::Receipt => null,
            self::Credit => 'red',
        };
    }
}
