<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Tranzila\TransactionStatus;
use App\Enums\Tranzila\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranzilaTransaction extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['card_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'items' => 'array',
            'card_token' => 'encrypted',
            'period' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'finance_payment_id');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TranzilaDocument::class, 'transaction_id');
    }

    public function isRefundable(): bool
    {
        return $this->type === TransactionType::Debit
            && $this->status === TransactionStatus::Approved
            && $this->refunds->every(fn (self $refund): bool => $refund->status === TransactionStatus::Declined);
    }
}
