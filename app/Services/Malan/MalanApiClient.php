<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Services\Malan\Exceptions\MalanApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MalanApiClient
{
    public function __construct(
        protected MalanCustomerResponseMapper $mapper,
    ) {}

    /**
     * @param  'phone'|'identity'  $lookupType
     *
     * @throws MalanApiException
     */
    public function getClient(string $lookupType, string $value): MalanCustomerLookupResult
    {
        $apiKey = (string) config('malan.api.key', '');
        $baseUrl = (string) config('malan.api.base_url', 'https://www.malan.app');
        $timeout = (int) config('malan.api.timeout', 15);
        $retries = max(0, (int) config('malan.api.retries', 1));
        $retrySleep = max(0, (int) config('malan.api.retry_sleep_ms', 200));

        if ($apiKey === '') {
            Log::critical('Malan API key is not configured.');

            throw MalanApiException::unauthorized();
        }

        $query = match ($lookupType) {
            'phone' => [
                'phone' => $value,
                'identity' => '',
                'visa_last4' => '',
            ],
            'identity' => [
                'phone' => '',
                'identity' => $value,
                'visa_last4' => '',
            ],
            default => throw MalanApiException::invalidInput(
                'Unsupported lookup type.',
                'تأكدلي من الرقم وابعته مرة ثانية.',
            ),
        };

        $url = $baseUrl.'/apiClient/getClient';

        Log::info('Malan customer lookup started', [
            'lookup_type' => $lookupType,
            'value_masked' => $lookupType === 'identity'
                ? MalanSensitiveDataMasker::maskIdentity($value)
                : MalanSensitiveDataMasker::maskPhone($value),
        ]);

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $retrySleep, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url, $query);
        } catch (ConnectionException $e) {
            Log::warning('Malan API connection/timeout failure', [
                'lookup_type' => $lookupType,
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::timeout();
        } catch (Throwable $e) {
            Log::warning('Malan API unexpected transport failure', [
                'lookup_type' => $lookupType,
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::serverError();
        }

        $status = $response->status();
        $json = $response->json();

        // Some Malan responses put "not found" / empty client in a 200 or odd status with result:false.
        if ($this->payloadIndicatesNotFound($json)) {
            throw MalanApiException::notFound();
        }

        return match (true) {
            $status === 200 => $this->mapper->mapSuccessfulPayload($json),
            $status === 400 => throw MalanApiException::invalidInput(
                'Invalid lookup parameters.',
                'تأكدلي من الرقم وابعته مرة ثانية بشكل صحيح.',
            ),
            $status === 401 => $this->handleUnauthorized(),
            $status === 404 => throw MalanApiException::notFound(),
            $status === 409 => throw MalanApiException::conflict($lookupType),
            $status === 405 => throw MalanApiException::methodNotAllowed(),
            $status === 429 => throw MalanApiException::rateLimited(),
            $status >= 500 => throw MalanApiException::serverError($status),
            default => throw MalanApiException::serverError($status),
        };
    }

    /**
     * Detect empty / not-found CRM payloads even when HTTP status is misleading.
     */
    private function payloadIndicatesNotFound(mixed $json): bool
    {
        if (! is_array($json)) {
            return false;
        }

        $error = strtolower(trim((string) ($json['error'] ?? '')));
        if ($error !== '' && (
            str_contains($error, 'not found')
            || str_contains($error, 'client not found')
            || str_contains($error, 'no client')
        )) {
            return true;
        }

        // Explicit failure with no client data.
        if (($json['result'] ?? null) === false) {
            $data = $json['data'] ?? null;
            $client = is_array($data) ? ($data['client'] ?? null) : null;
            if ($client === null || $client === [] || $client === '') {
                // Do not treat multi-match errors as not-found.
                if ($error !== '' && (
                    str_contains($error, 'more than one')
                    || str_contains($error, 'multiple')
                    || str_contains($error, 'matched')
                )) {
                    return false;
                }

                return $error === '' || str_contains($error, 'not found') || str_contains($error, 'no client');
            }
        }

        return false;
    }

    /**
     * @throws MalanApiException
     */
    private function handleUnauthorized(): never
    {
        Log::critical('Malan API returned unauthorized. Check MALAN_API_KEY configuration.');

        throw MalanApiException::unauthorized();
    }
}
