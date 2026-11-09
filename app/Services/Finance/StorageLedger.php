<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\StorageItem;
use App\Models\Finance\StorageMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every stock change goes through here so the item count and its movement log never drift apart.
 */
class StorageLedger
{
    public function move(
        StorageItem $item,
        int $change,
        string $reason,
        ?User $user = null,
        ?FinanceInvoice $invoice = null,
        ?string $note = null,
    ): ?StorageMovement {
        if ($change === 0 || ! $item->track_stock) {
            return null;
        }

        return DB::transaction(function () use ($item, $change, $reason, $user, $invoice, $note): StorageMovement {
            $locked = StorageItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($locked->stock + $change < 0) {
                throw ValidationException::withMessages([
                    'stock' => __('finance.errors.not_enough_stock', ['item' => $locked->name, 'stock' => $locked->stock]),
                ]);
            }

            $locked->increment('stock', $change);
            $item->stock = $locked->stock;

            return StorageMovement::query()->create([
                'storage_item_id' => $item->id,
                'change' => $change,
                'reason' => $reason,
                'finance_invoice_id' => $invoice?->id,
                'note' => $note,
                'user_id' => $user?->id,
            ]);
        });
    }
}
