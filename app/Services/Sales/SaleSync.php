<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\InvoiceType;
use App\Enums\Sales\QuoteStatus;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Sales\SalesQuote;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\StorageLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing a sale hands it to MFITGROUP Finance in one step: the monthly lines join the client's
 * monthly billing, and the one-time lines become a confirmed tax invoice that takes equipment out of stock.
 * If anything fails (for example not enough stock) nothing is saved and the quote stays as it was.
 */
class SaleSync
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly StorageLedger $ledger,
    ) {}

    /**
     * @param  float|null  $devicesPaid  What the client already paid for the devices, VAT included. Null means paid in full.
     */
    public function markSold(SalesQuote $quote, ?User $user, ?float $devicesPaid = null): SalesQuote
    {
        return DB::transaction(function () use ($quote, $user, $devicesPaid): SalesQuote {
            /** @var SalesQuote $quote */
            $quote = SalesQuote::query()->lockForUpdate()->with('items.storageItem')->findOrFail($quote->id);
            if ($quote->synced_at !== null) {
                return $quote;
            }

            $client = FinanceClient::query()->lockForUpdate()->findOrFail($quote->finance_client_id);
            $monthly = $quote->items->filter->isMonthly();
            $oneTime = $quote->items->reject->isMonthly();

            if ($monthly->isNotEmpty()) {
                $billing = $client->billingLines();
                foreach ($monthly as $line) {
                    $billing[] = ['name' => $line->name, 'amount' => (float) $line->line_total];
                }
                $client->billing_items = $billing;
                $client->monthly_fee = round(array_sum(array_column($billing, 'amount')), 2);
                // The account's next billing date (the restaurant's own when it has no shared account).
                $account = $client->account();
                if ($account->is($client)) {
                    $client->next_billing_date ??= now()->addMonthNoOverflow()->startOfMonth();
                } elseif ($account->next_billing_date === null) {
                    $account->forceFill(['next_billing_date' => now()->addMonthNoOverflow()->startOfMonth()])->save();
                }
            }
            if (filled($quote->contact_name) && blank($client->contact_name)) {
                $client->contact_name = $quote->contact_name;
            }
            $client->save();

            if ($oneTime->isNotEmpty()) {
                $total = $this->withVat((float) $quote->one_time_total);
                $paid = $devicesPaid === null ? $total : max(0.0, min($total, round($devicesPaid, 2)));
                $quote->devices_total = $total;
                $quote->devices_due = round($total - $paid, 2);
                $note = __('sales.sale_number', ['number' => $quote->number]);

                if ($paid >= $total) {
                    // Paid in full: one invoice lists the devices and takes them out of stock.
                    $invoice = $this->invoices->create(
                        $client,
                        InvoiceType::TaxInvoiceReceipt,
                        now(),
                        $oneTime->map(fn ($line): array => [
                            'storage_item_id' => $line->storage_item_id,
                            'description' => $line->name,
                            'quantity' => (float) $line->quantity,
                            'unit_price' => (float) $line->unit_price,
                        ])->values()->all(),
                        $note,
                        $user,
                    );
                } else {
                    // Partly paid: the devices still leave stock now, the invoice covers only what was paid,
                    // and the rest waits on the sale until Finance records the next payment.
                    foreach ($oneTime as $line) {
                        if ($line->storageItem !== null) {
                            $this->ledger->move($line->storageItem, -(int) ceil((float) $line->quantity), 'sale', $user, null, $note);
                        }
                    }
                    $invoice = $paid > 0 ? $this->paymentInvoice($client, $quote, $paid, $user) : null;
                }

                if ($invoice !== null) {
                    $invoice->forceFill(['sales_quote_id' => $quote->id])->save();
                }
                $quote->finance_invoice_id = $invoice?->id;
                $quote->save();
                $this->refreshHardwareStatus($client);
            }

            $quote->status = QuoteStatus::Sold;
            $quote->sold_at ??= now();
            $quote->synced_at = now();
            $quote->save();

            return $quote;
        });
    }

    /**
     * The client pays more towards devices still owed on a sale. Creates the invoice for exactly that amount.
     */
    public function payDevices(SalesQuote $quote, float $amount, ?User $user): FinanceInvoice
    {
        return DB::transaction(function () use ($quote, $amount, $user): FinanceInvoice {
            /** @var SalesQuote $quote */
            $quote = SalesQuote::query()->lockForUpdate()->findOrFail($quote->id);
            $amount = round(min($amount, (float) $quote->devices_due), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('finance.devices.nothing_due')]);
            }

            $client = FinanceClient::query()->findOrFail($quote->finance_client_id);
            $invoice = $this->paymentInvoice($client, $quote, $amount, $user);
            $invoice->forceFill(['sales_quote_id' => $quote->id])->save();
            $quote->forceFill(['devices_due' => round((float) $quote->devices_due - $amount, 2)])->save();
            $this->refreshHardwareStatus($client);

            return $invoice;
        });
    }

    /**
     * A tax invoice-receipt for a device payment of $amount, VAT included.
     */
    private function paymentInvoice(FinanceClient $client, SalesQuote $quote, float $amount, ?User $user): FinanceInvoice
    {
        $rate = (float) config('finance.vat_rate', 18);

        return $this->invoices->create(
            $client,
            InvoiceType::TaxInvoiceReceipt,
            now(),
            [[
                'storage_item_id' => null,
                'description' => __('sales.device_payment_line', ['number' => $quote->number]),
                'quantity' => 1,
                'unit_price' => round($amount / (1 + $rate / 100), 2),
            ]],
            __('sales.sale_number', ['number' => $quote->number]),
            $user,
        );
    }

    /**
     * The client's hardware status in Finance follows what is still owed on their sales.
     */
    private function refreshHardwareStatus(FinanceClient $client): void
    {
        // Across every restaurant in the account, which carries the status.
        $client = $client->account();
        $sales = SalesQuote::query()->whereIn('finance_client_id', $client->accountRestaurants()->pluck('id'))->where('devices_total', '>', 0);
        $due = (float) (clone $sales)->sum('devices_due');
        $total = (float) (clone $sales)->sum('devices_total');

        $client->forceFill([
            'hardware_status' => match (true) {
                $due <= 0 => HardwareStatus::Paid,
                $due >= $total => HardwareStatus::Unpaid,
                default => HardwareStatus::Partial,
            },
        ])->save();
    }

    private function withVat(float $amount): float
    {
        return round($amount * (1 + (float) config('finance.vat_rate', 18) / 100), 2);
    }
}
