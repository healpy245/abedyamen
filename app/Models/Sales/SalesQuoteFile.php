<?php

declare(strict_types=1);

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment evidence an agent attaches to a close-deal request. Stored privately, like Finance payment proof.
 */
class SalesQuoteFile extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'sales_quote_id',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'sales_quote_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
