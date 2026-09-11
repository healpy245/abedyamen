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
     * Create a CRM task linked to an existing Malan customer.
     *
     * @param  array{
     *     title: string,
     *     subject: string,
     *     to_user_id: int|string,
     *     status?: string|null,
     *     client_id?: int|string|null,
     *     client_phone?: string|null,
     *     client_identity?: string|null
     * }  $input
     * @return array{success: bool, http_status: int, task_id: int|string|null, raw: mixed}
     *
     * @throws MalanApiException
     */
    public function createTask(array $input): array
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

        $title = trim((string) ($input['title'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $toUserId = (int) ($input['to_user_id'] ?? 0);
        $status = trim((string) ($input['status'] ?? config('malan.tasks.default_status', 'non_urgent')));

        if ($title === '' || $subject === '' || $toUserId <= 0) {
            throw MalanApiException::invalidInput(
                'Missing required createTask fields.',
                'ما قدرت أرفع المهمة هلق. بحوّل لموظف يتابع معك.',
            );
        }

        if (! in_array($status, ['urgent', 'non_urgent'], true)) {
            $status = 'non_urgent';
        }

        $payload = [
            'title' => mb_substr($title, 0, 255),
            'subject' => mb_substr($subject, 0, 10000),
            'to_user_id' => $toUserId,
            'status' => $status,
        ];

        if (isset($input['client_id']) && $input['client_id'] !== null && $input['client_id'] !== '') {
            $payload['client_id'] = (int) $input['client_id'];
        }
        if (isset($input['client_phone']) && is_string($input['client_phone']) && trim($input['client_phone']) !== '') {
            $payload['client_phone'] = trim($input['client_phone']);
        }
        if (isset($input['client_identity']) && is_string($input['client_identity']) && trim($input['client_identity']) !== '') {
            $payload['client_identity'] = trim($input['client_identity']);
        }

        if (! isset($payload['client_id']) && ! isset($payload['client_phone']) && ! isset($payload['client_identity'])) {
            throw MalanApiException::invalidInput(
                'createTask requires a customer identifier.',
                'لازم نفحص الحساب أولًا قبل رفع المهمة.',
            );
        }

        $url = $baseUrl.'/apiClient/createTask';

        Log::info('Malan createTask started', [
            'to_user_id' => $toUserId,
            'status' => $status,
            'has_client_id' => isset($payload['client_id']),
            'has_client_phone' => isset($payload['client_phone']),
            'title_len' => mb_strlen($title),
        ]);

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $retrySleep, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Malan createTask connection/timeout failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::timeout();
        } catch (Throwable $e) {
            Log::warning('Malan createTask unexpected transport failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::serverError();
        }

        $httpStatus = $response->status();
        $json = $response->json();

        if ($httpStatus === 401) {
            $this->handleUnauthorized();
        }

        if ($httpStatus === 400) {
            throw MalanApiException::invalidInput(
                'Malan createTask rejected input.',
                'ما قدرت أرفع المهمة. تأكدنا من بيانات الحساب وبرجع بحاول أو بحوّل لموظف.',
            );
        }

        if ($httpStatus === 404) {
            throw MalanApiException::notFound();
        }

        if ($httpStatus === 409) {
            throw MalanApiException::conflict('phone');
        }

        if ($httpStatus === 405) {
            throw MalanApiException::methodNotAllowed();
        }

        if ($httpStatus === 429) {
            throw MalanApiException::rateLimited();
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::warning('Malan createTask unexpected status', [
                'http_status' => $httpStatus,
                'body' => is_array($json) ? array_intersect_key($json, array_flip(['result', 'error', 'message'])) : null,
            ]);

            throw MalanApiException::serverError($httpStatus);
        }

        $taskId = null;
        if (is_array($json)) {
            $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
            $taskId = $data['task_id'] ?? $data['id'] ?? $data['taskId'] ?? null;
        }

        Log::info('Malan createTask succeeded', [
            'http_status' => $httpStatus,
            'task_id' => $taskId,
        ]);

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'task_id' => is_scalar($taskId) ? $taskId : null,
            'raw' => $json,
        ];
    }

    /**
     * @return array{success: bool, http_status: int, sources: list<array{id: int, title: string}>, count: int, raw: mixed}
     *
     * @throws MalanApiException
     */
    public function getLeadSources(): array
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

        $url = $baseUrl.'/apiClient/getLeadSources';

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $retrySleep, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('Malan getLeadSources connection/timeout failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::timeout();
        } catch (Throwable $e) {
            Log::warning('Malan getLeadSources unexpected transport failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::serverError();
        }

        $httpStatus = $response->status();
        $json = $response->json();

        if ($httpStatus === 401) {
            $this->handleUnauthorized();
        }

        if ($httpStatus === 405) {
            throw MalanApiException::methodNotAllowed();
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::warning('Malan getLeadSources unexpected status', [
                'http_status' => $httpStatus,
            ]);

            throw MalanApiException::serverError($httpStatus);
        }

        $sources = [];
        $data = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [];
        $rawSources = is_array($data['sources'] ?? null) ? $data['sources'] : [];
        foreach ($rawSources as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if ($id > 0 && $title !== '') {
                $sources[] = ['id' => $id, 'title' => $title];
            }
        }

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'sources' => $sources,
            'count' => (int) ($data['count'] ?? count($sources)),
            'raw' => $json,
        ];
    }

    /**
     * Create a new sales lead (does not create a client record).
     *
     * @param  array{
     *     full_name: string,
     *     phone: string,
     *     leads_sources_id: int|string,
     *     identity?: string|null,
     *     email?: string|null,
     *     city_name?: string|null,
     *     additional_phone?: string|null,
     *     with_fiber?: int|bool|null
     * }  $input
     * @return array{success: bool, http_status: int, lead_id: int|string|null, raw: mixed}
     *
     * @throws MalanApiException
     */
    public function createLead(array $input): array
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

        $fullName = trim((string) ($input['full_name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? '')) ?? '';
        $sourceId = (int) ($input['leads_sources_id'] ?? 0);

        if ($fullName === '' || $phone === '' || $sourceId <= 0) {
            throw MalanApiException::invalidInput(
                'Missing required createLead fields.',
                'لازم الاسم ورقم التلفون عشان أسجّل الطلب.',
            );
        }

        if (strlen($phone) < 5 || strlen($phone) > 20) {
            throw MalanApiException::invalidInput(
                'Invalid createLead phone length.',
                'تأكدلي من رقم التلفون وابعته مرة ثانية.',
            );
        }

        $payload = [
            'full_name' => mb_substr($fullName, 0, 255),
            'phone' => $phone,
            'leads_sources_id' => $sourceId,
            'with_fiber' => ((int) ($input['with_fiber'] ?? 0)) === 1 ? 1 : 0,
        ];

        foreach (['identity', 'additional_phone'] as $digitField) {
            if (! isset($input[$digitField]) || $input[$digitField] === null || $input[$digitField] === '') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', (string) $input[$digitField]) ?? '';
            if ($digits !== '' && strlen($digits) >= 5 && strlen($digits) <= 20) {
                $payload[$digitField] = $digits;
            }
        }

        if (isset($input['email']) && is_string($input['email']) && trim($input['email']) !== '') {
            $payload['email'] = mb_substr(trim($input['email']), 0, 255);
        }

        if (isset($input['city_name']) && is_string($input['city_name']) && trim($input['city_name']) !== '') {
            $payload['city_name'] = mb_substr(trim($input['city_name']), 0, 255);
        }

        $url = $baseUrl.'/apiClient/createLead';

        Log::info('Malan createLead started', [
            'leads_sources_id' => $sourceId,
            'phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
            'has_city' => isset($payload['city_name']),
            'with_fiber' => $payload['with_fiber'],
        ]);

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $retrySleep, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Malan createLead connection/timeout failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::timeout();
        } catch (Throwable $e) {
            Log::warning('Malan createLead unexpected transport failure', [
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::serverError();
        }

        $httpStatus = $response->status();
        $json = $response->json();

        if ($httpStatus === 401) {
            $this->handleUnauthorized();
        }

        if ($httpStatus === 400) {
            throw MalanApiException::invalidInput(
                'Malan createLead rejected input.',
                'ما قدرت أسجّل الطلب. تأكدلي من الاسم ورقم التلفون.',
            );
        }

        if ($httpStatus === 404) {
            throw MalanApiException::leadSourceNotFound();
        }

        if ($httpStatus === 409) {
            throw MalanApiException::leadDuplicate();
        }

        if ($httpStatus === 405) {
            throw MalanApiException::methodNotAllowed();
        }

        if ($httpStatus === 429) {
            throw MalanApiException::rateLimited();
        }

        // Docs: 201 on success; accept any 2xx.
        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::warning('Malan createLead unexpected status', [
                'http_status' => $httpStatus,
                'body' => is_array($json) ? array_intersect_key($json, array_flip(['result', 'error', 'message'])) : null,
            ]);

            throw MalanApiException::serverError($httpStatus);
        }

        $leadId = null;
        if (is_array($json)) {
            $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
            $leadId = $data['lead_id'] ?? $data['id'] ?? null;
        }

        Log::info('Malan createLead succeeded', [
            'http_status' => $httpStatus,
            'lead_id' => $leadId,
        ]);

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'lead_id' => is_scalar($leadId) ? $leadId : null,
            'raw' => $json,
        ];
    }

    /**
     * Attach a note to an existing CRM lead (apiClient/createLeadNote).
     *
     * @return array{success: bool, http_status: int, raw: mixed}
     *
     * @throws MalanApiException
     */
    public function createLeadNote(int|string $leadId, string $note): array
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

        $leadId = is_numeric($leadId) ? (int) $leadId : trim((string) $leadId);
        $note = trim($note);

        if ($leadId === '' || $leadId === 0 || $note === '') {
            throw MalanApiException::invalidInput(
                'Missing required createLeadNote fields.',
                'ما قدرت أسجّل الملاحظة على الطلب.',
            );
        }

        $payload = [
            'lead_id' => $leadId,
            'note' => mb_substr($note, 0, 10000),
        ];

        $url = $baseUrl.'/apiClient/createLeadNote';

        Log::info('Malan createLeadNote started', [
            'lead_id' => $leadId,
            'note_chars' => mb_strlen($payload['note']),
        ]);

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, $retrySleep, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Malan createLeadNote connection/timeout failure', [
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::timeout();
        } catch (Throwable $e) {
            Log::warning('Malan createLeadNote unexpected transport failure', [
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            throw MalanApiException::serverError();
        }

        $httpStatus = $response->status();
        $json = $response->json();

        if ($httpStatus === 401) {
            $this->handleUnauthorized();
        }

        if ($httpStatus === 400) {
            throw MalanApiException::invalidInput(
                'Malan createLeadNote rejected input.',
                'ما قدرت أسجّل الملاحظة على الطلب.',
            );
        }

        if ($httpStatus === 404) {
            throw MalanApiException::notFound();
        }

        if ($httpStatus === 405) {
            throw MalanApiException::methodNotAllowed();
        }

        if ($httpStatus === 429) {
            throw MalanApiException::rateLimited();
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::warning('Malan createLeadNote unexpected status', [
                'http_status' => $httpStatus,
                'lead_id' => $leadId,
            ]);

            throw MalanApiException::serverError($httpStatus);
        }

        Log::info('Malan createLeadNote succeeded', [
            'http_status' => $httpStatus,
            'lead_id' => $leadId,
        ]);

        return [
            'success' => true,
            'http_status' => $httpStatus,
            'raw' => $json,
        ];
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
