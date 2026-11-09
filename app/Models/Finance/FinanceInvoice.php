<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Finance\InvoiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'finance_client_id',
        'sales_quote_id',
        'type',
        'number',
        'issued_on',
        'subtotal',
        'vat_rate',
        'vat',
        'total',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'issued_on' => 'date',
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(FinanceClient::class, 'finance_client_id');
    }

    public function salesQuote(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sales\SalesQuote::class, 'sales_quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayNumber(): string
    {
        return str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }
}
