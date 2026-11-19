<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\Finance\StorageCategory;
use App\Enums\Sales\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\QuoteController;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\StorageItem;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuote;
use App\Models\User;
use App\Services\Finance\FinanceClientDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentsController extends Controller
{
    use QuotePayload;

    public function sales(Request $request, FinanceClientDirectory $directory, QuoteController $quotes): JsonResponse
    {
        $user = $this->agent($request);
        $directory->syncIfStale();

        // An agent sees their own quotes; an admin sees everyone's.
        $visible = SalesQuote::query()->when(! $user->managesAgents(), fn ($query) => $query->where('agent_id', $user->id));
        $sold = (clone $visible)->where('status', QuoteStatus::Sold);

        return response()->json([
            'quotes' => (clone $visible)
                ->with(['client:id,name,logo', 'lead:id,name', 'agent:id,name'])
                ->orderByDesc('number')
                ->get()
                ->map(fn (SalesQuote $quote): array => $this->quoteRow($quote))
                ->values()
                ->all(),
            'stats' => [
                'open' => (clone $visible)->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Returned])->count(),
                'pending' => (clone $visible)->where('status', QuoteStatus::Pending)->count(),
                'sold' => (clone $sold)->count(),
                'monthly' => (float) (clone $sold)->sum('monthly_total'),
                'one_time' => (float) (clone $sold)->sum('one_time_total'),
            ],
            'clients' => FinanceClient::query()->orderBy('name')->get(['id', 'name', 'contact_name', 'phone', 'logo']),
            'catalog' => $quotes->catalog(),
        ]);
    }

    public function quote(Request $request, SalesQuote $quote): JsonResponse
    {
        $user = $this->agent($request);
        abort_unless($user->managesAgents() || $quote->agent_id === $user->id, 403);

        $quote->load(['client', 'lead:id,name', 'agent:id,name', 'items', 'invoice', 'files', 'confirmer:id,name']);

        return response()->json($this->quoteDetail($quote));
    }

    public function packages(Request $request): JsonResponse
    {
        $this->agent($request);

        $offer = fn (SalesCatalogItem $item): array => [
            'id' => $item->id,
            'name' => $item->localName(),
            'price' => (float) $item->price,
            'billing' => $item->billing,
            'note' => $item->note,
            'bullets' => $item->bulletList(),
            'popular' => (bool) $item->popular,
        ];
        $base = SalesCatalogItem::query()->listed('base')->first();

        return response()->json([
            'base' => $base === null ? null : $offer($base),
            'features' => SalesCatalogItem::query()->listed('feature')->get()->map($offer)->values()->all(),
            'equipment' => StorageItem::query()
                ->where('active', true)
                ->where('category', '!=', StorageCategory::Subscription)
                ->orderByRaw("case when category = 'hardware' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(fn (StorageItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->localName(),
                    'price' => (float) $item->unit_price,
                    'category' => self::state($item->category),
                    'stock' => $item->track_stock ? (int) $item->stock : null,
                ])->values()->all(),
        ]);
    }

    private function agent(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null || $user->isChatbotOnly(), 403);

        return $user;
    }
}
