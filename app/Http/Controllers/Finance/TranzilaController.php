<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\Tranzila\ChargeFrequency;
use App\Enums\Tranzila\DocumentPaymentMethod;
use App\Enums\Tranzila\DocumentStatus;
use App\Enums\Tranzila\DocumentType;
use App\Enums\Tranzila\SubscriptionStatus;
use App\Enums\Tranzila\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\TranzilaDocument;
use App\Models\Finance\TranzilaSubscription;
use App\Models\Finance\TranzilaTransaction;
use App\Services\Tranzila\TranzilaBilling;
use App\Services\Tranzila\TranzilaClient;
use App\Services\Tranzila\TranzilaDocuments;
use App\Services\Tranzila\TranzilaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One client's Tranzila billing: the tokenised card, charges and refunds, documents and standing orders.
 */
class TranzilaController extends Controller
{
    public function __construct(
        private readonly TranzilaClient $tranzila,
        private readonly TranzilaBilling $billing,
        private readonly TranzilaDocuments $documents,
    ) {}

    public function show(FinanceClient $client): View
    {
        $account = $client->account();

        return view('workspace.finance.tranzila', [
            'page' => 'clients',
            'client' => $account,
            'configured' => $this->tranzila->isConfigured(),
            'hasToken' => filled($account->tranzila_token),
            'transactions' => $account->tranzilaTransactions()->with('refunds', 'refundOf')->limit(100)->get(),
            'documents' => $account->tranzilaDocuments()->with('credits', 'relatedDocument')->limit(100)->get(),
            'subscriptions' => $account->tranzilaSubscriptions()->limit(50)->get(),
            'lines' => $account->accountBillingLines(),
            'chargeKey' => (string) Str::uuid(),
            'documentUrl' => fn (TranzilaDocument $document): ?string => $document->retrieval_key
                ? $this->tranzila->documentUrl($document->retrieval_key)
                : null,
        ]);
    }

    public function updateEmail(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate(['billing_email' => ['nullable', 'email', 'max:255']]);
        $client->account()->forceFill($data)->save();

        return $this->back($client)->with('success', __('tranzila.flash.email_saved'));
    }

