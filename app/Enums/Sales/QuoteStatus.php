<?php

declare(strict_types=1);

namespace App\Enums\Sales;

/**
 * Draft → sent to the client on WhatsApp → close-deal request waiting for MFITGROUP Finance → sold.
 * Finance can return a request to the agent with a note instead of confirming it.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Pending = 'pending';
    case Returned = 'returned';
    case Sold = 'sold';

    public function label(): string
    {
        return __('sales.status.'.$this->value);
    }

    /**
     * Dot colour for the design-system badge; null keeps it neutral.
     */
    public function badgeTone(): ?string
    {
        return match ($this) {
            self::Sent => 'blue',
            self::Pending => 'amber',
            self::Returned => 'red',
            self::Sold => 'green',
            self::Draft => null,
        };
    }
}
