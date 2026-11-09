<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Http\Controllers\AgentsWorkspaceController;
use App\Models\Finance\FinanceClient;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps one finance record per live kaman.rest restaurant. Only the name, subdomain and logo
 * come from kaman.rest; everything the finance team edits stays as they left it.
 */
class FinanceClientDirectory
{
    /**
     * Pages call this on every load; the real sync runs at most every five minutes.
     */
    public function syncIfStale(): void
    {
        if (Cache::add('finance.clients.synced', true, 300)) {
            $this->sync();
        }
    }

    public function sync(): void
    {
        $companies = app(AgentsWorkspaceController::class)->catalog()['companies'];

        foreach ($companies as $company) {
            $companyId = trim((string) ($company['id'] ?? ''));
            if ($companyId === '') {
                continue;
            }

            $client = FinanceClient::query()->firstOrNew(['company_id' => $companyId]);
            $client->fill([
                'name' => (string) ($company['name'] ?? $client->name),
                'subdomain' => (string) ($company['subdomain'] ?? '') ?: $client->subdomain,
                'logo' => (string) ($company['logo'] ?? '') ?: $client->logo,
            ]);

            if (! $client->exists) {
                $lang = (string) ($company['lang'] ?? '');
                $client->lang = in_array($lang, FinanceClient::LANGUAGES, true) ? $lang : 'he';
            }

            if ($client->isDirty()) {
                $client->save();
            }
        }
    }
}
