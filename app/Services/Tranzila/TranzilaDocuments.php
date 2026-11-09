<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

use App\Enums\Tranzila\CardBrand;
use App\Enums\Tranzila\CreditTerm;
use App\Enums\Tranzila\DocumentAction;
use App\Enums\Tranzila\DocumentPaymentMethod;
use App\Enums\Tranzila\DocumentRelation;
use App\Enums\Tranzila\DocumentStatus;
use App\Enums\Tranzila\DocumentType;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\TranzilaDocument;
use App\Models\Finance\TranzilaTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Accounting documents. A document that fails never undoes the money behind it: its row stays with the exact
 * payload, so staff can issue it again.
 */
class TranzilaDocuments
{
    public function __construct(
        private readonly TranzilaClient $client,
    ) {}

    /**
     * The document for an approved charge, linked to it through txnindex.
     */
    public function issueForCharge(TranzilaTransaction $transaction, ?User $user): TranzilaDocument
    {
        $type = DocumentType::tryFrom((string) config('tranzila.documents.default_type')) ?? DocumentType::TaxInvoiceReceipt;

        return $this->create(
            $transaction->client,
            $type,
            DocumentAction::Debit,
            now(),
            $this->items($transaction),
            [$this->cardPayment($transaction, false)],
            (float) $transaction->amount,
            $user,
            $transaction,
        );
    }

    /**
     * The credit document for a refund, cancelling the charge's document by its number.
     */
    public function issueForRefund(TranzilaTransaction $refund, TranzilaDocument $original, ?User $user): TranzilaDocument
    {
        return $this->create(
            $refund->client,
            $original->document_type,
            DocumentAction::Credit,
            now(),
            $this->items($refund),
            [$this->cardPayment($refund, true)],
            (float) $refund->amount,
            $user,
            $refund,
            $original,
        );
    }

    /**
     * A document staff write by hand, for cash, cheque or bank transfer.
     *
     * @param  list<array{name: string, unit_price: float, units_number: float, unit_type?: int|null}>  $lines
     * @param  list<array<string, mixed>>  $payments
     */
    public function issueManual(FinanceClient $client, DocumentType $type, Carbon $date, array $lines, array $payments, ?User $user): TranzilaDocument
    {
        $items = $type->hasItems() ? array_map(fn (array $line): array => array_filter([
            'type' => 'I',
            'name' => $line['name'],
            'price_type' => 'G',
            'unit_price' => round((float) $line['unit_price'], 2),
            'units_number' => (float) $line['units_number'],
            'unit_type' => $line['unit_type'] ?? 1,
            'currency_code' => 'ILS',
        ], fn ($value): bool => $value !== null), $lines) : [];

        $payments = $type->hasPayments() ? $payments : [];
        $itemsTotal = round(array_sum(array_map(fn (array $item): float => $item['unit_price'] * $item['units_number'], $items)), 2);
        $paymentsTotal = round(array_sum(array_column($payments, 'amount')), 2);

        // Tranzila refuses partial coverage (10008), so catch it before a number is spent.
        if ($type->hasItems() && $type->hasPayments() && abs($itemsTotal - $paymentsTotal) > 0.009) {
            throw new TranzilaException(__('tranzila.errors.totals_differ'));
        }

        return $this->create($client, $type, DocumentAction::Debit, $date, $items, $payments, $type->hasItems() ? $itemsTotal : $paymentsTotal, $user);
    }

    /**
     * Cancels an issued document with a credit document for the whole of it.
     */
    public function credit(TranzilaDocument $document, ?User $user): TranzilaDocument
    {
        $document->loadMissing('credits');
        if (! $document->isCreditable()) {
            throw new TranzilaException(__('tranzila.errors.not_creditable'));
        }

        return $this->create(
            $document->client,
            $document->document_type,
            DocumentAction::Credit,
            now(),
            $document->payload['items'] ?? [],
            $document->payload['payments'] ?? [],
            (float) $document->total,
            $user,
            null,
            $document,
        );
    }

