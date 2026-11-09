<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Finance\InvoiceType;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\StorageItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly StorageLedger $ledger,
    ) {}

    /**
     * A line names a storage item, or carries its own description for things that are not in storage.
     *
     * @param  list<array{storage_item_id?: int|null, description?: string|null, quantity: float|int|string, unit_price: float|int|string}>  $lines
     */
    public function create(
        FinanceClient $client,
        InvoiceType $type,
        Carbon $issuedOn,
        array $lines,
        ?string $notes,
        ?User $user,
    ): FinanceInvoice {
        return DB::transaction(function () use ($client, $type, $issuedOn, $lines, $notes, $user): FinanceInvoice {
            $items = StorageItem::query()
                ->whereIn('id', array_filter(array_column($lines, 'storage_item_id')))
                ->get()
                ->keyBy('id');

            $rows = [];
            $subtotal = 0.0;
            foreach ($lines as $line) {
                $item = $items->get((int) ($line['storage_item_id'] ?? 0));
                $description = trim((string) ($line['description'] ?? '')) ?: $item?->name;
                if ($description === null || $description === '') {
                    continue;
                }
                $quantity = round((float) $line['quantity'], 2);
                $unitPrice = round((float) $line['unit_price'], 2);
                $lineTotal = round($quantity * $unitPrice, 2);
                $subtotal += $lineTotal;
                $rows[] = compact('item', 'description', 'quantity', 'unitPrice', 'lineTotal');
            }

            $vatRate = $type->hasVat() ? (float) config('finance.vat_rate', 18) : 0.0;
            $vat = round($subtotal * $vatRate / 100, 2);
            $number = (int) FinanceInvoice::withTrashed()->where('type', $type->value)->lockForUpdate()->max('number') + 1;

            $invoice = FinanceInvoice::query()->create([
                'finance_client_id' => $client->account()->id,
                'type' => $type,
                'number' => $number,
                'issued_on' => $issuedOn->toDateString(),
                'subtotal' => round($subtotal, 2),
                'vat_rate' => $vatRate,
                'vat' => $vat,
                'total' => round($subtotal + $vat, 2),
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);

            foreach ($rows as $row) {
                $invoice->items()->create([
                    'storage_item_id' => $row['item']?->id,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unitPrice'],
                    'line_total' => $row['lineTotal'],
                ]);

                $direction = $type->stockDirection();
                if ($direction !== 0 && $row['item'] !== null) {
                    $this->ledger->move(
                        $row['item'],
                        $direction * (int) ceil($row['quantity']),
                        $direction < 0 ? 'sale' : 'return',
                        $user,
                        $invoice,
                    );
                }
            }

            return $invoice;
        });
    }

    /**
     * Soft-deletes the document and puts back whatever stock it moved.
     */
    public function delete(FinanceInvoice $invoice, ?User $user): void
    {
        DB::transaction(function () use ($invoice, $user): void {
            $invoice->loadMissing('items.storageItem');
            $direction = $invoice->type->stockDirection();

            foreach ($invoice->items as $line) {
                if ($direction !== 0 && $line->storageItem !== null) {
                    $this->ledger->move(
                        $line->storageItem,
                        -$direction * (int) ceil((float) $line->quantity),
                        'adjust',
                        $user,
                        $invoice,
                        __('finance.storage.reversed', ['number' => $invoice->displayNumber()]),
                    );
                }
            }

            $invoice->delete();
        });
    }
}
