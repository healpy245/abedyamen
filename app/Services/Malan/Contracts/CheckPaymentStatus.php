<?php

declare(strict_types=1);

namespace App\Services\Malan\Contracts;

interface CheckPaymentStatus
{
    /**
     * @return array<string, mixed>
     */
    public function check(string $paymentAttemptId): array;
}
