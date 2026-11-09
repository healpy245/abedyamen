<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentRecordStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Tranzila\ChargeFrequency;
use App\Enums\Tranzila\SubscriptionStatus;
use App\Enums\Tranzila\TransactionStatus;
use App\Enums\Tranzila\TransactionType;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\TranzilaDocument;
use App\Models\Finance\TranzilaSubscription;
use App\Models\Finance\TranzilaTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Moves money through Tranzila. Every attempt is written down before it is sent, so a crash or a lost answer
 * leaves a row that blocks another charge until someone has checked what happened.
 */
class TranzilaBilling
{
    public function __construct(
        private readonly TranzilaClient $client,
        private readonly TranzilaDocuments $documents,
    ) {}

    /**
     * Saves the token Hosted Fields returned. Brand, last 4 digits and expiry are safe to keep beside it.
     */
    public function saveCard(FinanceClient $client, string $token, string $last4, string $brand, int $month, int $year, ?string $holder): void
    {
        $client->account()->forceFill([
            'tranzila_token' => $token,
            'card_brand' => in_array($brand, FinanceClient::CARD_BRANDS, true) ? $brand : 'card',
            'card_last4' => $last4,
            'card_expiry' => sprintf('%02d/%02d', $month, $year % 100),
            'card_holder' => $holder ?: $client->account()->card_holder,
            'has_payment_method' => true,
        ])->save();
    }

