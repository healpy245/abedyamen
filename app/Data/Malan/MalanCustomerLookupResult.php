<?php

declare(strict_types=1);

namespace App\Data\Malan;

final class MalanCustomerLookupResult
{
    /**
     * @param  array{id:?string,name:?string,phone_masked:?string,identity_masked:?string,status:?string,city:?string}|null  $customer
     * @param  array{balance_raw:?float,debt_amount:?float,currency:string}|null  $financial
     * @param  array{package_name:?string}|null  $service
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $found,
        public readonly ?array $customer = null,
        public readonly ?array $financial = null,
        public readonly ?array $service = null,
        public readonly ?string $error_code = null,
        public readonly ?string $user_message = null,
        public readonly array $meta = [],
    ) {}

    /**
     * Safe payload for the AI / tool result (no raw CRM dump).
     *
     * @return array<string, mixed>
     */
    public function toToolPayload(): array
    {
        return array_filter([
            'success' => $this->success,
            'found' => $this->found,
            'customer' => $this->customer,
            'financial' => $this->financial,
            'service' => $this->service,
            'error_code' => $this->error_code,
            'message' => $this->user_message,
            'bank_transfer' => $this->meta['bank_transfer'] ?? null,
            'needs_second_identifier' => $this->meta['needs_second_identifier'] ?? null,
            'instruction' => $this->meta['instruction'] ?? null,
            'integration_pending' => $this->meta['integration_pending'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeLogContext(): array
    {
        return [
            'success' => $this->success,
            'found' => $this->found,
            'error_code' => $this->error_code,
            'customer_id' => $this->customer['id'] ?? null,
            'status' => $this->customer['status'] ?? null,
            'debt_amount' => $this->financial['debt_amount'] ?? null,
            'phone_masked' => $this->customer['phone_masked'] ?? null,
            'identity_masked' => $this->customer['identity_masked'] ?? null,
        ];
    }
}
