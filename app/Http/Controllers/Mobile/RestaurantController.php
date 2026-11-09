<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\Door;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Models\Finance\FinanceClient;
use App\Models\User;
use App\Services\Finance\RestaurantFinance;
use App\Services\KamanSupport\RestaurantSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The restaurant modal for the app, the same one the web opens from the Overview, Agents, Support and
 * Finance doors. So it sits behind any of those doors, and its Finance and Support sections keep the
 * web's own locks. The restaurant can be asked for by company id, Support slug, sup-<slug> or fin-<id>.
 */
class RestaurantController extends Controller
{
    public function show(Request $request, string $restaurant, AgentsWorkspaceController $catalog, RestaurantSupport $support, FinanceController $finance): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isChatbotOnly(), 403);
        abort_unless(
            $user->canOpen(Door::Overview, 'restaurants') || $user->canOpen(Door::Agents) || $user->canOpen(Door::Support) || $user->canOpen(Door::Finance, 'clients'),
            403
        );

        $data = $catalog->catalog();
        $row = $this->find($restaurant, $data['companies'], $support, $user);
        abort_if($row === null, 404);

        return response()->json([
            'restaurant' => $row,
            'cities' => $data['cities'],
            'links' => $this->links($row['id']),
            'finance' => $this->financeSection($request, $user, $row['id'], $finance),
            'support' => $this->supportSection($user, $row['id'], $support),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $companies
     * @return array<string, mixed>|null
     */
    private function find(string $key, array $companies, RestaurantSupport $support, User $user): ?array
    {
        $slug = mb_strtolower(trim($key));
        foreach ($companies as $company) {
            if ($company['id'] === $key || ($slug !== '' && mb_strtolower(trim((string) $company['subdomain'])) === $slug)) {
                return $company;
            }
        }

        foreach ($support->extraRestaurants($user) as $extra) {
            if ($extra['id'] === $key || $extra['id'] === 'sup-'.$slug) {
                return $this->standIn($extra['id'], (string) $extra['name']['en'], (string) $extra['name']['logo']);
            }
        }

        $client = $this->financeClient($key);

        return $client === null ? null : $this->standIn(RestaurantFinance::key($client), (string) $client->name, (string) $client->logo);
    }

    /**
     * A restaurant only Support or Finance knows, shaped like a kaman.rest company.
     *
     * @return array<string, mixed>
     */
    private function standIn(string $id, string $name, string $logo): array
    {
        return [
            'id' => $id, 'name' => $name, 'subdomain' => '', 'url' => '', 'color' => '', 'lang' => '',
            'address' => '', 'logo' => $logo, 'hours' => '', 'cityId' => '',
        ];
    }

    private function financeClient(string $key): ?FinanceClient
    {
        return str_starts_with($key, 'fin-')
            ? FinanceClient::query()->find((int) substr($key, 4))
            : FinanceClient::query()->where('company_id', $key)->first();
    }

    /**
     * Only real kaman.rest companies have a page in its admin.
     *
     * @return array{admin: ?string, enter: ?string}
     */
    private function links(string $id): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            return ['admin' => null, 'enter' => null];
        }

        return [
            'admin' => 'https://admin.kaman.rest/admin/companies/'.rawurlencode($id).'/edit#general',
            'enter' => 'https://admin.kaman.rest/admin/companies/login-as/'.rawurlencode($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeSection(Request $request, User $user, string $id, FinanceController $finance): array
    {
        $canOpen = $user->canOpenSubDoor(Door::Finance, 'clients');
        // Inside the Finance door the section is that door's own, whatever the restaurant card shows elsewhere.
        $visible = $user->canSeeRestaurantSection(Door::Finance) || ($canOpen && $request->query('surface') === 'finance');
        if (! $visible) {
            return ['locked' => true, 'lockedLabel' => __('access.section_locked')];
        }

        $client = $this->financeClient($id);

        return [
            'locked' => false,
            'canOpen' => $canOpen,
            'client' => $client === null ? null : Arr::only($finance->client($client)->getData(true), [
                'id', 'name', 'monthly_fee', 'next_billing_date', 'billing_lines', 'payments', 'invoices', 'devices_owed',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supportSection(User $user, string $id, RestaurantSupport $support): array
    {
        $boot = $support->boot($user);
        if ($boot['locked']) {
            return ['locked' => true, 'labels' => $boot['labels']];
        }

        $row = $support->byCompany($user)[$id] ?? [];

        return [
            'locked' => false,
            'live' => $boot['card'] !== null,
            'canOpenLogs' => $boot['logs'] !== null,
            'canOpenTickets' => $boot['ticket'] !== null,
            'slug' => $row['slug'] ?? '',
            'devices' => $row['devices'] ?? [],
            'tickets' => $row['tickets'] ?? [],
            'openTickets' => $row['openTickets'] ?? 0,
            'labels' => $boot['labels'],
        ];
    }
}
