<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Enums\Finance\StorageCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageItem extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'name_ar',
        'name_en',
        'category',
        'unit_price',
        'track_stock',
        'stock',
        'low_stock',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => StorageCategory::class,
            'unit_price' => 'decimal:2',
            'track_stock' => 'boolean',
            'stock' => 'integer',
            'low_stock' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StorageMovement::class)->latest('id');
    }

    /**
     * The name in the given language (the app's language by default), falling back to the main name.
     */
    public function localName(?string $lang = null): string
    {
        $translated = match ($lang ?? app()->getLocale()) {
            'ar' => $this->name_ar,
            'en' => $this->name_en,
            default => null,
        };

        return filled($translated) ? (string) $translated : $this->name;
    }

    public function isLow(): bool
    {
        return $this->track_stock && $this->stock <= $this->low_stock;
    }
}
