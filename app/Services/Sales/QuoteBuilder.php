<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Sales\QuoteStatus;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\StorageItem;
use App\Models\Sales\AgentLead;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns the quote form into a quote with priced lines. Names and default prices come from the
 * catalog and storage, so a quote always shows what the agent picked even if the list changes later.
 */
class QuoteBuilder
{
    /**
     * The contact and the document language come from the client's Finance record, so agents never retype them.
     * A lead has only a contact, and a lead that was already converted is quoted as its client.
     *
     * @param  array{finance_client_id?: int|null, agent_lead_id?: int|null, notes?: string|null, lines: list<array<string, mixed>>}  $data
     */
    public function save(?SalesQuote $quote, array $data, User $agent): SalesQuote
    {
        return DB::transaction(function () use ($quote, $data, $agent): SalesQuote {
            $lines = $this->lines($data['lines']);
            $lead = filled($data['agent_lead_id'] ?? null) ? AgentLead::query()->findOrFail($data['agent_lead_id']) : null;
            $client = $lead !== null ? $lead->client : FinanceClient::query()->findOrFail($data['finance_client_id']);

            if ($quote === null) {
                $quote = new SalesQuote([
                    'number' => max(SalesQuote::FIRST_NUMBER - 1, (int) SalesQuote::query()->lockForUpdate()->max('number')) + 1,
                    'status' => QuoteStatus::Draft,
                    'agent_id' => $agent->id,
                ]);
            }

            $quote->fill([
                'finance_client_id' => $client?->id,
                'agent_lead_id' => $client === null ? $lead?->id : null,
                'contact_name' => $client !== null ? $client->contact_name : (mb_substr((string) $lead?->contact, 0, 120) ?: null),
                'lang' => $client?->lang === 'ar' ? 'ar' : 'he',
                'notes' => $data['notes'] ?? null,
                'monthly_total' => round(array_sum(array_map(fn ($l) => $l['billing'] === 'monthly' ? $l['line_total'] : 0, $lines)), 2),
                'one_time_total' => round(array_sum(array_map(fn ($l) => $l['billing'] === 'one_time' ? $l['line_total'] : 0, $lines)), 2),
            ])->save();

            $quote->items()->delete();
            foreach ($lines as $i => $line) {
                $quote->items()->create($line + ['sort' => $i]);
            }

            return $quote->load('items');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return list<array{kind: string, sales_catalog_item_id: ?int, storage_item_id: ?int, name: string, quantity: float, unit_price: float, billing: string, line_total: float}>
     */
    private function lines(array $input): array
    {
        $catalog = SalesCatalogItem::query()
            ->whereIn('id', array_filter(array_column($input, 'ref_id')))
            ->get()
            ->keyBy('id');
        $storage = StorageItem::query()
            ->whereIn('id', array_filter(array_column($input, 'ref_id')))
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($input as $row) {
            $kind = (string) $row['kind'];
            $refId = (int) ($row['ref_id'] ?? 0);
            $catalogItem = in_array($kind, SalesCatalogItem::KINDS, true) ? $catalog->get($refId) : null;
            $storageItem = in_array($kind, SalesQuoteItem::STORAGE_KINDS, true) ? $storage->get($refId) : null;

            if (in_array($kind, SalesCatalogItem::KINDS, true) && ($catalogItem === null || $catalogItem->kind !== $kind)) {
                continue;
            }
            if (in_array($kind, SalesQuoteItem::STORAGE_KINDS, true) && $storageItem === null) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? '')) ?: ($catalogItem?->name ?? $storageItem?->name ?? '');
            if ($name === '') {
                continue;
            }

            $billing = match ($kind) {
                'equipment', 'service' => 'one_time',
                'custom' => in_array($row['billing'] ?? null, SalesCatalogItem::BILLINGS, true) ? $row['billing'] : 'monthly',
                default => $catalogItem->billing,
            };
            $price = isset($row['unit_price']) && $row['unit_price'] !== ''
                ? (float) $row['unit_price']
                : (float) ($catalogItem?->price ?? $storageItem?->unit_price ?? 0);
            $quantity = $kind === 'equipment' ? max(1, (float) ($row['quantity'] ?? 1)) : 1.0;

            $lines[] = [
                'kind' => $kind,
                'sales_catalog_item_id' => $catalogItem?->id,
                'storage_item_id' => $storageItem?->id,
                'name' => $name,
                'quantity' => round($quantity, 2),
                'unit_price' => round($price, 2),
                'billing' => $billing,
                'line_total' => round($quantity * $price, 2),
            ];
        }

        return $lines;
    }
}
