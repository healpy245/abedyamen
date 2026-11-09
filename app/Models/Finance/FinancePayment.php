<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancePayment extends Model
{
    protected $fillable = [
        'finance_client_id',
        'period',
        'amount',
        'status',
        'method',
        'paid_on',
        'reference',
        'note',
        'items',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'status' => PaymentRecordStatus::class,
            'method' => PaymentMethod::class,
            'items' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FinancePaymentFile::class);
    }

    /**
     * Still to be collected: a failed or pending month.
     */
    public function isOpen(): bool
    {
        return $this->status !== PaymentRecordStatus::Paid;
    }

    public function displayNumber(): string
    {
        return 'M-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
