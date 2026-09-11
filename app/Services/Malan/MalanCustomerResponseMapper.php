<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Services\Malan\Exceptions\MalanApiException;

class MalanCustomerResponseMapper
{
    private const KNOWN_STATUSES = [
        'ACTIVE',
        'DEBT_DISCONNECTED',
        'INACTIVE',
        'SUSPENDED',
        'CANCELLED',
        'UNKNOWN',
    ];

    /**
     * @throws MalanApiException
     */
    public function mapSuccessfulPayload(mixed $payload): MalanCustomerLookupResult
    {
        $record = $this->unwrapRecord($payload);

        if ($record === null) {
            throw MalanApiException::unexpectedPayload('empty or invalid envelope');
        }

        if (($record['result'] ?? null) !== true) {
            throw MalanApiException::unexpectedPayload('result is not true');
        }

        $data = $record['data'] ?? null;
        if (! is_array($data)) {
            throw MalanApiException::unexpectedPayload('missing data');
        }

        $client = $data['client'] ?? null;
        if (! is_array($client)) {
            throw MalanApiException::unexpectedPayload('missing data.client');
        }

        $statusRaw = strtoupper(trim((string) ($client['status'] ?? 'UNKNOWN')));
        $status = in_array($statusRaw, self::KNOWN_STATUSES, true) ? $statusRaw : 'UNKNOWN';

        $phone = isset($client['client_phone']) ? (string) $client['client_phone'] : null;
        $identity = isset($client['client_identity']) ? (string) $client['client_identity'] : null;

        $financialSummary = is_array($data['financial_summary'] ?? null)
            ? $data['financial_summary']
            : [];

        [$balanceRaw, $debtAmount] = $this->resolveDebt($status, $financialSummary);

        $packageName = $this->extractPackageName($data);

        $city = isset($client['client_city'])
            ? (string) $client['client_city']
            : (isset($client['city']) ? (string) $client['city'] : null);

        return new MalanCustomerLookupResult(
            success: true,
            found: true,
            customer: [
                'id' => isset($client['id']) ? (string) $client['id'] : null,
                'name' => isset($client['client_name']) ? (string) $client['client_name'] : null,
                'phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
                'identity_masked' => str_contains((string) $identity, '*')
                    ? (string) $identity
                    : MalanSensitiveDataMasker::maskIdentity($identity),
                'status' => $status,
                'city' => $city !== '' ? $city : null,
            ],
            financial: [
                'balance_raw' => $balanceRaw,
                'debt_amount' => $debtAmount,
                'currency' => 'ILS',
            ],
            service: [
                'package_name' => $packageName,
            ],
            radius: $this->extractRadius($data),
            meta: [
                'raw_status' => $statusRaw,
                'status_known' => in_array($statusRaw, self::KNOWN_STATUSES, true),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unwrapRecord(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        // Array envelope: [ { result, data } ]
        if (array_is_list($payload)) {
            $first = $payload[0] ?? null;

            return is_array($first) ? $first : null;
        }

        // Object envelope: { result, data }
        if (array_key_exists('result', $payload) || array_key_exists('data', $payload)) {
            return $payload;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $financialSummary
     * @return array{0:?float,1:?float}
     */
    private function resolveDebt(string $status, array $financialSummary): array
    {
        if (! array_key_exists('balance', $financialSummary) || $financialSummary['balance'] === null || $financialSummary['balance'] === '') {
            return [null, null];
        }

        $balanceRaw = (float) $financialSummary['balance'];

        if ($status !== 'DEBT_DISCONNECTED') {
            return [$balanceRaw, null];
        }

        $debtAmount = abs($balanceRaw);

        if ($debtAmount <= 0.0) {
            return [$balanceRaw, null];
        }

        return [$balanceRaw, $debtAmount];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPackageName(array $data): ?string
    {
        $internetServices = $data['internet_services'] ?? null;
        if (! is_array($internetServices) || $internetServices === []) {
            $dynamic = $data['dynamic_services'] ?? null;
            if (is_array($dynamic) && $dynamic !== []) {
                $first = $dynamic[0] ?? null;
                if (is_array($first)) {
                    foreach (['package_name', 'name', 'service_name'] as $key) {
                        if (! empty($first[$key]) && is_string($first[$key])) {
                            return $first[$key];
                        }
                    }
                }
            }

            return null;
        }

        $first = $internetServices[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        foreach (['package_name', 'name', 'service_name', 'plan_name'] as $key) {
            if (! empty($first[$key]) && is_string($first[$key])) {
                return $first[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     status_raw:?string,
     *     state:?string,
     *     is_online:?bool,
     *     classification: 'connected'|'expired'|'other'|'unknown',
     *     guidance: string,
     *     request_succeeded:?bool
     * }
     */
    private function extractRadius(array $data): array
    {
        $radiusNode = null;

        $internetServices = $data['internet_services'] ?? null;
        if (is_array($internetServices)) {
            foreach ($internetServices as $service) {
                if (! is_array($service)) {
                    continue;
                }
                if (isset($service['radius_status']) && is_array($service['radius_status'])) {
                    $radiusNode = $service['radius_status'];
                    break;
                }
                if (isset($service['radius']) && is_array($service['radius'])) {
                    $radiusNode = $service['radius'];
                    break;
                }
            }
        }

        if ($radiusNode === null && isset($data['radius_status']) && is_array($data['radius_status'])) {
            $radiusNode = $data['radius_status'];
        }
        if ($radiusNode === null && isset($data['client']) && is_array($data['client'])) {
            if (isset($data['client']['radius_status']) && is_array($data['client']['radius_status'])) {
                $radiusNode = $data['client']['radius_status'];
            } elseif (isset($data['client']['radius_status']) && is_string($data['client']['radius_status'])) {
                $radiusNode = ['state' => $data['client']['radius_status']];
            }
        }

        if ($radiusNode === null) {
            return [
                'status_raw' => null,
                'state' => null,
                'is_online' => null,
                'classification' => 'unknown',
                'guidance' => 'Radius status missing. After ACTIVE + basic troubleshooting, escalate with a normal technical task.',
                'request_succeeded' => null,
            ];
        }

        $stateRaw = trim((string) ($radiusNode['state'] ?? $radiusNode['status'] ?? $radiusNode['radius_status'] ?? ''));
        $isOnline = array_key_exists('is_online', $radiusNode)
            ? filter_var($radiusNode['is_online'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $requestSucceeded = array_key_exists('request_succeeded', $radiusNode)
            ? filter_var($radiusNode['request_succeeded'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $classification = $this->classifyRadius($stateRaw, $isOnline);
        $guidance = match ($classification) {
            'connected' => 'Radius is connected (מחובר/online). Internet should work. Do NOT escalate to technical yet — continue local router checks; if still broken after checks, ask customer to recheck then escalate only if still down.',
            'expired' => 'Radius date/session expired. Creating a technical CRM task is appropriate. Draft must say radius expired and quick fix is renewing/extending the radius date/session.',
            'other' => 'Radius is not connected (e.g. offline/other). After ACTIVE + basic troubleshooting, create a normal technical CRM task.',
            default => 'Radius status unknown. After ACTIVE + basic troubleshooting, create a normal technical CRM task.',
        };

        return [
            'status_raw' => $stateRaw !== '' ? $stateRaw : null,
            'state' => $stateRaw !== '' ? $stateRaw : null,
            'is_online' => $isOnline,
            'classification' => $classification,
            'guidance' => $guidance,
            'request_succeeded' => $requestSucceeded,
        ];
    }

    private function classifyRadius(string $stateRaw, ?bool $isOnline): string
    {
        $normalized = mb_strtolower(trim($stateRaw));
        $compact = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        if ($isOnline === true
            || $normalized === 'online'
            || $normalized === 'connected'
            || str_contains($normalized, 'מחובר')
            || $compact === 'מחובר'
        ) {
            return 'connected';
        }

        if (
            str_contains($normalized, 'expired')
            || str_contains($normalized, 'expiry')
            || str_contains($normalized, 'פג')
            || str_contains($normalized, 'תוקף')
            || str_contains($normalized, 'منتهي')
            || str_contains($normalized, 'انتهى')
        ) {
            return 'expired';
        }

        if ($normalized === '' && $isOnline === null) {
            return 'unknown';
        }

        return 'other';
    }
}
