<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\Finance\StorageCategory;
use App\Models\Finance\StorageItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by create and edit. Opening stock is only taken on create; later changes go through adjust.
 */
class StoreStorageItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canOpenDoor(\App\Enums\Door::Finance) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('storage_items', 'sku')->ignore($item instanceof StorageItem ? $item->id : null)],
            'category' => ['required', Rule::enum(StorageCategory::class)],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'track_stock' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
