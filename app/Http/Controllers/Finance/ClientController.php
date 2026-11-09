<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\InvoiceType;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\ServiceStatus;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UpdateFinanceBillingRequest;
use App\Http\Requests\Finance\UpdateFinanceClientRequest;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\StorageItem;
use App\Models\Sales\SalesQuote;
use App\Services\Finance\FinanceAccounts;
use App\Services\Finance\FinanceClientDirectory;
use App\Services\Finance\FinanceWhatsApp;
use App\Services\Finance\RestaurantFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ClientController extends Controller
{
    public function index(Request $request, FinanceClientDirectory $directory, RestaurantFinance $finance): View
    {
        $directory->syncIfStale();

        $search = trim((string) $request->query('q', ''));
        $payment = PaymentStatus::tryFrom((string) $request->query('payment', ''));
        $service = ServiceStatus::tryFrom((string) $request->query('service', ''));

        // One row per finance account; restaurants sharing an account are listed under it.
        // Every row is on the page: finance-workspace.js searches and filters them in place.
        $clients = FinanceClient::query()
            ->whereNull('billing_client_id')
            ->with('linkedClients')
            ->orderByRaw("case when payment_status = 'failed' then 0 else 1 end")
            ->orderBy('name')
            ->get();
        $searchText = $clients->mapWithKeys(fn (FinanceClient $client): array => [$client->id => self::searchText($client)])->all();
        $needle = mb_strtolower($search);
        $shown = $clients->filter(fn (FinanceClient $client): bool => ($needle === '' || str_contains($searchText[$client->id], $needle))
            && ($payment === null || $client->payment_status === $payment)
            && ($service === null || $client->service_status === $service));

        // A client opens in the restaurant modal of the Overview door, on its Finance section.
        $open = FinanceClient::query()->find($request->integer('open'))?->account();
        $catalog = app(AgentsWorkspaceController::class)->catalog();
        $known = array_flip(array_column($catalog['companies'], 'id'));
        $extra = FinanceClient::query()->get(['id', 'company_id', 'name', 'logo'])
            ->reject(fn (FinanceClient $client): bool => isset($known[RestaurantFinance::key($client)]))
            ->map(fn (FinanceClient $client): array => [
                'id' => RestaurantFinance::key($client),
                'name' => ['en' => $client->name, 'he' => $client->name, 'ar' => $client->name, 'logo' => (string) $client->logo],
                'owner' => '', 'phone' => '', 'cityId' => '', 'address' => ['en' => '', 'he' => '', 'ar' => ''],
                'site' => '', 'agentId' => '', 'status' => 'active', 'onboardedAt' => null, 'notes' => '',
            ])->values()->all();
        $openTab = self::embedTab($request->query('tab'));

        return view('workspace.finance.clients', [
            'page' => 'clients',
            'clients' => $clients,
            'searchText' => $searchText,
            'shown' => $shown->pluck('id')->flip()->all(),
            'search' => $search,
            'filters' => [
                'payment' => $payment?->value ?? '',
                'service' => $service?->value ?? '',
            ],
            'totals' => [
                'all' => FinanceClient::query()->whereNull('billing_client_id')->count(),
                'failed' => FinanceClient::query()->whereNull('billing_client_id')->where('payment_status', PaymentStatus::Failed)->count(),
            ],
            'modal' => $open ? $this->modalData($open, $openTab) + ['embedded' => true] : null,
            'agentsBoot' => [
                'companies' => $catalog['companies'],
                'cities' => $catalog['cities'],
                'finance' => $finance->byCompany(),
                'supportBoot' => app(\App\Services\KamanSupport\RestaurantSupport::class)->boot($request->user()),
                'extraRestaurants' => $extra,
                'financeEmbed' => route('workspace.finance.clients.show', ['client' => '__ID__', 'embed' => 1]),
                'open' => $open ? ['id' => RestaurantFinance::key($open), 'finTab' => $openTab === 'invoices' ? 'hardware' : 'monthly'] : null,
            ],
        ]);
    }

    /**
     * The client modal. With ?embed=1 it is one section (monthly payments or hardware payments)
     * for the Finance part of the restaurant modal.
     */
    public function show(Request $request, FinanceClient $client): View
    {
        $client = $client->account();
        if ($request->boolean('embed')) {
            return view('workspace.finance.client-modal', $this->modalData($client, self::embedTab($request->query('tab'))) + ['embedded' => true]);
        }

        return view('workspace.finance.client-modal', $this->modalData($client, self::tab($request->query('tab'))));
    }

    /**
     * A section of the client, read-only, for the restaurant modal in the other doors.
     * Changing anything happens in the Finance door.
     */
    public function preview(Request $request, FinanceClient $client): View
    {
        abort_unless($request->user()?->canSeeRestaurantSection(\App\Enums\Door::Finance), 403);
        $client = $client->account();

        return view('workspace.finance.client-modal', $this->modalData($client, self::embedTab($request->query('tab')), true) + ['embedded' => true, 'readonly' => true]);
    }

    /**
     * Adds a restaurant to this restaurant's MFITGROUP account.
     */
    public function link(Request $request, FinanceClient $client, FinanceAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', Rule::exists('finance_clients', 'id')],
        ]);
        $restaurant = FinanceClient::query()->findOrFail($data['restaurant_id']);
        $account = $accounts->link($client, $restaurant);

        return $this->backToClient($account, 'payments')->with('success', __('finance.account.linked', ['name' => $restaurant->name]));
    }

    /**
     * Takes a restaurant out of the account; the shared history stays with the account.
     */
    public function unlink(FinanceClient $client, FinanceClient $restaurant, FinanceAccounts $accounts): RedirectResponse
    {
        $account = $client->account();
        abort_unless($restaurant->billing_client_id === $account->id, 404);
        $accounts->unlink($restaurant);

        return $this->backToClient($account, 'payments')->with('success', __('finance.account.unlinked', ['name' => $restaurant->name]));
    }

    /**
     * The account's MFITGROUP id. A restaurant given an id another account has joins that account.
     */
    public function updateMfit(Request $request, FinanceClient $client, FinanceAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'mfit_client_id' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ]);
        $before = $client->account();
        $account = $accounts->setMfitId($client, isset($data['mfit_client_id']) ? (int) $data['mfit_client_id'] : null);

        return $this->backToClient($account, 'payments')->with('success', $account->is($before)
            ? __('finance.account.mfit_saved')
            : __('finance.account.joined', ['name' => $account->name]));
    }

    public function update(UpdateFinanceClientRequest $request, FinanceClient $client): RedirectResponse
    {
        $client->fill($request->validated())->save();

        return $this->backToClient($client, 'details')->with('success', __('finance.flash.client_saved'));
    }

    /**
     * The header switch. Turning service off is confirmed in the browser first.
     */
    public function updateService(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'service_status' => ['required', Rule::enum(ServiceStatus::class)],
        ]);
        $client->forceFill(['service_status' => ServiceStatus::from($data['service_status'])])->save();

        $tab = in_array($request->input('tab'), ['details', 'payments', 'invoices'], true) ? $request->input('tab') : 'details';

        return $this->backToClient($client, $tab)->with('success', __('finance.flash.service_'.$client->service_status->value));
    }

    public function updateBilling(UpdateFinanceBillingRequest $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validated();

        // Billed features and the monthly fee are set from the restaurant profile, never from here.
        // Whether a payment method is on file follows the saved card, so it is not set here.
        // Monthly billing on/off has its own switch; failed is set by the charges, not by hand.
        $client->forceFill(array_filter([
            'payment_status' => isset($data['payment_status']) ? PaymentStatus::from($data['payment_status']) : null,
            'next_billing_date' => $data['next_billing_date'] ?? null,
        ], fn ($value, string $key): bool => $value !== null || $key === 'next_billing_date', ARRAY_FILTER_USE_BOTH))->save();

        return $this->backToClient($client, 'payments')->with('success', __('finance.flash.billing_saved'));
    }

    /**
     * The monthly billing switch. Turning it on clears a failed state; the failure stays in the history.
     */
    public function updateBillingState(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'billing' => ['required', Rule::in(['on', 'off'])],
        ]);
        $client->forceFill(['payment_status' => $data['billing'] === 'on' ? PaymentStatus::Active : PaymentStatus::Inactive])->save();

        return $this->backToClient($client, 'payments')->with('success', __('finance.flash.billing_'.$data['billing']));
    }

    /**
     * Saves the client's card. The browser sends only the brand, last 4 digits, expiry and holder;
     * the full number and CVV never leave it. Charging will go through the payment provider's API.
     */
    public function saveCard(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'card_brand' => ['required', Rule::in(FinanceClient::CARD_BRANDS)],
            'card_last4' => ['required', 'digits:4'],
            'card_expiry' => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'card_holder' => ['required', 'string', 'max:80'],
        ], [
            'card_last4.*' => __('finance.card.invalid_number'),
            'card_expiry.*' => __('finance.card.invalid_expiry'),
        ]);

        // A card typed in by hand replaces the tokenised one, so the old token must not be charged under it.
        $client->forceFill($data + ['has_payment_method' => true, 'tranzila_token' => null])->save();

        return $this->backToClient($client, 'payments')->with('success', __('finance.card.saved'));
    }

    public function removeCard(FinanceClient $client): RedirectResponse
    {
        $client->forceFill([
            'card_brand' => null,
            'card_last4' => null,
            'card_expiry' => null,
            'card_holder' => null,
            'tranzila_token' => null,
            'has_payment_method' => false,
        ])->save();

        return $this->backToClient($client, 'payments')->with('success', __('finance.card.removed'));
    }

    public function updateHardware(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'hardware_status' => ['required', Rule::enum(HardwareStatus::class)],
        ]);
        $client->forceFill(['hardware_status' => HardwareStatus::from($data['hardware_status'])])->save();

        return $this->backToClient($client, 'invoices')->with('success', __('finance.flash.hardware_saved'));
    }

    public function notify(Request $request, FinanceClient $client, FinanceWhatsApp $whatsapp): RedirectResponse
    {
        $data = $request->validate([
            'lang' => ['required', Rule::in(FinanceClient::LANGUAGES)],
        ]);

        try {
            $whatsapp->sendFailedPaymentNotice($client, $data['lang']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('finance.flash.notice_sent', ['name' => $client->name]));
    }

    private function backToClient(FinanceClient $client, string $tab): RedirectResponse
    {
        return redirect()->route('workspace.finance.clients', array_merge(self::keptFilters(), ['open' => $client->id, 'tab' => $tab]));
    }

    private static function tab(mixed $tab): string
    {
        return in_array($tab, ['details', 'payments', 'invoices'], true) ? $tab : 'details';
    }

    private static function embedTab(mixed $tab): string
    {
        return $tab === 'invoices' ? 'invoices' : 'payments';
    }

    /**
     * @return array<string, mixed>
     */
    private function modalData(FinanceClient $client, string $tab, bool $readonly = false): array
    {
        $client->load(['payments.files', 'invoices.items', 'linkedClients']);
        $ids = $client->accountRestaurants()->pluck('id')->all();
        // Devices still owed on any of the account's restaurants.
        $client->setRelation('devicesOwed', SalesQuote::query()
            ->whereIn('finance_client_id', $ids)
            ->where('status', 'sold')
            ->where('devices_due', '>', 0)
            ->orderBy('sold_at')
            ->get());

        return [
            'client' => $client,
            // Restaurants that can join this account: everyone not in it already.
            'linkable' => $readonly ? collect() : FinanceClient::query()
                ->whereNotIn('id', $ids)
                ->orderBy('name')
                ->get(['id', 'name', 'subdomain', 'billing_client_id']),
            'tab' => $tab,
            'storageItems' => StorageItem::query()->where('active', true)->orderBy('category')->orderBy('name')->get(),
            'invoiceTypes' => InvoiceType::cases(),
            'paymentMethods' => PaymentMethod::cases(),
            'vatRate' => (float) config('finance.vat_rate', 18),
        ];
    }

    /**
     * The clients-list filters from the page the form was sent from, so saving keeps the list as it was.
     *
     * @return array<string, string>
     */
    public static function searchText(FinanceClient $client): string
    {
        return mb_strtolower(implode(' ', [
            $client->name,
            $client->subdomain,
            $client->phone,
            ...$client->linkedClients->flatMap(fn (FinanceClient $linked): array => [$linked->name, $linked->subdomain]),
        ]));
    }

    public static function keptFilters(): array
    {
        $query = [];
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        return array_intersect_key($query, array_flip(['q', 'payment', 'service']));
    }
}
