<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\StorageCategory;
use App\Enums\Sales\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Finance\FinanceClient;
use App\Services\Finance\RestaurantFinance;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\StorageItem;
use App\Models\Finance\StorageMovement;
use App\Models\Sales\SalesQuote;
use App\Services\Finance\FinanceClientDirectory;
use App\Services\Finance\FinanceDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    use QuotePayload;

    public function dashboard(FinanceClientDirectory $directory, FinanceDashboard $dashboard): JsonResponse
    {
        $directory->syncIfStale();
        $dash = $dashboard->build();

        return response()->json([
            'mrr' => $dash['mrr'],
            'collected' => $dash['collected'],
            'delta' => $dash['delta'],
            'active' => $dash['active'],
            'total' => $dash['total'],
            'failed' => $dash['failed'],
            'no_method' => $dash['no_method'],
            'hardware_due' => $dash['hardware_due'],
            'trend' => $dash['trend'],
            'trend_max' => $dash['trend_max'],
            'payment_mix' => array_map(fn (array $row): array => [
                'status' => self::state($row['status']),
                'count' => $row['count'],
            ], $dash['payment_mix']),
            'low_stock' => $dash['low_stock']->map(fn (StorageItem $item): array => $this->itemRow($item))->values()->all(),
            'recent' => $dash['recent']->map(fn (FinanceInvoice $invoice): array => $this->invoiceRow($invoice) + [
                'client' => $invoice->client?->name,
                'logo' => $invoice->client?->logo,
            ])->values()->all(),
        ]);
    }

    public function clients(FinanceClientDirectory $directory): JsonResponse
    {
        $directory->syncIfStale();

        // One row per finance account, failed first, as on the web page. The app filters them in place.
        $clients = FinanceClient::query()
            ->whereNull('billing_client_id')
            ->with('linkedClients')
            ->orderByRaw("case when payment_status = 'failed' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        return response()->json([
            'clients' => $clients->map(fn (FinanceClient $client): array => $this->clientRow($client))->values()->all(),
            'totals' => [
                'all' => $clients->count(),
                'failed' => $clients->where('payment_status', \App\Enums\Finance\PaymentStatus::Failed)->count(),
            ],
        ]);
    }

    public function client(FinanceClient $client): JsonResponse
    {
        $client = $client->account();
        $client->load(['payments.files', 'invoices', 'linkedClients']);

        $owed = SalesQuote::query()
            ->whereIn('finance_client_id', $client->accountRestaurants()->pluck('id')->all())
            ->where('status', QuoteStatus::Sold)
            ->where('devices_due', '>', 0)
            ->orderBy('sold_at')
            ->get();

        return response()->json($this->clientRow($client) + [
            'contact_name' => $client->contact_name,
            'business_number' => $client->business_number,
            'lang' => $client->lang,
            'notes' => $client->notes,
            'mfit_client_id' => $client->mfit_client_id,
            'site_url' => $client->siteUrl(),
            'failed_notice_at' => $client->failed_notice_at?->toIso8601String(),
            // Only what the web page keeps: the brand, last digits, expiry and holder.
            'card' => $client->card_last4 === null ? null : [
                'brand' => $client->card_brand,
                'last4' => $client->card_last4,
                'expiry' => $client->card_expiry,
                'holder' => $client->card_holder,
            ],
            'billing_lines' => $client->accountBillingLines(),
            'payments' => $client->payments->map(fn (FinancePayment $payment): array => [
                'id' => $payment->id,
                'number' => $payment->displayNumber(),
                'period' => $payment->period?->format('Y-m'),
                'amount' => (float) $payment->amount,
                'status' => self::state($payment->status),
                'method' => self::state($payment->method),
                'paid_on' => $payment->paid_on?->toDateString(),
                'reference' => $payment->reference,
                'note' => $payment->note,
                'open' => $payment->isOpen(),
                'files' => $payment->files->count(),
            ])->values()->all(),
            'invoices' => $client->invoices->map(fn (FinanceInvoice $invoice): array => $this->invoiceRow($invoice))->values()->all(),
            'devices_owed' => $owed->map(fn (SalesQuote $quote): array => [
                'id' => $quote->id,
                'title' => $quote->title(),
                'devices_due' => (float) $quote->devices_due,
            ])->values()->all(),
            'payment_methods' => array_map(fn (PaymentMethod $method): array => self::state($method), PaymentMethod::cases()),
        ]);
    }

    public function deals(): JsonResponse
    {
        $relations = ['client', 'agent:id,name', 'items', 'files', 'invoice', 'confirmer:id,name'];

        $pending = SalesQuote::query()
            ->with($relations)
            ->where('status', QuoteStatus::Pending)
            ->orderBy('requested_at')
            ->get();

        $recent = SalesQuote::query()
            ->with($relations)
            ->whereNotNull('requested_at')
            ->whereIn('status', [QuoteStatus::Sold, QuoteStatus::Returned])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        return response()->json([
            'pending' => $pending->map(fn (SalesQuote $quote): array => $this->quoteDetail($quote))->values()->all(),
            'recent' => $recent->map(fn (SalesQuote $quote): array => $this->quoteDetail($quote))->values()->all(),
        ]);
    }

    public function storage(): JsonResponse
    {
        $items = StorageItem::query()->where('active', true)->orderBy('category')->orderBy('name')->get();
        $tracked = $items->where('track_stock', true);

        return response()->json([
            'items' => $items->map(fn (StorageItem $item): array => $this->itemRow($item))->values()->all(),
            'stats' => [
                'items' => $items->count(),
                'units' => (int) $tracked->sum('stock'),
                'value' => round((float) $tracked->sum(fn (StorageItem $item): float => $item->stock * (float) $item->unit_price), 2),
                'low' => $items->filter(fn (StorageItem $item): bool => $item->isLow())->count(),
            ],
            'categories' => array_map(fn (StorageCategory $category): array => self::state($category), StorageCategory::cases()),
            'movements' => StorageMovement::query()->with(['item:id,name', 'user:id,name'])->latest('id')->limit(8)->get()
                ->map(fn (StorageMovement $movement): array => [
                    'id' => $movement->id,
                    'item' => $movement->item?->name,
                    'change' => (int) $movement->change,
                    'reason' => $movement->reason,
                    'note' => $movement->note,
                    'user' => $movement->user?->name,
                    'at' => $movement->created_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientRow(FinanceClient $client): array
    {
        return [
            'id' => $client->id,
            'key' => RestaurantFinance::key($client),
            'name' => $client->name,
            'subdomain' => $client->subdomain,
            'logo' => $client->logo,
            'phone' => $client->phone,
            'service_status' => self::state($client->service_status),
            'payment_status' => self::state($client->payment_status),
            'hardware_status' => self::state($client->hardware_status),
            'has_payment_method' => (bool) $client->has_payment_method,
            'monthly_fee' => $client->accountMonthlyFee(),
            'next_billing_date' => $client->next_billing_date?->toDateString(),
            'linked' => $client->linkedClients->pluck('name')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(FinanceInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->displayNumber(),
            'type' => self::state($invoice->type),
            'issued_on' => $invoice->issued_on?->toDateString(),
            'total' => (float) $invoice->total,
            'url' => route('workspace.finance.invoices.show', $invoice, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(StorageItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->localName(),
            'name_raw' => $item->name,
            'sku' => $item->sku,
            'category' => self::state($item->category),
            'unit_price' => (float) $item->unit_price,
            'track_stock' => (bool) $item->track_stock,
            'stock' => (int) $item->stock,
            'low_stock' => (int) $item->low_stock,
            'low' => $item->isLow(),
        ];
    }
}
