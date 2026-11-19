<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Sales\QuoteStatus;
use App\Models\Sales\SalesQuote;
use App\Models\Sales\SalesQuoteFile;
use App\Models\User;
use App\Notifications\SalesDealRequested;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * An agent asks MFITGROUP Finance to close a deal, with payment evidence. Finance confirms it, which
 * starts the client's monthly billing and invoices the devices, or returns it to the agent with a note.
 */
class DealRequests
{
    public function __construct(
        private readonly SaleSync $sync,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function request(SalesQuote $quote, string $note, array $files, User $agent): SalesQuote
    {
        DB::transaction(function () use ($quote, $note, $files): void {
            $quote->forceFill([
                'status' => QuoteStatus::Pending,
                'deal_note' => $note,
                'requested_at' => now(),
                'finance_note' => null,
            ])->save();

            foreach ($files as $upload) {
                $quote->files()->create([
                    'path' => $upload->store('sales/deals/'.$quote->id, SalesQuoteFile::DISK),
                    'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                    'mime' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
                    'size' => (int) $upload->getSize(),
                ]);
            }
        });

        Notification::send(
            User::query()->whereKeyNot($agent->id)->whereNull('disabled_at')->get()
                ->filter(fn (User $user): bool => $user->canOpenSubDoor(\App\Enums\Door::Finance, 'deals'))
                ->values(),
            new SalesDealRequested($quote->loadMissing('client'), $agent),
        );

        return $quote;
    }

    /**
     * Confirming starts billing in Finance. The MFITGROUP system itself is not connected yet, so billing and the
     * invoice are created in Finance here; the MFITGROUP API call belongs in this step once it is configured.
     *
     * @param  float|null  $devicesPaid  Device payment already received, VAT included; null when paid in full.
     */
    public function confirm(SalesQuote $quote, User $financeUser, ?CarbonInterface $nextBilling = null, ?float $devicesPaid = null): SalesQuote
    {
        $quote = DB::transaction(function () use ($quote, $financeUser, $nextBilling, $devicesPaid): SalesQuote {
            $quote = $this->sync->markSold($quote, $financeUser, $devicesPaid);
            $quote->forceFill(['confirmed_by' => $financeUser->id])->save();

            // The date Finance picked in the confirm dialog replaces the default first billing date.
            if ($nextBilling !== null) {
                $quote->client?->forceFill(['next_billing_date' => $nextBilling->toDateString()])->save();
            }

            return $quote;
        });
        $this->clearNotices($quote);

        return $quote;
    }

    public function returnToAgent(SalesQuote $quote, string $note): SalesQuote
    {
        $quote->forceFill([
            'status' => QuoteStatus::Returned,
            'finance_note' => $note,
        ])->save();
        $this->clearNotices($quote);

        return $quote;
    }

    /**
     * Once one Finance user handles a request, it no longer waits in anyone's bell.
     */
    private function clearNotices(SalesQuote $quote): void
    {
        DatabaseNotification::query()
            ->where('type', SalesDealRequested::class)
            ->whereNull('read_at')
            ->where('data->quote_id', $quote->id)
            ->update(['read_at' => now()]);
    }
}
