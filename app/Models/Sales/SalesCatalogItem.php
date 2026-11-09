<?php

declare(strict_types=1);

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A package or feature agents can put on a quote. The single "base" row is the monthly subscription
 * every monthly offer starts from.
 */
class SalesCatalogItem extends Model
{
    public const KINDS = ['base', 'package', 'feature'];

    public const BILLINGS = ['monthly', 'one_time'];

    protected $fillable = [
        'kind',
        'name',
        'name_ar',
        'name_en',
        'price',
        'billing',
        'note',
        'bullets',
        'popular',
        'sort',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'bullets' => 'array',
            'popular' => 'boolean',
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query, string $kind): void
    {
        $query->where('kind', $kind)->where('active', true)->orderBy('sort')->orderBy('id');
    }

    /**
     * The name in the given language (the app's language by default), falling back to the Hebrew one.
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

    /**
     * @return list<string>
     */
    public function bulletList(): array
    {
        return array_values(array_filter(array_map('strval', $this->bullets ?? []), 'filled'));
    }
}
