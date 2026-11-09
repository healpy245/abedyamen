<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteFile;
use App\Models\Sales\SalesQuoteItem;
use BackedEnum;

/**
 * The quote as Finance's deal page and the agent's quote panel both show it.
 */
trait QuotePayload
{
    /**
     * @return array{value: ?string, label: ?string}
     */
    private static function state(?BackedEnum $state): array
    {
        return [
            'value' => $state?->value,
            'label' => $state !== null && method_exists($state, 'label') ? $state->label() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteRow(SalesQuote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'title' => $quote->title(),
            'status' => self::state($quote->status),
            'locked' => $quote->isLocked(),
            'client' => $quote->client === null ? null : ['id' => $quote->client->id, 'name' => $quote->client->name, 'logo' => $quote->client->logo],
            'lead' => $quote->lead === null ? null : ['id' => $quote->lead->id, 'name' => $quote->lead->name],
            'customer_name' => $quote->customerName(),
            'contact_name' => $quote->contact_name,
            'agent' => $quote->agent?->name,
            'monthly_total' => (float) $quote->monthly_total,
            'one_time_total' => (float) $quote->one_time_total,
            'devices_total' => (float) $quote->devices_total,
            'devices_due' => (float) $quote->devices_due,
            'requested_at' => $quote->requested_at?->toIso8601String(),
            'sold_at' => $quote->sold_at?->toIso8601String(),
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteDetail(SalesQuote $quote): array
    {
        $vat = (float) config('finance.vat_rate', 18);

        return $this->quoteRow($quote) + [
            'finance_client_id' => $quote->finance_client_id,
            'notes' => $quote->notes,
            'lang' => $quote->lang,
            'deal_note' => $quote->deal_note,
            'finance_note' => $quote->finance_note,
            'confirmer' => $quote->confirmer?->name,
            'vat_rate' => $vat,
            'devices_with_vat' => round((float) $quote->one_time_total * (1 + $vat / 100), 2),
            'invoice_number' => $quote->invoice?->displayNumber(),
            'lines' => $quote->items->map(fn (SalesQuoteItem $item): array => [
                'id' => $item->id,
                'kind' => $item->kind,
                'ref_id' => $item->sales_catalog_item_id ?? $item->storage_item_id,
                'name' => $item->name,
                'quantity' => (float) $item->quantity,
                'quantity_label' => $item->quantityLabel(),
                'unit_price' => (float) $item->unit_price,
                'billing' => $item->billing,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
            'files' => $quote->files->map(fn (SalesQuoteFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime' => $file->mime,
                'url' => route('workspace.agents.sales.file', $file, false),
            ])->values()->all(),
        ];
    }
}
