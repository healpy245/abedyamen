<?php

declare(strict_types=1);

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt photo or document attached to a monthly payment. Stored on the private local disk.
 */
class FinancePaymentFile extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'finance_payment_id',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'finance_payment_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
