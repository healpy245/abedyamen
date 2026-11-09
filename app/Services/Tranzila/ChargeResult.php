<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

/**
 * What a credit-card transaction came back with.
 */
final readonly class ChargeResult
{
    public function __construct(
        public bool $approved,
        public int $errorCode,
        public string $message,
        public ?string $processorCode,
        public ?int $transactionId,
        public ?string $authNumber,
        public ?string $last4,
        public ?string $cardTypeName,
    ) {}

    public static function fromResponse(TranzilaResponse $response): self
    {
        $result = is_array($response->data['transaction_result'] ?? null) ? $response->data['transaction_result'] : [];
        $processorCode = isset($result['processor_response_code']) ? (string) $result['processor_response_code'] : null;
        $transactionId = $result['transaction_id'] ?? null;

        return new self(
            approved: $response->ok() && $processorCode === '000',
            errorCode: $response->code,
            message: $response->message,
            processorCode: $processorCode,
            transactionId: is_numeric($transactionId) ? (int) $transactionId : null,
            authNumber: isset($result['auth_number']) ? (string) $result['auth_number'] : null,
            last4: isset($result['last_4']) ? (string) $result['last_4'] : null,
            cardTypeName: isset($result['card_type_name']) ? (string) $result['card_type_name'] : null,
        );
    }
}
