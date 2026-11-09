<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Tranzila\ChargeFrequency;
use App\Enums\Tranzila\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranzilaSubscription extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'charge_frequency' => ChargeFrequency::class,
            'amount' => 'decimal:2',
            'items' => 'array',
            'first_charge_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }
}
