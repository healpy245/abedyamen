<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

/**
 * A readable answer from Tranzila. It can be a refusal: the document API answers HTTP 200 for its errors
 * and reports them in status_code, while the payments API uses error_code.
 */
final readonly class TranzilaResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $code,
        public string $message,
        public array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromBody(array $data): self
    {
        $code = $data['status_code'] ?? $data['error_code'] ?? -1;

        return new self(
            is_numeric($code) ? (int) $code : -1,
            (string) ($data['status_msg'] ?? $data['message'] ?? ''),
            $data,
        );
    }

    public function ok(): bool
    {
        return $this->code === 0;
    }
}
