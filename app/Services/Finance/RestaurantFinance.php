<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Door;
use App\Enums\Sales\QuoteStatus;
use App\Models\Finance\FinanceClient;
use App\Models\User;

/**
 * What the restaurant modal in the Agents and Overview doors shows on its Finance tab, read from
 * MFITGROUP Finance. Keyed by kaman.rest company id, the id those pages use for a restaurant.
 */
class RestaurantFinance
{
    /**
     * The same, but nothing for someone whose restaurant card keeps its Finance section locked.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forUser(?User $user): array
    {
        return $user?->canSeeRestaurantSection(Door::Finance) ? $this->byCompany() : [];
    }

    /**
     * @return array<string, array{clientId: int, monthly: float, nextPayment: ?string, devicesDue: float, items: list<array{name: string, amount: float}>, invoices: list<array{number: string, issued: ?string, amount: float, status: string}>}>
     */
    public function byCompany(): array
    {
        $clients = FinanceClient::query()
            ->with([
                'invoices' => fn ($query) => $query->limit(200),
                'salesQuotes' => fn ($query) => $query->where('status', QuoteStatus::Sold)->where('devices_due', '>', 0),
            ])
            ->get()
            ->keyBy('id');

        // Restaurants sharing an MFITGROUP account all show that account's finance.
        $linked = $clients->whereNotNull('billing_client_id')->groupBy('billing_client_id');
        foreach ($clients as $client) {
            $client->setRelation('linkedClients', ($linked->get($client->id) ?? collect())->sortBy('name')->values());
            $client->setRelation('billingClient', $client->billing_client_id !== null ? $clients->get($client->billing_client_id) : null);
        }

        return $clients->mapWithKeys(function (FinanceClient $client): array {
            $account = $client->account();
            $restaurants = $account->accountRestaurants();

            return [self::key($client) => [
                'clientId' => $account->id,
                'monthly' => $account->accountMonthlyFee(),
                'nextPayment' => $account->next_billing_date?->toDateString(),
                'devicesDue' => round((float) $restaurants->sum(fn (FinanceClient $restaurant): float => (float) $restaurant->salesQuotes->sum('devices_due')), 2),
                'items' => $account->accountBillingLines(),
                'invoices' => $account->invoices->take(6)->map(fn ($invoice): array => [
                    'number' => $invoice->displayNumber(),
                    'issued' => $invoice->issued_on?->toDateString(),
                    'amount' => (float) $invoice->total,
                    'status' => 'paid',
                ])->values()->all(),
            ]];
        })->all();
    }

    /**
     * Links the restaurant modal outside the Finance door needs: its read-only Finance sections, and
     * for people who can open the Finance door, the client there.
     *
     * @return array{preview: ?string, open: ?string, locked: bool, lockedLabel: string}
     */
    public static function links(?User $user): array
    {
        $visible = $user?->canSeeRestaurantSection(Door::Finance) ?? false;

        return [
            'preview' => $visible ? route('workspace.restaurant.finance', ['client' => '__ID__']) : null,
            'open' => $visible && $user->canOpenSubDoor(Door::Finance, 'clients') ? route('workspace.finance.clients', ['open' => '__ID__']) : null,
            'locked' => ! $visible,
            'lockedLabel' => __('access.section_locked'),
        ];
    }

    /**
     * The restaurant id a Finance client goes by. A client not linked to a kaman.rest company gets its own.
     */
    public static function key(FinanceClient $client): string
    {
        return filled($client->company_id) ? (string) $client->company_id : 'fin-'.$client->id;
    }
}
