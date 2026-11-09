<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\TranzilaDocument;
use App\Models\Finance\TranzilaSubscription;
use App\Models\Finance\TranzilaTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Restaurants that share one MFITGROUP account. The account holder keeps the payments, invoices, card,
 * billing and service for all of them; each restaurant keeps its own billed features.
 */
class FinanceAccounts
{
    /**
     * Brings $restaurant (and any restaurants already linked to it) into $holder's account.
     * Their past payments and invoices move to the account, so it has one history.
     */
    public function link(FinanceClient $holder, FinanceClient $restaurant): FinanceClient
    {
        $holder = $holder->account();
        $joining = $restaurant->account();
        if ($joining->is($holder)) {
            return $holder;
        }

        DB::transaction(function () use ($holder, $joining): void {
            $moving = collect([$joining])->concat($joining->linkedClients()->get());
            $ids = $moving->pluck('id')->all();

            FinancePayment::query()->whereIn('finance_client_id', $ids)->update(['finance_client_id' => $holder->id]);
            FinanceInvoice::withTrashed()->whereIn('finance_client_id', $ids)->update(['finance_client_id' => $holder->id]);
            TranzilaTransaction::query()->whereIn('finance_client_id', $ids)->update(['finance_client_id' => $holder->id]);
            TranzilaDocument::query()->whereIn('finance_client_id', $ids)->update(['finance_client_id' => $holder->id]);
            TranzilaSubscription::query()->whereIn('finance_client_id', $ids)->update(['finance_client_id' => $holder->id]);

            // The account keeps what it has; what it is missing comes from the restaurant that joins.
            $holder->mfit_client_id ??= $joining->mfit_client_id;
            if (blank($holder->card_last4) && filled($joining->card_last4)) {
                $holder->forceFill($joining->only(['card_brand', 'card_last4', 'card_expiry', 'card_holder', 'tranzila_token']) + ['has_payment_method' => true]);
            }
            $holder->next_billing_date ??= $joining->next_billing_date;
            $holder->save();

            FinanceClient::query()->whereIn('id', $ids)->update([
                'billing_client_id' => $holder->id,
                'mfit_client_id' => $holder->mfit_client_id,
            ]);
        });

        return $holder->refresh();
    }

    /**
     * Takes a restaurant out of the account. The shared history stays with the account.
     */
    public function unlink(FinanceClient $restaurant): void
    {
        $restaurant->forceFill(['billing_client_id' => null, 'mfit_client_id' => null])->save();
    }

    /**
     * Sets the account's MFITGROUP id. When another account already has it, this restaurant joins that one.
     *
     * @return FinanceClient the account the restaurant is now in
     */
    public function setMfitId(FinanceClient $client, ?int $mfitId): FinanceClient
    {
        $account = $client->account();
        if ($mfitId !== null) {
            $other = FinanceClient::query()
                ->whereNull('billing_client_id')
                ->where('mfit_client_id', $mfitId)
                ->whereKeyNot($account->id)
                ->orderBy('id')
                ->first();
            if ($other !== null) {
                return $this->link($other, $account);
            }
        }

        DB::transaction(function () use ($account, $mfitId): void {
            $account->forceFill(['mfit_client_id' => $mfitId])->save();
            FinanceClient::query()->where('billing_client_id', $account->id)->update(['mfit_client_id' => $mfitId]);
        });

        return $account;
    }
}