    /**
     * The one-time thtk Hosted Fields needs to validate a card. Requested only when staff press save.
     */
    public function handshake(FinanceClient $client): JsonResponse
    {
        $terminal = (string) config('tranzila.charge_terminal');
        $amount = (float) config('tranzila.hosted_fields.capture_amount');

        try {
            return response()->json([
                'thtk' => $this->tranzila->createHandshake($terminal, $amount),
                'terminal' => $terminal,
                'amount' => $amount,
            ]);
        } catch (TranzilaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Keeps the token from Hosted Fields. A full card number has no field here and is refused outright.
     */
    public function saveCard(Request $request, FinanceClient $client): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]*[A-Za-z][A-Za-z0-9]*$/'],
            'last4' => ['required', 'digits:4'],
            'brand' => ['required', Rule::in(FinanceClient::CARD_BRANDS)],
            'expiry_month' => ['required', 'integer', 'between:1,12'],
            'expiry_year' => ['required', 'integer', 'between:2020,2099'],
            'holder' => ['nullable', 'string', 'max:80'],
        ]);

        $this->billing->saveCard($client, $data['token'], $data['last4'], $data['brand'], (int) $data['expiry_month'], (int) $data['expiry_year'], $data['holder'] ?? null);
        $request->session()->flash('success', __('tranzila.flash.card_saved'));

        return response()->json(['ok' => true]);
    }

    public function charge(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:120'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'period' => ['nullable', 'date_format:Y-m'],
        ]);

        return $this->attempt($client, function () use ($request, $client, $data): RedirectResponse {
            $period = filled($data['period'] ?? null) ? Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth() : null;
            $transaction = $this->billing->charge($client, $this->lines($data['lines']), $data['idempotency_key'], $period, $request->user());

            return $this->afterTransaction($client, $transaction, 'charge');
        });
    }

    public function refund(Request $request, FinanceClient $client, TranzilaTransaction $transaction): RedirectResponse
    {
        $this->ensureOwned($client, $transaction->finance_client_id);

        return $this->attempt($client, fn (): RedirectResponse => $this->afterTransaction(
            $client,
            $this->billing->refund($transaction, $request->user()),
            'refund',
        ));
    }

    public function resolve(Request $request, FinanceClient $client, TranzilaTransaction $transaction): RedirectResponse
    {
        $this->ensureOwned($client, $transaction->finance_client_id);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['went_through', 'not_charged'])],
            'tranzila_transaction_id' => ['required_if:outcome,went_through', 'nullable', 'integer', 'min:1'],
            'auth_number' => ['required_if:outcome,went_through', 'nullable', 'string', 'max:32'],
        ]);

        return $this->attempt($client, function () use ($request, $client, $transaction, $data): RedirectResponse {
            $this->billing->resolve(
                $transaction,
                $data['outcome'] === 'went_through',
                isset($data['tranzila_transaction_id']) ? (int) $data['tranzila_transaction_id'] : null,
                $data['auth_number'] ?? null,
                $request->user(),
            );

            return $this->back($client)->with('success', __('tranzila.flash.resolved'));
        });
    }

    public function storeDocument(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['array'],
            'lines.*.name' => ['required', 'string', 'max:120'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'lines.*.units_number' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', Rule::in(array_map(fn (DocumentPaymentMethod $method): int => $method->value, DocumentPaymentMethod::manual()))],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'bank' => ['nullable', 'string', 'max:40'],
            'bank_branch' => ['nullable', 'string', 'max:40'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'cheque_number' => ['nullable', 'string', 'max:40'],
            'other_description' => ['nullable', 'string', 'max:120'],
        ]);

        $type = DocumentType::from($data['document_type']);
        if ($type->hasItems() && empty($data['lines'])) {
            return $this->back($client)->withErrors(['lines' => __('tranzila.errors.lines_required')])->withInput();
        }
        if ($type->hasPayments() && (empty($data['payment_method']) || empty($data['amount']))) {
            return $this->back($client)->withErrors(['payment_method' => __('tranzila.errors.payment_required')])->withInput();
        }

        return $this->attempt($client, function () use ($request, $client, $data, $type): RedirectResponse {
            $document = $this->documents->issueManual(
                $client->account(),
                $type,
                Carbon::createFromFormat('Y-m-d', $data['document_date']),
                $data['lines'] ?? [],
                $this->manualPayment($data),
                $request->user(),
            );

            return $this->afterDocument($client, $document);
        });
    }

    public function reissueDocument(FinanceClient $client, TranzilaDocument $document): RedirectResponse
    {
        $this->ensureOwned($client, $document->finance_client_id);

        return $this->attempt($client, fn (): RedirectResponse => $this->afterDocument($client, $this->documents->reissue($document)));
    }

    public function creditDocument(Request $request, FinanceClient $client, TranzilaDocument $document): RedirectResponse
    {
        $this->ensureOwned($client, $document->finance_client_id);

        return $this->attempt($client, fn (): RedirectResponse => $this->afterDocument($client, $this->documents->credit($document, $request->user())));
    }

    public function storeSubscription(Request $request, FinanceClient $client): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:120'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'charge_frequency' => ['required', Rule::enum(ChargeFrequency::class)],
            'charge_dom' => ['required', 'integer', 'between:1,28'],
            'payments_number' => ['required', 'integer', 'min:1', 'max:1000'],
            'first_charge_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        return $this->attempt($client, function () use ($request, $client, $data): RedirectResponse {
            $subscription = $this->billing->subscribe(
                $client,
                $this->lines($data['lines']),
                ChargeFrequency::from($data['charge_frequency']),
                (int) $data['charge_dom'],
                (int) $data['payments_number'],
                filled($data['first_charge_date'] ?? null) ? Carbon::createFromFormat('Y-m-d', $data['first_charge_date']) : null,
                $request->user(),
            );

            return $subscription->status === SubscriptionStatus::Active
                ? $this->back($client)->with('success', __('tranzila.flash.subscribed'))
                : $this->back($client)->with('error', __('tranzila.flash.subscription_'.$subscription->status->value, ['error' => (string) $subscription->error_message]));
        });
    }

    public function cancelSubscription(Request $request, FinanceClient $client, TranzilaSubscription $subscription): RedirectResponse
    {
        $this->ensureOwned($client, $subscription->finance_client_id);

        return $this->attempt($client, function () use ($request, $client, $subscription): RedirectResponse {
            $this->billing->cancelSubscription($subscription, $request->user());

            return $this->back($client)->with('success', __('tranzila.flash.subscription_cancelled'));
        });
    }

    /**
     * @param  callable(): RedirectResponse  $action
     */
    private function attempt(FinanceClient $client, callable $action): RedirectResponse
    {
        try {
            return $action();
        } catch (TranzilaException $e) {
            return $this->back($client)->with('error', $e->getMessage());
        }
    }

    private function afterTransaction(FinanceClient $client, TranzilaTransaction $transaction, string $kind): RedirectResponse
    {
        if ($transaction->status === TransactionStatus::Approved) {
            $failedDocument = $transaction->documents()->where('status', '!=', DocumentStatus::Issued->value)->exists();

            return $failedDocument
                ? $this->back($client)->with('error', __('tranzila.flash.'.$kind.'_document_failed'))
                : $this->back($client)->with('success', __('tranzila.flash.'.$kind.'_approved'));
        }

        return $this->back($client)->with('error', $transaction->status === TransactionStatus::Declined
            ? __('tranzila.flash.declined', ['error' => (string) $transaction->error_message])
            : __('tranzila.flash.unknown'));
    }

    private function afterDocument(FinanceClient $client, TranzilaDocument $document): RedirectResponse
    {
        return $document->status === DocumentStatus::Issued
            ? $this->back($client)->with('success', __('tranzila.flash.document_issued', ['number' => (string) $document->number]))
            : $this->back($client)->with('error', __('tranzila.flash.document_'.$document->status->value, ['error' => (string) $document->error_message]));
    }

    /**
     * @param  array<int, array{name: string, amount: mixed}>  $lines
     * @return list<array{name: string, amount: float}>
     */
    private function lines(array $lines): array
    {
        return array_values(array_map(fn (array $line): array => ['name' => $line['name'], 'amount' => round((float) $line['amount'], 2)], $lines));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function manualPayment(array $data): array
    {
        if (empty($data['payment_method']) || empty($data['amount'])) {
            return [];
        }

        return [array_filter([
            'payment_method' => (int) $data['payment_method'],
            'payment_date' => $data['document_date'],
            'amount' => round((float) $data['amount'], 2),
            'currency_code' => 'ILS',
            'bank' => $data['bank'] ?? null,
            'bank_branch' => $data['bank_branch'] ?? null,
            'bank_account' => $data['bank_account'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'other_description' => $data['other_description'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '')];
    }

    private function ensureOwned(FinanceClient $client, int $ownerId): void
    {
        abort_unless($client->account()->id === $ownerId, 404);
    }

    private function back(FinanceClient $client): RedirectResponse
    {
        return redirect()->route('workspace.finance.clients.tranzila.show', $client);
    }
}
