<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Finance\StorageItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteItem extends Model
{
    public const KINDS = ['base', 'package', 'feature', 'equipment', 'service', 'custom'];

    /** Lines priced from storage: devices (by quantity) and one-time services. */
    public const STORAGE_KINDS = ['equipment', 'service'];

    protected $fillable = [
        'sales_quote_id',
        'kind',
        'sales_catalog_item_id',
        'storage_item_id',
        'name',
        'quantity',
        'unit_price',
        'billing',
        'line_total',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'sales_quote_id');
    }

    public function storageItem(): BelongsTo
    {
        return $this->belongsTo(StorageItem::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(SalesCatalogItem::class, 'sales_catalog_item_id');
    }

    /**
     * Lines show in the reader's language when their price-list item or device has a translation;
     * custom lines keep the name they were saved with.
     */
    public function displayName(?string $lang = null): string
    {
        return $this->catalogItem?->localName($lang) ?? $this->storageItem?->localName($lang) ?? $this->name;
    }

    public function isMonthly(): bool
    {
        return $this->billing === 'monthly';
    }

    public function quantityLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');
    }
}
