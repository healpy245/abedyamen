<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sales\SalesQuote;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells Finance users a sales agent is waiting for them to confirm a client.
 */
class SalesDealRequested extends Notification
{
    public function __construct(
        public SalesQuote $quote,
        public ?User $agent = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'sales_deal_requested',
            'quote_id' => $this->quote->id,
            'quote_number' => $this->quote->number,
            'client' => $this->quote->client?->name,
            'actor' => $this->agent?->name,
            'monthly' => (float) $this->quote->monthly_total,
            'one_time' => (float) $this->quote->one_time_total,
            'url' => route('workspace.finance.deals', ['open' => $this->quote->id]),
        ];
    }
}
