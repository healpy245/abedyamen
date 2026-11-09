<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tranzila's REST API. Runs on the server only, since the requests are signed with the secret.
 *
 * Only reads are retried, and only on a network failure. A charge, a document or a standing order is
 * never sent twice automatically: when its outcome is unclear, TranzilaOutcomeUnknown is thrown instead.
 */
class TranzilaClient
{
    private const READ_ATTEMPTS = 3;

    public function isConfigured(): bool
    {
        return filled(config('tranzila.app_key')) && filled(config('tranzila.secret'));
    }

    /**
     * Charges, credits or verifies a card or token. The amount comes from the items.
     *
     * @param  array<string, mixed>  $body
     */
    public function createCreditCardTransaction(array $body): ChargeResult
    {
        return ChargeResult::fromResponse($this->post($this->paymentsUrl('/v1/transaction/credit_card/create'), $body));
    }

    /**
     * The one-time thtk token Hosted Fields needs. $sum must equal the amount the browser then charges.
     */
    public function createHandshake(string $terminal, float $sum): string
    {
        $response = $this->post($this->paymentsUrl('/v2/handshake/create'), ['terminal_name' => $terminal, 'sum' => $sum], true);
        $thtk = $response->data['thtk'] ?? null;
        if (! $response->ok() || blank($thtk)) {
            throw new TranzilaException($this->describe($response));
        }

        return (string) $thtk;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function createDocument(array $body): TranzilaResponse
    {
        return $this->post($this->documentsUrl('/api/documents_db/create_document'), $body);
    }

    /**
     * The document's PDF. Tranzila answers with JSON only when it fails.
     */
    public function getDocumentPdf(string $terminal, string $documentId, string $language = 'eng'): string
    {
        $response = $this->send($this->documentsUrl('/api/documents_db/get_document'), [
            'terminal_name' => $terminal,
            'document_id' => $documentId,
            'response_language' => $language,
        ], true);

        $error = $response->json();
        if (is_array($error)) {
            throw new TranzilaException($this->describe(TranzilaResponse::fromBody($error)));
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $filters  start_date, end_date, client_email, transaction_ids
     */
    public function getDocuments(array $filters): TranzilaResponse
    {
        return $this->post($this->documentsUrl('/api/documents_db/get_documents'), $filters, true);
    }

    /**
     * The public link a customer opens to see a document.
     */
    public function documentUrl(string $retrievalKey): string
    {
        return config('tranzila.urls.document_view').'/api/get_financial_document/'.rawurlencode($retrievalKey);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function createStandingOrder(array $body): TranzilaResponse
    {
        return $this->post($this->paymentsUrl('/v2/sto/create'), $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function updateStandingOrder(array $body): TranzilaResponse
    {
        return $this->post($this->paymentsUrl('/v2/sto/update'), $body);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getStandingOrders(array $filters): TranzilaResponse
    {
        return $this->post($this->paymentsUrl('/v1/stos/get'), $filters, true);
    }

    /**
     * Sends the customer a payment request by SMS or email.
     *
     * @param  array<string, mixed>  $body
     */
    public function createPaymentRequest(array $body): TranzilaResponse
    {
        return $this->post($this->paymentsUrl('/v1/pr/create'), $body);
    }

    /**
     * Transactions with their line items, for reconciliation.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getTransactions(array $filters): TranzilaResponse
    {
        $data = $this->send(config('tranzila.urls.reports').'/v1/transaction', $filters, true)->json();
        if (! is_array($data)) {
            throw new TranzilaOutcomeUnknown(__('tranzila.errors.unreadable'));
        }

        // A report has no status field when it succeeds, only its transactions.
        return isset($data['transactions'])
            ? new TranzilaResponse(0, '', $data)
            : TranzilaResponse::fromBody($data);
    }

    /**
     * The four signed headers every Tranzila request carries.
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        $appKey = (string) config('tranzila.app_key');
        $time = (string) time();
        $nonce = bin2hex(random_bytes(40));

        return [
            'X-tranzila-api-app-key' => $appKey,
            'X-tranzila-api-request-time' => $time,
            'X-tranzila-api-nonce' => $nonce,
            'X-tranzila-api-access-token' => hash_hmac('sha256', $appKey, config('tranzila.secret').$time.$nonce),
        ];
    }

    /**
     * Describes a refusal for staff, with Tranzila's code so it can be looked up.
     */
    public function describe(TranzilaResponse $response): string
    {
        return sprintf('%s (%d)', $response->message !== '' ? $response->message : __('tranzila.errors.refused'), $response->code);
    }

    private function paymentsUrl(string $path): string
    {
        return config('tranzila.urls.payments').$path;
    }

    private function documentsUrl(string $path): string
    {
        return config('tranzila.urls.documents').$path;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $url, array $body, bool $isRead = false): TranzilaResponse
    {
        $data = $this->send($url, $body, $isRead)->json();
        if (! is_array($data)) {
            throw new TranzilaOutcomeUnknown(__('tranzila.errors.unreadable'));
        }

        return TranzilaResponse::fromBody($data);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $url, array $body, bool $isRead): Response
    {
        if (! $this->isConfigured()) {
            throw new TranzilaException(__('tranzila.errors.not_configured'));
        }

        try {
            $response = $this->request($isRead)->post($url, $body);
        } catch (ConnectionException) {
            // Deliberately drops the exception: staff get a plain message, and the body may carry a token.
            throw new TranzilaOutcomeUnknown(__('tranzila.errors.no_answer'));
        }

        // A server error says nothing about whether the request was carried out.
        if ($response->serverError()) {
            throw new TranzilaOutcomeUnknown(__('tranzila.errors.no_answer'));
        }

        return $response;
    }

    private function request(bool $isRead): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders($this->authHeaders())
            ->withOptions(['verify' => (bool) config('tranzila.ssl_verify')])
            ->connectTimeout((int) config('tranzila.connect_timeout'))
            ->timeout((int) config('tranzila.timeout'));

        return $isRead
            ? $request->retry(self::READ_ATTEMPTS, 300, fn ($exception): bool => $exception instanceof ConnectionException, false)
            : $request;
    }
}
