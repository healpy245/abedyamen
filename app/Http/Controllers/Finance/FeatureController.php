<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuoteItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The monthly price list agents sell from: the base subscription and the features added to it.
 * Managed by Finance on the storage page; quotes keep the name and price they were made with.
 */
class FeatureController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        SalesCatalogItem::query()->create($data + [
            'kind' => 'feature',
            'sort' => (int) SalesCatalogItem::query()->where('kind', 'feature')->max('sort') + 1,
            'active' => true,
        ]);

        return $this->back(__('finance.features.saved'));
    }

    public function update(Request $request, SalesCatalogItem $feature): RedirectResponse
    {
        abort_unless(in_array($feature->kind, ['base', 'feature'], true), 404);
        $data = $this->validated($request);
        // The base subscription is always billed monthly.
        if ($feature->kind === 'base') {
            $data['billing'] = 'monthly';
        }
        $feature->update($data);

        return $this->back(__('finance.features.saved'));
    }

    /**
     * Features already on quotes are hidden instead of removed so those quotes keep their lines.
     * The base subscription stays.
     */
    public function destroy(SalesCatalogItem $feature): RedirectResponse
    {
        abort_unless($feature->kind === 'feature', 404);

        if (SalesQuoteItem::query()->where('sales_catalog_item_id', $feature->id)->exists()) {
            $feature->update(['active' => false]);
        } else {
            $feature->delete();
        }

        return $this->back(__('finance.features.removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'billing' => ['required', Rule::in(SalesCatalogItem::BILLINGS)],
            'note' => ['nullable', 'string', 'max:191'],
            'bullets' => ['nullable', 'string', 'max:3000'],
            'popular' => ['nullable', 'boolean'],
        ]);

        $data['bullets'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($data['bullets'] ?? '')) ?: []), 'strlen'));
        $data['popular'] = $request->boolean('popular');

        return $data;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('workspace.finance.storage', ['tab' => 'features'])->with('success', $message);
    }
}
