<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\StorageCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreStorageItemRequest;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\StorageItem;
use App\Models\Finance\StorageMovement;
use App\Models\Sales\SalesCatalogItem;
use App\Services\Finance\StorageLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Storage, services and features: devices and their stock, the one-time services agents sell, and the
 * monthly subscription with its features. Everything a quote is priced from.
 */
class StorageController extends Controller
{
    public const TABS = ['storage', 'services', 'features'];

    public function __construct(
        private readonly StorageLedger $ledger,
    ) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'storage';
        $search = trim((string) $request->query('q', ''));
        $category = StorageCategory::tryFrom((string) $request->query('category', ''));
        $lowOnly = $request->boolean('low');

        $items = StorageItem::query()
            ->where('active', true)
            ->when($tab === 'services', fn ($query) => $query->where('category', StorageCategory::Service))
            ->when($tab === 'storage', fn ($query) => $query->where('category', '!=', StorageCategory::Service))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%');
            }))
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($lowOnly, fn ($query) => $query->where('track_stock', true)->whereColumn('stock', '<=', 'low_stock'))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $all = StorageItem::query()->where('active', true)->get(['track_stock', 'stock', 'low_stock', 'unit_price']);

        return view('workspace.finance.storage', [
            'page' => 'storage',
            'tab' => $tab,
            'features' => $tab === 'features'
                ? SalesCatalogItem::query()->whereIn('kind', ['base', 'feature'])->where('active', true)->orderByRaw("case when kind = 'base' then 0 else 1 end")->orderBy('sort')->orderBy('id')->get()
                : collect(),
            'items' => $items,
            'search' => $search,
            'filters' => ['category' => $category?->value ?? '', 'low' => $lowOnly],
            'stats' => [
                'items' => $all->count(),
                'units' => (int) $all->where('track_stock', true)->sum('stock'),
                'value' => round((float) $all->where('track_stock', true)->sum(fn (StorageItem $item): float => $item->stock * (float) $item->unit_price), 2),
                'low' => $all->filter(fn (StorageItem $item): bool => $item->isLow())->count(),
            ],
            'movements' => StorageMovement::query()->with(['item:id,name', 'invoice', 'user:id,name'])->latest('id')->limit(8)->get(),
        ]);
    }

    public function store(StoreStorageItemRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $item = StorageItem::query()->create([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'sku' => $data['sku'] ?? null,
            'category' => $data['category'],
            'unit_price' => $data['unit_price'],
            'track_stock' => $request->boolean('track_stock'),
            'stock' => 0,
            'low_stock' => (int) ($data['low_stock'] ?? 0),
        ]);

        $opening = (int) ($data['stock'] ?? 0);
        if ($item->track_stock && $opening > 0) {
            $this->ledger->move($item, $opening, 'purchase', $request->user(), null, __('finance.storage.opening'));
        }

        return redirect()->route('workspace.finance.storage', $this->tabFor($item))->with('success', __('finance.flash.item_saved'));
    }

    public function update(StoreStorageItemRequest $request, StorageItem $item): RedirectResponse
    {
        $data = $request->validated();
        $item->update([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'sku' => $data['sku'] ?? null,
            'category' => $data['category'],
            'unit_price' => $data['unit_price'],
            'track_stock' => $request->boolean('track_stock'),
            'low_stock' => (int) ($data['low_stock'] ?? 0),
        ]);

        return redirect()->route('workspace.finance.storage', $this->tabFor($item))->with('success', __('finance.flash.item_saved'));
    }

    public function adjust(Request $request, StorageItem $item): RedirectResponse
    {
        $data = $request->validate([
            'change' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', Rule::in(['purchase', 'adjust', 'return'])],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $this->ledger->move($item, (int) $data['change'], $data['reason'], $request->user(), null, $data['note'] ?? null);

        return redirect()->route('workspace.finance.storage')->with('success', __('finance.flash.stock_updated', ['item' => $item->name]));
    }

    /**
     * Items already on documents are hidden instead of removed so old invoices keep their lines.
     */
    public function destroy(StorageItem $item): RedirectResponse
    {
        if (FinanceInvoiceItem::query()->where('storage_item_id', $item->id)->exists()) {
            $item->update(['active' => false]);
        } else {
            $item->delete();
        }

        return redirect()->route('workspace.finance.storage', $this->tabFor($item))->with('success', __('finance.flash.item_removed'));
    }

    /**
     * Back to the tab the item is listed on.
     *
     * @return array<string, string>
     */
    private function tabFor(StorageItem $item): array
    {
        return $item->category === StorageCategory::Service ? ['tab' => 'services'] : [];
    }
}
