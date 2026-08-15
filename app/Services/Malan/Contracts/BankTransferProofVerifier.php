<?php

declare(strict_types=1);

namespace App\Services\Malan\Contracts;

use App\Data\Malan\BankTransferProofVerificationResult;

interface BankTransferProofVerifier
{
    /**
     * @param  array{
     *     expected_amount: float,
     *     expected_date: string,
     *     bank_name?: string|null,
     *     bank_branch?: string|null,
     *     bank_account?: string|null,
     *     mime_type: string,
     * }  $expectations
     */
    public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult;
}
