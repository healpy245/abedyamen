<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Tranzila\DocumentAction;
use App\Enums\Tranzila\DocumentStatus;
use App\Enums\Tranzila\DocumentType;
use App\Enums\Tranzila\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranzilaDocument extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'action' => DocumentAction::class,
            'status' => DocumentStatus::class,
            'document_date' => 'date',
            'total' => 'decimal:2',
            'payload' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TranzilaTransaction::class, 'transaction_id');
    }

    public function relatedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_document_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(self::class, 'related_document_id');
    }

    /**
     * Manual documents only. A card charge's document is cancelled by refunding the charge, so that the
     * money and the document move together and the refund cannot credit it a second time.
     */
    public function isCreditable(): bool
    {
        return $this->action === DocumentAction::Debit
            && $this->transaction_id === null
            && $this->status === DocumentStatus::Issued
            && filled($this->number)
            && $this->credits->every(fn (self $credit): bool => $credit->status === DocumentStatus::Failed);
    }

    /**
     * A charge that was refunded in the meantime no longer needs its invoice.
     */
    public function canBeReissued(): bool
    {
        $refunded = $this->action === DocumentAction::Debit
            && $this->transaction?->refunds()->where('status', TransactionStatus::Approved)->exists();

        return ! $refunded && ($this->status === DocumentStatus::Failed || $this->status === DocumentStatus::Unknown);
    }
}