    /**
     * Charges the client's saved card and, when approved, issues the document.
     *
     * @param  list<array{name: string, amount: float}>  $lines  VAT-inclusive amounts
     */
    public function charge(FinanceClient $client, array $lines, string $idempotencyKey, ?Carbon $period, ?User $user): TranzilaTransaction
    {
        $account = $client->account();
        $total = $this->total($lines);

        $existing = $this->findByKey($account, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        [$month, $year] = $this->expiry($account);
        if (blank($account->tranzila_token) || $month === null) {
            throw new TranzilaException(__('tranzila.errors.no_token'));
        }
        if ($total <= 0) {
            throw new TranzilaException(__('tranzila.errors.no_amount'));
        }
        if ($account->tranzilaTransactions()->whereIn('status', [TransactionStatus::Pending, TransactionStatus::Unknown])->exists()) {
            throw new TranzilaException(__('tranzila.errors.unresolved'));
        }

        try {
            $transaction = TranzilaTransaction::query()->create([
                'finance_client_id' => $account->id,
                'created_by' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                'terminal' => (string) config('tranzila.charge_terminal'),
                'type' => TransactionType::Debit,
                'status' => TransactionStatus::Pending,
                'amount' => $total,
                'items' => $lines,
                'card_token' => $account->tranzila_token,
                'expire_month' => $month,
                'expire_year' => $year,
                'card_last4' => $account->card_last4,
                'period' => $period?->copy()->startOfMonth()->toDateString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($account, $idempotencyKey) ?? throw new TranzilaException(__('tranzila.errors.refused'));
        }

        $this->send($transaction, $account, $user);

        if ($transaction->status === TransactionStatus::Approved) {
            $this->recordPaid($transaction);
            $this->documents->issueForCharge($transaction, $user);
        } elseif ($transaction->status === TransactionStatus::Declined) {
            $this->recordFailed($transaction);
        }

        return $transaction;
    }

    /**
     * Refunds a whole charge and issues the credit document that cancels its document.
     */
    public function refund(TranzilaTransaction $original, ?User $user): TranzilaTransaction
    {
        $original->loadMissing('refunds', 'client');
        if (! $original->isRefundable()) {
            throw new TranzilaException(__('tranzila.errors.not_refundable'));
        }
        if (blank($original->auth_number) || $original->tranzila_transaction_id === null) {
            throw new TranzilaException(__('tranzila.errors.refund_missing_reference'));
        }

        $refund = TranzilaTransaction::query()->create([
            'finance_client_id' => $original->finance_client_id,
            'refund_of_id' => $original->id,
            'created_by' => $user?->id,
            'idempotency_key' => 'refund-'.$original->id.'-'.Str::uuid(),
            'terminal' => $original->terminal,
            'type' => TransactionType::Credit,
            'status' => TransactionStatus::Pending,
            'amount' => $original->amount,
            'items' => $original->items,
            'card_token' => $original->card_token,
            'expire_month' => $original->expire_month,
            'expire_year' => $original->expire_year,
            'card_last4' => $original->card_last4,
            'card_type_name' => $original->card_type_name,
        ]);

        $this->send($refund, $original->client, $user, [
            'reference_txn_id' => $original->tranzila_transaction_id,
            'authorization_number' => $original->auth_number,
        ]);

        if ($refund->status === TransactionStatus::Approved) {
            $this->reopenPayment($original);

            $document = $this->issuedDocument($original);
            if ($document !== null) {
                $this->documents->issueForRefund($refund, $document, $user);
            }
        }

        return $refund;
    }

    /**
     * Staff checked in Tranzila what became of an attempt that got no answer, and record it here.
     * An attempt that went through continues as a normal charge or refund would.
     */
    public function resolve(TranzilaTransaction $transaction, bool $wentThrough, ?int $tranzilaId, ?string $authNumber, ?User $user): void
    {
        if (! $transaction->status->isUnresolved()) {
            throw new TranzilaException(__('tranzila.errors.not_unresolved'));
        }

        if (! $wentThrough) {
            $transaction->forceFill(['status' => TransactionStatus::Declined, 'error_message' => __('tranzila.errors.resolved_not_charged')])->save();

            return;
        }

        $transaction->forceFill([
            'status' => TransactionStatus::Approved,
            'tranzila_transaction_id' => $tranzilaId,
            'auth_number' => $authNumber,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        if ($transaction->type === TransactionType::Debit) {
            $this->recordPaid($transaction);
            $this->documents->issueForCharge($transaction, $user);

            return;
        }

        $original = $transaction->refundOf;
        if ($original !== null) {
            $this->reopenPayment($original);
            $document = $this->issuedDocument($original);
            if ($document !== null) {
                $this->documents->issueForRefund($transaction, $document, $user);
            }
        }
    }

    /**
     * Creates a standing order. Tranzila charges it from then on, on its own schedule.
     *
     * @param  list<array{name: string, amount: float}>  $lines  VAT-inclusive amounts
     */
    public function subscribe(FinanceClient $client, array $lines, ChargeFrequency $frequency, int $chargeDay, int $paymentsNumber, ?Carbon $firstCharge, ?User $user): TranzilaSubscription
    {
        $account = $client->account();
        [$month, $year] = $this->expiry($account);
        if (blank($account->tranzila_token) || $month === null) {
            throw new TranzilaException(__('tranzila.errors.no_token'));
        }

        $terminal = (string) config('tranzila.charge_terminal');
        $subscription = TranzilaSubscription::query()->create([
            'finance_client_id' => $account->id,
            'created_by' => $user?->id,
            'terminal' => $terminal,
            'status' => SubscriptionStatus::Pending,
            'amount' => $this->total($lines),
            'items' => $lines,
            'charge_frequency' => $frequency,
            'charge_dom' => $chargeDay,
            'payments_number' => $paymentsNumber,
            'first_charge_date' => $firstCharge?->toDateString(),
        ]);

        try {
            $response = $this->client->createStandingOrder(array_filter([
                'terminal_name' => $terminal,
                'sto_payments_number' => $paymentsNumber,
                'charge_frequency' => $frequency->value,
                'charge_dom' => $chargeDay,
                'first_charge_date' => $firstCharge?->toDateString(),
                'currency_code' => 'ILS',
                'response_language' => $this->language(),
                'created_by_user' => $user?->name,
                'items' => array_map(fn (array $line): array => [
                    'name' => $line['name'],
                    'unit_price' => $line['amount'],
                    'units_number' => 1,
                    'price_type' => 'G',
                ], $lines),
                'client' => $this->clientDetails($account, false),
                'card' => array_filter([
                    'token' => $account->tranzila_token,
                    'expire_month' => $month,
                    'expire_year' => $year,
                    'card_holder_name' => $account->card_holder,
                ]),
            ], fn ($value): bool => $value !== null));
        } catch (TranzilaOutcomeUnknown $e) {
            $subscription->forceFill(['status' => SubscriptionStatus::Unknown, 'error_message' => $e->getMessage()])->save();

            return $subscription;
        } catch (TranzilaException $e) {
            $subscription->forceFill(['status' => SubscriptionStatus::Failed, 'error_message' => $e->getMessage()])->save();

            return $subscription;
        }

        $subscription->forceFill($response->ok()
            ? ['status' => SubscriptionStatus::Active, 'sto_id' => $response->data['sto_id'] ?? null]
            : ['status' => SubscriptionStatus::Failed, 'error_code' => $response->code, 'error_message' => mb_substr($response->message, 0, 500)]
        )->save();

        return $subscription;
    }

    public function cancelSubscription(TranzilaSubscription $subscription, ?User $user): void
    {
        if ($subscription->status !== SubscriptionStatus::Active || $subscription->sto_id === null) {
            throw new TranzilaException(__('tranzila.errors.not_cancellable'));
        }

        $response = $this->client->updateStandingOrder([
            'terminal_name' => $subscription->terminal,
            'sto_id' => $subscription->sto_id,
            'sto_status' => 'inactive',
            'response_language' => $this->language(),
            'updated_by_user' => $user?->name,
        ]);

        if (! $response->ok()) {
            throw new TranzilaException($this->client->describe($response));
        }

        $subscription->forceFill(['status' => SubscriptionStatus::Inactive])->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(TranzilaTransaction $transaction, FinanceClient $account, ?User $user, array $extra = []): void
    {
        $body = [
            'terminal_name' => $transaction->terminal,
            'txn_type' => $transaction->type->value,
            // The token goes where a card number would: the API takes either in card_number.
            'card_number' => $transaction->card_token,
            'expire_month' => $transaction->expire_month,
            'expire_year' => $transaction->expire_year,
            'txn_currency_code' => $transaction->currency,
            'response_language' => $this->language(),
            'created_by_user' => $user?->name,
            'created_by_system' => config('tranzila.system_name'),
            'client' => $this->clientDetails($account, true),
            'items' => array_map(fn (array $line): array => [
                'name' => $line['name'],
                'type' => 'I',
                'unit_price' => $line['amount'],
                'units_number' => 1,
                'price_type' => 'G',
            ], $transaction->items),
        ] + $extra;

        if (config('tranzila.dc_disable') && $transaction->type === TransactionType::Debit) {
            $body['user_defined_fields'] = [['name' => 'DCdisable', 'value' => $transaction->idempotency_key]];
        }

        try {
            $result = $this->client->createCreditCardTransaction(array_filter($body, fn ($value): bool => $value !== null));
        } catch (TranzilaOutcomeUnknown $e) {
            $transaction->forceFill(['status' => TransactionStatus::Unknown, 'error_message' => $e->getMessage()])->save();

            return;
        } catch (TranzilaException $e) {
            // Refused before anything was sent, such as missing credentials.
            $transaction->forceFill(['status' => TransactionStatus::Declined, 'error_message' => $e->getMessage()])->save();

            return;
        }

        $transaction->forceFill([
            'status' => $result->approved ? TransactionStatus::Approved : TransactionStatus::Declined,
            'tranzila_transaction_id' => $result->transactionId,
            'auth_number' => $result->authNumber,
            'processor_response_code' => $result->processorCode,
            'error_code' => $result->approved ? null : $result->errorCode,
            'error_message' => $result->approved ? null : mb_substr($result->message, 0, 500),
            'card_last4' => $result->last4 ?? $transaction->card_last4,
            'card_type_name' => $result->cardTypeName ?? $transaction->card_type_name,
        ])->save();
    }

    /**
     * A paid charge for a month settles that month, or adds it to the payment history.
     */
    private function recordPaid(TranzilaTransaction $transaction): void
    {
        if ($transaction->period === null) {
            return;
        }

        $payment = $this->monthPayment($transaction) ?? new FinancePayment([
            'finance_client_id' => $transaction->finance_client_id,
            'period' => $transaction->period->toDateString(),
        ]);
        $payment->fill([
            'amount' => $transaction->amount,
            'status' => PaymentRecordStatus::Paid,
            'method' => PaymentMethod::CreditCard,
            'paid_on' => now()->toDateString(),
            'reference' => 'Tranzila '.$transaction->tranzila_transaction_id,
            'items' => $transaction->items,
        ])->save();

        $transaction->forceFill(['finance_payment_id' => $payment->id])->save();

        $client = $transaction->client;
        if ($client->payment_status === PaymentStatus::Failed
            && ! $client->payments()->where('status', PaymentRecordStatus::Failed)->exists()) {
            $client->forceFill(['payment_status' => PaymentStatus::Active])->save();
        }
    }

    /**
     * A declined charge for a month flags the client, as a failure recorded by hand does.
     */
    private function recordFailed(TranzilaTransaction $transaction): void
    {
        if ($transaction->period === null) {
            return;
        }

        $payment = $this->monthPayment($transaction) ?? new FinancePayment([
            'finance_client_id' => $transaction->finance_client_id,
            'period' => $transaction->period->toDateString(),
            'amount' => $transaction->amount,
            'items' => $transaction->items,
        ]);
        $payment->fill(['status' => PaymentRecordStatus::Failed, 'method' => PaymentMethod::CreditCard])->save();

        $transaction->forceFill(['finance_payment_id' => $payment->id])->save();
        $transaction->client->forceFill(['payment_status' => PaymentStatus::Failed])->save();
    }

    /**
     * A refunded month is no longer paid, so it goes back to being collected and stops counting as income.
     */
    private function reopenPayment(TranzilaTransaction $original): void
    {
        $original->payment?->fill([
            'status' => PaymentRecordStatus::Pending,
            'paid_on' => null,
            'note' => trim($original->payment->note.' '.__('tranzila.refunded_note', ['id' => $original->tranzila_transaction_id])),
        ])->save();
    }

    private function monthPayment(TranzilaTransaction $transaction): ?FinancePayment
    {
        return FinancePayment::query()
            ->where('finance_client_id', $transaction->finance_client_id)
            ->whereDate('period', $transaction->period)
            ->where('status', '!=', PaymentRecordStatus::Paid)
            ->orderBy('id')
            ->first();
    }

    private function issuedDocument(TranzilaTransaction $original): ?TranzilaDocument
    {
        return $original->documents()->where('status', 'issued')->whereNotNull('number')->latest('id')->first();
    }

    private function findByKey(FinanceClient $account, string $key): ?TranzilaTransaction
    {
        return TranzilaTransaction::query()->where('finance_client_id', $account->id)->where('idempotency_key', $key)->first();
    }

    /**
     * @param  list<array{name: string, amount: float}>  $lines
     */
    private function total(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function expiry(FinanceClient $account): array
    {
        if (preg_match('/^(\d{2})\/(\d{2})$/', (string) $account->card_expiry, $parts) !== 1) {
            return [null, null];
        }

        return [(int) $parts[1], 2000 + (int) $parts[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientDetails(FinanceClient $account, bool $withExternalId): array
    {
        $id = (string) $account->business_number;

        return array_filter([
            'external_id' => $withExternalId ? (string) $account->id : null,
            'name' => $account->name,
            'id' => preg_match('/^[0-9A-Za-z]{5,9}$/', $id) === 1 ? $id : null,
            'email' => $account->billing_email,
        ], fn ($value): bool => $value !== null);
    }

    private function language(): string
    {
        return app()->getLocale() === 'he' ? 'hebrew' : 'english';
    }
}
