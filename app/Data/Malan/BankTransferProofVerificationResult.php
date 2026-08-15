<?php

declare(strict_types=1);

namespace App\Data\Malan;

final class BankTransferProofVerificationResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  list<string>  $suspicionReasons
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $status,
        public readonly ?float $detectedAmount = null,
        public readonly ?string $detectedDate = null,
        public readonly ?string $referenceNumber = null,
        public readonly bool $beneficiaryMatch = false,
        public readonly bool $amountMatch = false,
        public readonly bool $dateMatch = false,
        public readonly bool $referencePresent = false,
        public readonly array $suspicionReasons = [],
        public readonly ?float $confidence = null,
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'detected_amount' => $this->detectedAmount,
            'detected_date' => $this->detectedDate,
            'reference_number' => $this->referenceNumber,
            'beneficiary_match' => $this->beneficiaryMatch,
            'amount_match' => $this->amountMatch,
            'date_match' => $this->dateMatch,
            'reference_present' => $this->referencePresent,
            'suspicion_reasons' => $this->suspicionReasons,
            'confidence' => $this->confidence,
            'details' => $this->details,
        ];
    }
}