    /**
     * Sends a document that failed again, unchanged. Staff asked for this after checking Tranzila when the
     * first attempt got no answer.
     */
    public function reissue(TranzilaDocument $document): TranzilaDocument
    {
        if (! $document->canBeReissued()) {
            throw new TranzilaException(__('tranzila.errors.not_reissuable'));
        }

        return $this->send($document);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $payments
     */
    private function create(
        FinanceClient $client,
        DocumentType $type,
        DocumentAction $action,
        Carbon $date,
        array $items,
        array $payments,
        float $total,
        ?User $user,
        ?TranzilaTransaction $transaction = null,
        ?TranzilaDocument $related = null,
    ): TranzilaDocument {
        $terminal = (string) config('tranzila.terminal');

        $payload = array_filter([
            'terminal_name' => $terminal,
            'document_date' => $date->toDateString(),
            'document_type' => $type->value,
            'action' => $action->value,
            'document_language' => config('tranzila.documents.language'),
            'response_language' => app()->getLocale() === 'he' ? 'heb' : 'eng',
            'document_currency_code' => 'ILS',
            'client_name' => $client->name,
            'client_company' => $client->name,
            'client_id' => ctype_digit((string) $client->business_number) ? (string) $client->business_number : null,
            'client_email' => $client->billing_email,
            'related_document_number' => $related?->number,
            'relation_type' => $related !== null ? DocumentRelation::ByNumber->value : null,
            'created_by_user' => $user?->name,
            'created_by_system' => config('tranzila.system_name'),
            'items' => $items,
            'payments' => $payments,
        ], fn ($value): bool => $value !== null && $value !== []);

        return $this->send(TranzilaDocument::query()->create([
            'finance_client_id' => $client->id,
            'transaction_id' => $transaction?->id,
            'related_document_id' => $related?->id,
            'created_by' => $user?->id,
            'terminal' => $terminal,
            'document_type' => $type,
            'action' => $action,
            'status' => DocumentStatus::Pending,
            'document_date' => $date->toDateString(),
            'total' => $total,
            'payload' => $payload,
        ]));
    }

    private function send(TranzilaDocument $document): TranzilaDocument
    {
        try {
            $response = $this->client->createDocument($document->payload);
        } catch (TranzilaOutcomeUnknown $e) {
            $document->forceFill(['status' => DocumentStatus::Unknown, 'error_code' => null, 'error_message' => $e->getMessage()])->save();

            return $document;
        } catch (TranzilaException $e) {
            $document->forceFill(['status' => DocumentStatus::Failed, 'error_code' => null, 'error_message' => $e->getMessage()])->save();

            return $document;
        }

        if (! $response->ok()) {
            $document->forceFill([
                'status' => DocumentStatus::Failed,
                'error_code' => $response->code,
                'error_message' => mb_substr($response->message, 0, 500),
                'enquiry_key' => $response->data['enquiry_key'] ?? null,
            ])->save();

            return $document;
        }

        $result = DocumentResult::fromResponse($response);
        $document->forceFill([
            'status' => DocumentStatus::Issued,
            'tranzila_document_id' => $result->id,
            'number' => $result->number,
            'assignment_number' => $result->assignmentNumber,
            'retrieval_key' => $result->retrievalKey,
            'enquiry_key' => $response->data['enquiry_key'] ?? null,
            'issued_at' => $result->issuedAt ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        return $document;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(TranzilaTransaction $transaction): array
    {
        return array_map(fn (array $item): array => [
            'type' => 'I',
            'name' => $item['name'],
            'price_type' => 'G',
            'unit_price' => (float) $item['amount'],
            'units_number' => 1,
            'unit_type' => 1,
            'currency_code' => $transaction->currency,
        ], $transaction->items);
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayment(TranzilaTransaction $transaction, bool $cancels): array
    {
        return array_filter([
            'payment_method' => DocumentPaymentMethod::CreditCard->value,
            'payment_date' => now()->toDateString(),
            'amount' => (float) $transaction->amount,
            'currency_code' => $transaction->currency,
            'cc_last_4_digits' => $transaction->card_last4,
            'cc_credit_term' => CreditTerm::Regular->value,
            'cc_brand' => CardBrand::fromName($transaction->card_type_name)?->value,
            'txnindex' => $transaction->tranzila_transaction_id,
            'canceldoc' => $cancels ? 'Y' : null,
        ], fn ($value): bool => $value !== null);
    }
}
