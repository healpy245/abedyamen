<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\Sales\LeadStage;
use App\Models\Finance\FinanceClient;
use App\Models\Sales\AgentLead;
use App\Models\Sales\SalesQuote;
use Illuminate\Support\Facades\DB;

/**
 * A lead becomes a client in two steps: the boarding site creates the restaurant, which reaches Finance
 * from kaman.rest, and the agent links the lead to it here. Billing needs a real client, so a stage of
 * "won" alone does not count: only that link lets a lead's quote go to Finance.
 */
class LeadConversion
{
    public function convert(AgentLead $lead, FinanceClient $client): void
    {
        DB::transaction(function () use ($lead, $client): void {
            $lead->forceFill(['restaurant_id' => $client->company_id, 'stage' => LeadStage::Won])->save();
            $lead->setRelation('client', $client);

            // The client's number is remembered for next time, as when a quote goes out on WhatsApp.
            if (blank($client->phone) && filled($lead->phone)) {
                $client->forceFill(['phone' => $lead->phone])->save();
            }

            $lead->quotes()->get()->each(fn (SalesQuote $quote) => $this->moveToClient($quote, $client));
        });
    }

    /**
     * Whether the quote is ready for Finance. The lead may have been converted since the quote was
     * saved, from another of its quotes or the leads page, so the quote moves onto the client here.
     */
    public function settle(SalesQuote $quote): bool
    {
        if ($quote->finance_client_id !== null) {
            return true;
        }

        $client = $quote->lead?->client;
        if ($client === null) {
            return false;
        }
        $this->moveToClient($quote, $client);

        return true;
    }

    private function moveToClient(SalesQuote $quote, FinanceClient $client): void
    {
        $quote->forceFill([
            'finance_client_id' => $client->id,
            'agent_lead_id' => null,
            'contact_name' => $client->contact_name ?: $quote->contact_name,
            'lang' => $client->lang === 'ar' ? 'ar' : 'he',
        ])->save();
        $quote->setRelation('client', $client);
        $quote->setRelation('lead', null);
    }
}
