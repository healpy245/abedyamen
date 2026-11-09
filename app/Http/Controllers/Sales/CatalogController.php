<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\Finance\StorageCategory;
use App\Http\Controllers\Controller;
use App\Models\Finance\StorageItem;
use App\Models\Sales\SalesCatalogItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The price list agents sell from. Read-only here: agents see what they can offer, not change it.
 */
class CatalogController extends Controller
{
    public const TABS = ['features', 'equipment'];

    public function index(Request $request, ?string $tab = null): View
    {
        $user = $request->user();
        abort_if($user === null || $user->isChatbotOnly(), 403);

        return view('workspace.agents.packages', [
            'page' => 'packages',
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'features',
            'base' => SalesCatalogItem::query()->listed('base')->first(),
            'features' => SalesCatalogItem::query()->listed('feature')->get(),
            'equipment' => StorageItem::query()
                ->where('active', true)
                ->where('category', '!=', StorageCategory::Subscription)
                ->orderByRaw("case when category = 'hardware' then 0 else 1 end")
                ->orderBy('name')
                ->get(),
        ]);
    }
}
